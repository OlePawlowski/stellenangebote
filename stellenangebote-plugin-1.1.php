<?php
/**
 * Plugin Name: Oferty Pracy Opiekunek (Maidplus)
 * Description: Oferty pracy + szczegóły + formularz aplikacyjny (Maidplus API).
 * Version: 1.4
 * Author: Ole Pawlowski (extended)
 */

/* =========================================================
   Helper
   ========================================================= */

/** Bezpieczne pobieranie wartości z tablicy z kropkową ścieżką. */
function arr_get($arr, $path, $default = null) {
    $keys = explode('.', $path);
    foreach ($keys as $k) {
        if (!is_array($arr) || !array_key_exists($k, $arr)) return $default;
        $arr = $arr[$k];
    }
    return ($arr === null || $arr === '') ? $default : $arr;
}

/** Etykieta płci (PL). */
function pl_gender_label($gender) {
    $g = strtolower(trim((string)$gender));
    return match($g) {
        'male', 'm'   => 'Mężczyzna',
        'female', 'f' => 'Kobieta',
        default       => 'Nieznany'
    };
}

/** Tak/Nie po polsku. */
function yesno_pl($v, $unknown = '-') {
    if ($v === null) return $unknown;
    return $v ? 'Tak' : 'Nie';
}

/** Format daty. */
function fmt_date_pl($iso) {
    if (empty($iso)) return '-';
    $ts = strtotime($iso);
    if (!$ts) return '-';
    return date_i18n('d.m.Y', $ts);
}

/** Wiek na podstawie birthDate (YYYY-MM-DD lub ISO). */
function fmt_age($iso) {
    if (empty($iso)) return '-';
    $ts = strtotime($iso);
    if (!$ts) return '-';
    $birth = new DateTime(date('Y-m-d', $ts));
    $today = new DateTime('today');
    $age = $birth->diff($today)->y;
    return $age >= 0 ? $age.' lat' : '-';
}

/** Wynagrodzenie jako €/30 dni. */
function fmt_salary30($val) {
    if ($val === null || $val === '') return '-';
    return number_format((float)$val, 2, ',', '') . ' € / 30 dni';
}

/** Wzrost/Waga. */
function fmt_height_cm($v) { return ($v && $v > 0) ? ((int)$v).' cm' : '-'; }
function fmt_weight_kg($v) { return ($v && $v > 0) ? ((int)$v).' kg' : '-'; }

/** Normalizuje poziom języka do A1..C1 (np. "B1 (gut)" → "B1"). */
function lang_code_normalize($s) {
    if (!$s) return null;
    if (preg_match('/\b([ABC][12])\b/i', $s, $m)) {
        $code = strtoupper($m[1]);
        if (in_array($code, ['A1','A2','B1','B2','C1'], true)) return $code;
    }
    return null;
}

/** Łączy wartości niepuste, z separatorem, z opcjonalnym prefiksem. */
function join_nonempty(array $items, string $sep = ', ') {
    $out = array_values(array_filter(array_map(function($v){
        $v = is_string($v) ? trim($v) : $v;
        return ($v === null || $v === '' || $v === false) ? null : $v;
    }, $items), fn($v) => $v !== null));
    return implode($sep, $out);
}

/** Pobiera otwarte oferty. */
function maidplus_fetch_open_positions() {
    $url = 'https://integration.maidplus.de/working-position/open';
    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode('helpcare:nrJTyyouKzbdiwA'),
        ],
        'timeout' => 20,
    ]);
    if (is_wp_error($response)) return $response;
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    if (!is_array($data)) $data = [];
    return $data;
}

/** OSM iframe/link – domyślnie mocniej zbliżony (miasto). */
function osm_iframe_from_latlon($lat, $lon, $zoom = 14) {
    $delta = 0.01; // ~zoom 14 (miasto)
    $minlon = $lon - $delta; $minlat = $lat - $delta;
    $maxlon = $lon + $delta; $maxlat = $lat + $delta;
    $bbox   = rawurlencode($minlon).'%2C'.rawurlencode($minlat).'%2C'.rawurlencode($maxlon).'%2C'.rawurlencode($maxlat);
    $marker = rawurlencode($lat).'%2C'.rawurlencode($lon);
    $iframe = "https://www.openstreetmap.org/export/embed.html?bbox={$bbox}&layer=mapnik&marker={$marker}";
    $link   = "https://www.openstreetmap.org/?mlat={$lat}&mlon={$lon}#map={$zoom}/{$lat}/{$lon}";
    return [$iframe, $link];
}

/* =========================================================
   Shortcode: Lista (nowy design + filtry)
   ========================================================= */

add_shortcode('pflege_stellenangebote', 'pflegejobs_modernes_listing');

function pflegejobs_modernes_listing() {
    $jobs = maidplus_fetch_open_positions();
    if (is_wp_error($jobs)) return '<p>Błąd podczas ładowania ofert pracy.</p>';
    if (empty($jobs)) return '<p>Brak ofert pracy.</p>';

    // Stałe opcje filtrów (wg wymagań)
    $gender_opts = [
        ''       => 'Płeć (wszystko)',
        'male'   => 'Mężczyzna',
        'female' => 'Kobieta',
    ];
    $pflege_opts = ['' => 'Wszystko', '0'=>'0','1'=>'1','2'=>'2','3'=>'3','4'=>'4','5'=>'5'];
    $lang_opts   = ['' => 'Wszystko', 'A1'=>'A1 (podstawy)','A2'=>'A2 (podstawy+)','B1'=>'B1 (dobry)','B2'=>'B2 (bardzo dobry)','C1'=>'C1 (perfekcyjny)'];

    // GET
    $q              = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
    $gender         = isset($_GET['gender']) ? sanitize_text_field($_GET['gender']) : '';
    $pg_filter      = isset($_GET['pflegegrad']) ? sanitize_text_field($_GET['pflegegrad']) : '';
    $lvl_filter     = isset($_GET['level']) ? sanitize_text_field($_GET['level']) : '';
    $from           = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
    $to             = isset($_GET['to']) ? sanitize_text_field($_GET['to']) : '';
    $min_salary     = isset($_GET['min_salary']) ? floatval($_GET['min_salary']) : null;
    $two_person     = isset($_GET['two']) ? (int)$_GET['two'] : 0;
    $license_req    = isset($_GET['license']) ? (int)$_GET['license'] : 0;
    $sort           = isset($_GET['sort']) ? sanitize_text_field($_GET['sort']) : 'date_desc';
    $per_page       = isset($_GET['per_page']) ? max(6, min(48, intval($_GET['per_page']))) : 12;
    $page           = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

    // Filtry
    $filtered = array_filter($jobs, function($job) use ($q,$gender,$pg_filter,$lvl_filter,$from,$to,$min_salary,$two_person,$license_req) {
        if ($q !== '') {
            $hay = strtolower(
                (string)arr_get($job,'client.city','').' '.
                (string)arr_get($job,'client.zipCode','').' '.
                (string)arr_get($job,'jobOfferId','')
            );
            if (strpos($hay, strtolower($q)) === false) return false;
        }
        if ($gender !== '') {
            $g = strtolower(trim((string)arr_get($job,'client.gender','')));
            if ($g !== $gender) return false; // tylko male/female
        }
        if ($pg_filter !== '') {
            $pg = (string)arr_get($job,'client.pflegegrad','');
            if ($pg !== $pg_filter) return false; // 0..5
        }
        if ($lvl_filter !== '') {
            $job_code = lang_code_normalize(arr_get($job,'client.requirement.languageSkill.languageLevel',''));
            if ($job_code !== $lvl_filter) return false; // A1..C1
        }
        $s = arr_get($job,'startDate',''); $e = arr_get($job,'endDate','');
        $s_ts = $s ? strtotime($s) : null; $e_ts = $e ? strtotime($e) : null;
        if ($from !== '') { $from_ts = strtotime($from.' 00:00:00'); if ($e_ts && $e_ts < $from_ts) return false; }
        if ($to   !== '') { $to_ts   = strtotime($to.' 23:59:59');  if ($s_ts && $s_ts > $to_ts)   return false; }
        if ($min_salary !== null && $min_salary > 0) {
            if (floatval(arr_get($job,'offeredSalary',0)) < $min_salary) return false;
        }
        if ($two_person === 1 && !arr_get($job,'secondPerson',false)) return false;
        if ($license_req === 1) {
            $dl = arr_get($job,'client.requirement.drivingLicense',null);
            if (!$dl) return false;
        }
        return true;
    });

    // Sortowanie
    usort($filtered, function($a,$b) use ($sort){
        return match($sort) {
            'salary_desc' => floatval(arr_get($b,'offeredSalary',0)) <=> floatval(arr_get($a,'offeredSalary',0)),
            'salary_asc'  => floatval(arr_get($a,'offeredSalary',0)) <=> floatval(arr_get($b,'offeredSalary',0)),
            'city_asc'    => strcasecmp((string)arr_get($a,'client.city',''), (string)arr_get($b,'client.city','')),
            'date_asc'    => strtotime((string)arr_get($a,'startDate','')) <=> strtotime((string)arr_get($b,'startDate','')),
            default       => strtotime((string)arr_get($b,'startDate','')) <=> strtotime((string)arr_get($a,'startDate','')),
        };
    });

    // Paginacja
    $total = count($filtered);
    $pages = max(1, (int)ceil($total / $per_page));
    $page  = min($page, $pages);
    $offset = ($page - 1) * $per_page;
    $paged_items = array_slice($filtered, $offset, $per_page);

    ob_start();

    // Font & Icons
    echo '<link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;600&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>';

    // SVG tło
    echo '<svg class="bcg" preserveAspectRatio="xMidYMid slice" viewBox="10 10 80 80">
      <defs><style>@keyframes rotate{0%{transform:rotate(0)}100%{transform:rotate(360deg)}}
      .out-top{animation:rotate 20s linear infinite;transform-origin:13px 25px}
      .in-top{animation:rotate 10s linear infinite;transform-origin:13px 25px}
      .out-bottom{animation:rotate 25s linear infinite;transform-origin:84px 93px}
      .in-bottom{animation:rotate 15s linear infinite;transform-origin:84px 93px}</style></defs>
      <path fill="#f780600f" class="out-top" d="M37-5C25.1-14.7,5.7-19.1-9.2-10-28.5,1.8-32.7,31.1-19.8,49c15.5,21.5,52.6,22,67.2,2.3C59.4,35,53.7,8.5,37-5Z"/>
      <path fill="#f780600f" class="in-top" d="M20.6,4.1C11.6,1.5-1.9,2.5-8,11.2-16.3,23.1-8.2,45.6,7.4,50S42.1,38.9,41,24.5C40.2,14.1,29.4,6.6,20.6,4.1Z"/>
      <path fill="#f780600f" class="out-bottom" d="M105.9,48.6c-12.4-8.2-29.3-4.8-39.4,0.8-23.4,12.8-37.7,51.9-19.1,74.1s63.9,15.3,76-5.6c7.6-13.3,1.8-31.1-2.3-43.8C117.6,63.3,114.7,54.3,105.9,48.6Z"/>
      <path fill="#f780600f" class="in-bottom" d="M102,67.1c-9.6-6.1-22-3.1-29.5,2-15.4,10.7-19.6,37.5-7.6,47.8s35.9,3.9,44.5-12.5C115.5,92.6,113.9,74.6,102,67.1Z"/></svg>';

    echo '<div class="job-listing-container">';
    echo '<h2 class="job-listing-title">Aktualne oferty pracy w opiece</h2>';

    // Filtry (nowy design)
    echo '<form class="job-filters" method="get">';
      echo '<div class="filters-row">';
        echo '<div class="filter-item"><label for="q"><i class="fa-solid fa-magnifying-glass"></i> Miasto / Kod / ID</label>
              <input type="text" id="q" name="q" value="'.esc_attr($q).'" placeholder="np. Berlin, 10115, 1fcc..."></div>';

        echo '<div class="filter-item"><label for="gender"><i class="fa-solid fa-venus-mars"></i> Płeć</label><select id="gender" name="gender">';
          foreach ($gender_opts as $val=>$label) {
              echo "<option value='".esc_attr($val)."' ".selected($gender,$val,false).">".esc_html($label)."</option>";
          }
        echo '</select></div>';

        echo '<div class="filter-item"><label for="pflegegrad"><i class="fa-solid fa-wheelchair"></i> Poziom opieki</label><select id="pflegegrad" name="pflegegrad">';
          foreach ($pflege_opts as $val=>$label) {
              echo "<option value='".esc_attr($val)."' ".selected($pg_filter,$val,false).">".esc_html($label)."</option>";
          }
        echo '</select></div>';

        echo '<div class="filter-item"><label for="level"><i class="fa-solid fa-language"></i> Język (DE)</label><select id="level" name="level">';
          foreach ($lang_opts as $val=>$label) {
              echo "<option value='".esc_attr($val)."' ".selected($lvl_filter,$val,false).">".esc_html($label)."</option>";
          }
        echo '</select></div>';
      echo '</div>';

      echo '<div class="filters-row">';
        echo '<div class="filter-item"><label for="from"><i class="fa-solid fa-calendar-day"></i> Od</label><input type="date" id="from" name="from" value="'.esc_attr($from).'"></div>';
        echo '<div class="filter-item"><label for="to"><i class="fa-solid fa-calendar-check"></i> Do</label><input type="date" id="to" name="to" value="'.esc_attr($to).'"></div>';
        echo '<div class="filter-item"><label for="min_salary"><i class="fa-solid fa-euro-sign"></i> Min. wynagrodzenie (€/30)</label><input type="number" step="0.01" min="0" id="min_salary" name="min_salary" value="'.esc_attr($min_salary ?? '').'" placeholder="np. 30"></div>';
        echo '<div class="filter-item checkbox"><label class="chk"><input type="checkbox" name="two" value="1" '.checked($two_person,1,false).'> 2 osoby</label></div>';
        echo '<div class="filter-item checkbox"><label class="chk"><input type="checkbox" name="license" value="1" '.checked($license_req,1,false).'> Prawo jazdy</label></div>';
      echo '</div>';

      echo '<div class="filters-row">';
        echo '<div class="filter-item"><label for="sort"><i class="fa-solid fa-arrow-down-short-wide"></i> Sortowanie</label>
              <select id="sort" name="sort">
                <option value="date_desc" '.selected($sort,'date_desc',false).'>Najnowsze</option>
                <option value="date_asc" '.selected($sort,'date_asc',false).'>Najstarsze</option>
                <option value="salary_desc" '.selected($sort,'salary_desc',false).'>Wynagrodzenie (↓)</option>
                <option value="salary_asc" '.selected($sort,'salary_asc',false).'>Wynagrodzenie (↑)</option>
                <option value="city_asc" '.selected($sort,'city_asc',false).'>Miasto (A–Z)</option>
              </select></div>';

        echo '<div class="filter-item"><label for="per_page">Na stronie</label>
              <select id="per_page" name="per_page">';
                foreach ([12,24,36,48] as $pp) { echo "<option value='{$pp}' ".selected($per_page,$pp,false).">$pp</option>"; }
        echo    '</select></div>';

        $base_url = esc_url(remove_query_arg(array_keys($_GET)));
        echo '<div class="filter-actions"><button type="submit" class="btn-primary"><i class="fa-solid fa-filter"></i> Filtruj</button>
              <a class="btn-secondary" href="'.$base_url.'">Wyczyść</a></div>';
      echo '</div>';
    echo '</form>';

    // Info
    echo '<div class="result-info">'. sprintf('<strong>%d</strong> wyników · strona %d z %d', (int)$total, (int)$page, (int)$pages) .'</div>';

    // Karty
    echo '<div class="job-cards">';
    if (empty($paged_items)) {
        echo '<div class="noresults">Brak dopasowanych ofert. Zmień filtry.</div>';
    } else {
        foreach ($paged_items as $job) {
            $id           = esc_attr(arr_get($job, 'jobOfferId', ''));
            $city         = esc_html(arr_get($job, 'client.city', '—'));
            $gender_disp  = esc_html(pl_gender_label(arr_get($job,'client.gender','')));
            $pflegegrad   = esc_html(arr_get($job, 'client.pflegegrad', '-'));
            $lang_level   = esc_html(arr_get($job, 'client.requirement.languageSkill.languageLevel', '-'));
            $start        = fmt_date_pl(arr_get($job, 'startDate', ''));
            $end          = fmt_date_pl(arr_get($job, 'endDate', ''));
            $salary       = esc_html(fmt_salary30(arr_get($job, 'offeredSalary', null)));
            $secondPerson = (bool)arr_get($job, 'secondPerson', false);
            $dl           = arr_get($job,'client.requirement.drivingLicense',null) ? 'Tak' : 'Nie';

            echo "<div class='job-card' onclick=\"window.location.href='/oferta?jobid={$id}'\">";
              echo "<div class='job-card-section'>
                      <div><i class='fas fa-map-marker-alt'></i> <strong>Miejsce:</strong>&nbsp;{$city}</div>
                      <div><i class='fas fa-venus-mars'></i> <strong>Płeć:</strong>&nbsp;{$gender_disp}</div>
                      <div><i class='fas fa-wheelchair'></i> <strong>Poziom opieki:</strong>&nbsp;{$pflegegrad}</div>
                    </div>";
              echo "<div class='job-card-section'>
                      <div><i class='fas fa-calendar-alt'></i> <strong>Okres: </strong>&nbsp;{$start} – {$end}</div>
                      <div><i class='fas fa-language'></i> <strong>Język:</strong>&nbsp;{$lang_level}</div>
                      <div><i class='fas fa-euro-sign'></i> <strong>Wynagrodzenie:</strong>&nbsp;{$salary}</div>
                    </div>";
              echo "<div class='badge-row'>
                      ".($secondPerson ? "<span class='pill'><i class='fa-solid fa-user-group'></i> 2 osoby</span>" : "<span class='pill'><i class='fa-solid fa-user'></i> 1 osoba</span>")."
                      <span class='pill ".($dl==='Tak'?'ok':'no')."'><i class='fa-solid fa-id-card'></i> Prawo jazdy: {$dl}</span>
                    </div>";
            echo "</div>";
        }
    }
    echo '</div>';

    // Paginacja
    if ($pages > 1) {
        echo '<div class="pagination">';
        $qs = $_GET; unset($qs['page']);
        for ($p=1; $p<=$pages; $p++) {
            $qs['page'] = $p; $url = esc_url(add_query_arg($qs));
            echo "<a href='{$url}'".($p===$page?' class="active"':'').">$p</a>";
        }
        echo '</div>';
    }

    echo '</div>'; // container

    // Style (nowy design)
    echo '<style>
    body{margin:0;font-family:"Quicksand",sans-serif;background:#f6f7f8;color:#333}
    svg.bcg{position:fixed;top:0;left:0;width:100%;height:100%;z-index:0;pointer-events:none}
    .job-listing-container{position:relative;z-index:1;max-width:960px;margin:100px auto 60px;padding:40px 24px}
    .job-listing-title{text-align:center;font-size:32px;color:#f78060;margin-bottom:24px}
    .job-filters{background:rgba(255,245,242,.35);backdrop-filter:blur(6px);border:1px solid #fdded6;border-radius:16px;padding:16px;margin-bottom:16px}
    .filters-row{display:flex;flex-wrap:wrap;gap:16px}
    .filter-item{display:flex;flex-direction:column;min-width:180px;flex:1 1 200px}
    .filter-item.checkbox{justify-content:flex-end;flex:0 0 auto;min-width:auto}
    .filter-item label{font-size:12px;color:#f78060;margin-bottom:6px;display:flex;gap:6px;align-items:center}
    .filter-item input[type=text],.filter-item input[type=number],.filter-item input[type=date],.filter-item select{padding:10px 12px;border:1px solid #fdded6;border-radius:12px;background:#fff;font-size:14px}
    .filter-actions{margin-left:auto;display:flex;gap:8px;align-items:flex-end}
    .btn-primary{background:#f78060;color:#fff;border:none;border-radius:12px;padding:10px 14px;cursor:pointer;font-weight:600}
    .btn-secondary{display:inline-block;padding:10px 14px;border-radius:12px;border:1px solid #fdded6;color:#f78060;text-decoration:none;background:#fff}
    .result-info{margin:10px 4px 18px;color:#7b6d68;font-size:14px}
    .job-cards{display:flex;flex-direction:column;gap:24px}
    .job-card{display:flex;justify-content:space-between;flex-wrap:wrap;gap:24px;background:rgba(255,245,242,.35);backdrop-filter:blur(6px);padding:24px;border-radius:16px;border:1px solid #fdded6;box-shadow:0 6px 20px rgba(0,0,0,.04);transition:.3s ease;cursor:pointer}
    .job-card:hover{background:rgba(255,245,242,.5);box-shadow:0 10px 30px rgba(0,0,0,.08);transform:translateY(-4px)}
    .job-card-section{display:flex;flex-direction:column;gap:10px;flex:1 1 45%}
    .job-card-section i{margin-right:6px;color:#f78060;width:18px}
    .job-card-section div{font-size:16px;color:#333;display:flex;align-items:center}
    .badge-row{display:flex;gap:8px;width:100%}
    .pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:1px solid #fdded6;background:#fff;font-size:12px;color:#6a5a56}
    .pill.no{opacity:.8}
    .pagination{display:flex;gap:6px;justify-content:center;margin-top:20px}
    .pagination a{padding:8px 12px;border:1px solid #fdded6;border-radius:12px;text-decoration:none;color:#f78060;background:#fff}
    .pagination a.active,.pagination a:hover{background:rgba(255,245,242,.8)}
    @media(max-width:768px){.job-card{flex-direction:column}.filter-actions{width:100%;margin-top:8px}}
    </style>';

    return ob_get_clean();
}

/* =========================================================
   Shortcode: Szczegóły oferty (mapa zbliżona + 2 osoby + rozszerzone info)
   ========================================================= */

add_shortcode('pflegejob_detail', 'pokaz_szczegoly_oferty');

function pokaz_szczegoly_oferty() {
    if (!isset($_GET['jobid'])) return '<p>Brak oferty pracy.</p>';

    $jobId = sanitize_text_field($_GET['jobid']);
    $jobs  = maidplus_fetch_open_positions();
    if (is_wp_error($jobs)) return '<p>Błąd podczas ładowania oferty.</p>';

    $job = null;
    foreach ($jobs as $j) {
        if ((string)arr_get($j, 'jobOfferId', '') === (string)$jobId) { $job = $j; break; }
    }
    if (!$job) return '<p>Nie znaleziono oferty.</p>';

    // Dane główne
    $city        = esc_html(arr_get($job, 'client.city', 'Nieznane'));
    $zip         = esc_html(arr_get($job, 'client.zipCode', ''));
    $state       = esc_html(arr_get($job, 'client.state', ''));
    $lat         = (float)arr_get($job, 'client.latitude', 51.165691);
    $lon         = (float)arr_get($job, 'client.longitude', 10.451526);
    $start       = fmt_date_pl(arr_get($job, 'startDate', ''));
    $end         = fmt_date_pl(arr_get($job, 'endDate', ''));
    $salary30    = fmt_salary30(arr_get($job, 'offeredSalary', null));
    $pflegegrad  = esc_html(arr_get($job, 'client.pflegegrad', '-'));

    // Osoba 1 – profil
    $firstName1  = esc_html(arr_get($job, 'client.firstName', ''));
    $gender1     = esc_html(pl_gender_label(arr_get($job, 'client.gender', '')));
    $birth1      = arr_get($job, 'client.birthDate', null);
    $age1        = esc_html(fmt_age($birth1));
    $height1     = esc_html(fmt_height_cm(arr_get($job, 'client.height', 0)));
    $weight1     = esc_html(fmt_weight_kg(arr_get($job, 'client.weight', 0)));
    $residents   = esc_html(arr_get($job, 'client.residents', ''));

    // Wymagania/stan zdrowia – osoba 1
    $license     = yesno_pl(arr_get($job, 'client.requirement.drivingLicense', null));
    $nonSmokerReq= yesno_pl(arr_get($job, 'client.requirement.nonSmoker', null));
    $lang_level  = esc_html(arr_get($job, 'client.requirement.languageSkill.languageLevel', '-'));
    $lang_name   = esc_html(arr_get($job, 'client.requirement.languageSkill.language', 'Niemiecki'));
    $nightSorties= esc_html(arr_get($job, 'client.requirement.nightSorties', '0'));
    $petType     = esc_html(arr_get($job, 'client.requirement.petType.name', '—'));
    $petsCare    = yesno_pl(arr_get($job, 'client.requirement.petsCare', null));
    $helpDevices = arr_get($job, 'client.requirement.helpDevices', []);
    $helpDeviceNames = [];
    if (is_array($helpDevices)) {
        foreach ($helpDevices as $dev) {
            if (is_array($dev) && isset($dev['name'])) {
                $helpDeviceNames[] = trim((string)$dev['name']);
            } elseif (is_string($dev)) {
                $helpDeviceNames[] = trim($dev);
            }
        }
    }
    $helpDevicesStr = esc_html(join_nonempty($helpDeviceNames, ', '));

    // Opis/uwagi tekstowe – osoba 1
    $desc        = esc_html(arr_get($job, 'client.house.houseDescription', ''));
    $add_req     = esc_html(arr_get($job, 'client.requirement.additionalRequirement', ''));

    // Dodatkowe meta na potrzeby sekcji skrótów
    $caregiverGender = strtolower(trim((string)arr_get($job, 'client.requirement.caregiverGender', '')));
    $caregiverFor = match($caregiverGender) {
        'male', 'm' => 'opiekuna',
        'female', 'f' => 'opiekunki',
        default => 'opiekuna/opiekunki'
    };
    $clientGenderFor = esc_html(strtolower(pl_gender_label(arr_get($job, 'client.gender', ''))));
    $startRaw = arr_get($job, 'startDate', '');
    $endRaw   = arr_get($job, 'endDate', '');
    $startFor = fmt_date_pl($startRaw);
    $endFor   = $endRaw ? fmt_date_pl($endRaw) : 'Brak';
    $country  = 'Niemcy';
    $locationCompact = trim(join_nonempty([$country, $state, $city], ' / '));
    $contractType = '-'; // brak w API
    $tripPeriod = $endRaw ? ($startFor.' – '.fmt_date_pl($endRaw)) : 'dowolna';
    $mobilityNote = '';
    if ($helpDevicesStr !== '') {
        $lower = mb_strtolower($helpDevicesStr);
        if (strpos($lower, 'rollator') !== false) { $mobilityNote = 'osoba z rollatorem'; }
        elseif (strpos($lower, 'laska') !== false) { $mobilityNote = 'osoba z laską'; }
    }
    if ($mobilityNote === '' && arr_get($job,'client.requirement.mobilityHelp',null) !== null) {
        $mobilityNote = yesno_pl(arr_get($job,'client.requirement.mobilityHelp',null)) === 'Tak' ? 'wymaga pomocy w poruszaniu' : 'samodzielna mobilność';
    }

    // Stan/diagnozy (bool/teksty)
    $conditions = [];
    if (arr_get($job,'client.requirement.dementia',null) !== null)
        $conditions[] = 'Demencja: '.yesno_pl(arr_get($job,'client.requirement.dementia',null));
    $dType = arr_get($job,'client.requirement.dementiaType',null);
    if (!empty($dType)) $conditions[] = 'Typ demencji: '.esc_html($dType);
    if (arr_get($job,'client.requirement.bedridden',null) !== null)
        $conditions[] = 'Osoba leżąca: '.yesno_pl(arr_get($job,'client.requirement.bedridden',null));
    if (arr_get($job,'client.requirement.transfer',null) !== null)
        $conditions[] = 'Transfer: '.yesno_pl(arr_get($job,'client.requirement.transfer',null));
    if (arr_get($job,'client.requirement.mobilityHelp',null) !== null)
        $conditions[] = 'Pomoc w mobilności: '.yesno_pl(arr_get($job,'client.requirement.mobilityHelp',null));
    if (arr_get($job,'client.requirement.diapers',null) !== null)
        $conditions[] = 'Pieluchy: '.yesno_pl(arr_get($job,'client.requirement.diapers',null));
    if (arr_get($job,'client.requirement.helpToilet',null) !== null)
        $conditions[] = 'Pomoc w toalecie: '.yesno_pl(arr_get($job,'client.requirement.helpToilet',null));
    if (arr_get($job,'client.requirement.helpFoodIntake',null) !== null)
        $conditions[] = 'Pomoc przy jedzeniu: '.yesno_pl(arr_get($job,'client.requirement.helpFoodIntake',null));
    if (arr_get($job,'client.requirement.bodyHygiene',null) !== null)
        $conditions[] = 'Higiena ciała: '.yesno_pl(arr_get($job,'client.requirement.bodyHygiene',null));
    if (arr_get($job,'client.requirement.intimateCare',null) !== null)
        $conditions[] = 'Pielęgnacja intymna: '.yesno_pl(arr_get($job,'client.requirement.intimateCare',null));
    if (arr_get($job,'client.requirement.helpDress',null) !== null)
        $conditions[] = 'Pomoc w ubieraniu: '.yesno_pl(arr_get($job,'client.requirement.helpDress',null));
    if (arr_get($job,'client.anamnesis',null))
        $conditions[] = 'Anamneza: '.esc_html(arr_get($job,'client.anamnesis',''));

    // Dom / zakwaterowanie – rozszerzone
    $houseType   = esc_html(arr_get($job, 'client.house.houseType.name', '—'));
    $internet    = esc_html(arr_get($job, 'client.house.houseInternetConnectionType.name', '—'));
    $ownBath     = yesno_pl(arr_get($job, 'client.house.ownBathroomForCaregiver', null));
    $ownApt      = yesno_pl(arr_get($job, 'client.house.ownApartmentForCaregiver', null));
    $sqm         = arr_get($job, 'client.house.squareMetres', null);
    $sqmStr      = $sqm !== null ? esc_html((string)$sqm).' m²' : '-';
    $smokerHH    = yesno_pl(arr_get($job, 'client.house.smokerHousehold', null));
    $petsDesc    = esc_html(arr_get($job, 'client.house.petsDescription', ''));
    $housePets   = arr_get($job, 'client.house.housePets', []);
    $housePetsStr= esc_html(join_nonempty(is_array($housePets) ? $housePets : [], ', '));
    $shoppingFac = esc_html(arr_get($job, 'client.house.shoppingFacility', ''));
    $toClean     = esc_html(arr_get($job, 'client.house.toClean', ''));
    $carModel    = esc_html(arr_get($job, 'client.house.carModel', ''));
    $gearbox     = esc_html(arr_get($job, 'client.house.gearbox', ''));
    $mobOptions  = arr_get($job, 'client.house.mobilityOptions', []);
    $mobOptionsStr = esc_html(join_nonempty(is_array($mobOptions) ? $mobOptions : [], ', '));
    $surrounding = esc_html(arr_get($job, 'client.house.surroundingArea', ''));

    // Druga osoba
    $secondPerson = (bool)arr_get($job, 'secondPerson', false);
    $gender2    = esc_html(pl_gender_label(arr_get($job, 'secondClient.gender', '')));
    $pflege2    = esc_html(arr_get($job, 'secondClient.pflegegrad', '-'));
    $birth2     = arr_get($job, 'secondClient.birthDate', null);
    $age2       = esc_html(fmt_age($birth2));
    $height2    = esc_html(fmt_height_cm(arr_get($job, 'secondClient.height', 0)));
    $weight2    = esc_html(fmt_weight_kg(arr_get($job, 'secondClient.weight', 0)));

    // Mapa (bliżej miasta)
    [$iframeSrc, $mapLink] = osm_iframe_from_latlon($lat, $lon, 14);

    ob_start();
    echo '<link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;600&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>';

    ?>
    <div class="pflegejob-detail-container">
        <svg class="bcg" preserveAspectRatio="xMidYMid slice" viewBox="10 10 80 80">
            <defs>
                <style>
                    @keyframes rotate { 0% { transform: rotate(0deg);} 100% { transform: rotate(360deg);} }
                    .out-top { animation: rotate 20s linear infinite; transform-origin: 13px 25px; }
                    .in-top { animation: rotate 10s linear infinite; transform-origin: 13px 25px; }
                    .out-bottom { animation: rotate 25s linear infinite; transform-origin: 84px 93px; }
                    .in-bottom { animation: rotate 15s linear infinite; transform-origin: 84px 93px; }
                </style>
            </defs>
            <path fill="#f780600f" class="out-top" d="M37-5C25.1-14.7,5.7-19.1-9.2-10-28.5,1.8-32.7,31.1-19.8,49c15.5,21.5,52.6,22,67.2,2.3C59.4,35,53.7,8.5,37-5Z"/>
            <path fill="#f780600f" class="in-top" d="M20.6,4.1C11.6,1.5-1.9,2.5-8,11.2-16.3,23.1-8.2,45.6,7.4,50S42.1,38.9,41,24.5C40.2,14.1,29.4,6.6,20.6,4.1Z"/>
            <path fill="#f780600f" class="out-bottom" d="M105.9,48.6c-12.4-8.2-29.3-4.8-39.4,0.8-23.4,12.8-37.7,51.9-19.1,74.1s63.9,15.3,76-5.6c7.6-13.3,1.8-31.1-2.3-43.8C117.6,63.3,114.7,54.3,105.9,48.6Z"/>
            <path fill="#f780600f" class="in-bottom" d="M102,67.1c-9.6-6.1-22-3.1-29.5,2-15.4,10.7-19.6,37.5-7.6,47.8s35.9,3.9,44.5-12.5C115.5,92.6,113.9,74.6,102,67.1Z"/>
        </svg>

        <h1 class="pflegejob-title">Zlecenie opieki w <?= $city ?><?= $zip ? ' ('.$zip.')' : '' ?></h1>

        <div class="pflegejob-info-bar">
            <p><span class="pflegejob-label">Okres:</span> <?= esc_html($start) ?> – <?= esc_html($end) ?></p>
            <p><span class="pflegejob-label">Wynagrodzenie:</span> <?= $salary30 !== '-' ? esc_html($salary30) : 'do negocjacji' ?></p>
            <p><span class="pflegejob-label">Miejscowość:</span> <?= $city ?><?= $state ? ' / '.esc_html($state) : '' ?></p>
            <p><span class="pflegejob-label">Poziom opieki:</span> <?= $pflegegrad ?></p>
            <p><span class="pflegejob-label">Język:</span> <?= $lang_name ?><?= $lang_level ? ' – '.$lang_level : '' ?></p>
            <p><span class="pflegejob-label">Prawo jazdy:</span> <?= esc_html($license) ?></p>
        </div>

        <div class="spec-summary">
            <div class="spec-item"><i class="fa-solid fa-briefcase-medical"></i><span class="k">Praca dla</span><span class="v">opieka</span></div>
            <div class="spec-item"><i class="fa-solid fa-hand-holding-euro"></i><span class="k">Stawka netto/msc</span><span class="v"><?= $salary30 !== '-' ? esc_html($salary30) : '—' ?></span></div>
            <div class="spec-item"><i class="fa-solid fa-plane-departure"></i><span class="k">Data wyjazdu</span><span class="v"><?= esc_html($startFor) ?></span></div>
            <div class="spec-item"><i class="fa-solid fa-plane-arrival"></i><span class="k">Data powrotu</span><span class="v"><?= esc_html($endFor) ?></span></div>
            <div class="spec-item"><i class="fa-solid fa-location-dot"></i><span class="k">Lokalizacja</span><span class="v"><?= esc_html($locationCompact) ?></span></div>
            <div class="spec-item"><i class="fa-solid fa-file-contract"></i><span class="k">Rodzaj umowy</span><span class="v"><?= esc_html($contractType) ?></span></div>
            <div class="spec-item"><i class="fa-regular fa-clock"></i><span class="k">Okres wyjazdu</span><span class="v"><?= esc_html($tripPeriod) ?></span></div>
            <div class="spec-item"><i class="fa-solid fa-language"></i><span class="k">Znajomość języka</span><span class="v"><?= $lang_name ?><?= $lang_level ? ': '.esc_html($lang_level) : '' ?></span></div>
            <div class="spec-item"><i class="fa-solid fa-user-check"></i><span class="k">Oferta dla</span><span class="v"><?= esc_html($caregiverFor) ?></span></div>
            <div class="spec-item"><i class="fa-solid fa-person"></i><span class="k">Oferta dotyczy</span><span class="v"><?= esc_html($clientGenderFor) ?></span></div>
            <div class="spec-item"><i class="fa-solid fa-house-chimney-user"></i><span class="k">Współmieszkańcy</span><span class="v"><?= $residents !== '' ? esc_html($residents) : '—' ?></span></div>
            <div class="spec-item"><i class="fa-solid fa-person-walking-with-cane"></i><span class="k">Mobilność</span><span class="v"><?= $mobilityNote !== '' ? esc_html($mobilityNote) : '—' ?></span></div>
            <div class="spec-item"><i class="fa-solid fa-cake-candles"></i><span class="k">Wiek</span><span class="v"><?= esc_html($age1) ?></span></div>
            <div class="spec-item"><i class="fa-solid fa-weight-scale"></i><span class="k">Waga</span><span class="v"><?= esc_html($weight1) ?></span></div>
            <div class="spec-item"><i class="fa-solid fa-ruler-vertical"></i><span class="k">Wzrost</span><span class="v"><?= esc_html($height1) ?></span></div>
        </div>

        <div class="pflegejob-grid-2x2">

            <!-- OSOBA 1 -->
            <div class="pflegejob-description-box">
                <h2><i class="fas fa-user"></i> Podopieczny/a — Osoba 1 <?= $firstName1 ? ' ('.$firstName1.')' : '' ?></h2>
                <div class="info-row">
                    <p><strong>Płeć:</strong> <?= $gender1 ?></p>
                    <p><strong>Wiek:</strong> <?= $age1 ?></p>
                    <p><strong>Wzrost:</strong> <?= $height1 ?></p>
                    <p><strong>Waga:</strong> <?= $weight1 ?></p>
                    <p><strong>Pflegegrad:</strong> <?= $pflegegrad ?></p>
                    <?php if ($residents !== ''): ?>
                        <p><strong>Liczba mieszkańców w domu:</strong> <?= $residents ?></p>
                    <?php endif; ?>
                    <?php if ($petType !== '—'): ?>
                        <p><strong>Zwierzęta w domu:</strong> <?= $petType ?> (opieka: <?= $petsCare ?>)</p>
                    <?php endif; ?>
                    <?php if ($nightSorties !== '' && $nightSorties !== '0'): ?>
                        <p><strong>Nocne wyjścia:</strong> <?= $nightSorties ?>/mies.</p>
                    <?php endif; ?>
                </div>

                <?php if (!empty($conditions)): ?>
                    <div class="sublist">
                        <strong>Stan zdrowia / opieka:</strong>
                        <ul>
                        <?php foreach ($conditions as $c): ?>
                            <li><?= esc_html($c) ?></li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($helpDevicesStr !== ''): ?>
                    <p><strong>Urządzenia pomocnicze:</strong> <?= $helpDevicesStr ?></p>
                <?php endif; ?>

                <?php if ($add_req !== ''): ?>
                    <p><strong>Dodatkowe informacje:</strong> <?= $add_req ?></p>
                <?php endif; ?>
            </div>

            <!-- MAPA -->
            <div class="pflegejob-map">
                <h2><i class="fas fa-map-marked-alt"></i> Lokalizacja</h2>
                <iframe src="<?= esc_url($iframeSrc) ?>"></iframe>
                <br/>
                <small><a href="<?= esc_url($mapLink) ?>" target="_blank" rel="noopener">Zobacz większą mapę</a></small>
            </div>

            <!-- ZAKWATEROWANIE ROZSZERZONE -->
            <div class="pflegejob-description-box">
                <h2><i class="fas fa-house-user"></i> Zakwaterowanie</h2>
                <?php if ($desc !== ''): ?><p><?= $desc ?></p><?php endif; ?>
                <div class="info-grid">
                    <p><strong>Typ domu:</strong> <?= $houseType ?></p>
                    <p><strong>Internet:</strong> <?= $internet ?></p>
                    <p><strong>Łazienka dla opiekunki:</strong> <?= $ownBath ?></p>
                    <p><strong>Oddzielne mieszkanie:</strong> <?= $ownApt ?></p>
                    <p><strong>Powierzchnia pokoju/mieszkania:</strong> <?= $sqmStr ?></p>
                    <p><strong>Dom palących:</strong> <?= $smokerHH ?></p>
                    <?php if ($housePetsStr !== ''): ?>
                        <p><strong>Zwierzęta domowe:</strong> <?= $housePetsStr ?><?= $petsDesc ? ' ('.$petsDesc.')' : '' ?></p>
                    <?php endif; ?>
                    <?php if ($shoppingFac !== ''): ?>
                        <p><strong>Sklepy w pobliżu:</strong> <?= $shoppingFac ?></p>
                    <?php endif; ?>
                    <?php if ($toClean !== ''): ?>
                        <p><strong>Powierzchnia do sprzątania:</strong> <?= $toClean ?></p>
                    <?php endif; ?>
                    <?php if ($mobOptionsStr !== ''): ?>
                        <p><strong>Komunikacja / dojazd:</strong> <?= $mobOptionsStr ?></p>
                    <?php endif; ?>
                    <?php if ($carModel !== '' || $gearbox !== ''): ?>
                        <p><strong>Auto do dyspozycji:</strong> <?= trim(join_nonempty([$carModel, $gearbox], ' / ')) ?></p>
                    <?php endif; ?>
                    <?php if ($surrounding !== ''): ?>
                        <p><strong>Okolica:</strong> <?= $surrounding ?></p>
                    <?php endif; ?>
                </div>
                <?php
                    $dispo = [];
                    if ($ownApt === 'Tak') $dispo[] = 'Własny pokój/mieszkanie';
                    if ($ownBath === 'Tak') $dispo[] = 'Własna łazienka';
                    if ($internet !== '-' && $internet !== '—') $dispo[] = 'Internet';
                    if ($carModel !== '' || $gearbox !== '') $dispo[] = 'Samochód';
                    if (!empty($dispo)):
                ?>
                    <div class="sublist">
                        <strong>Do dyspozycji na zleceniu:</strong>
                        <ul>
                            <?php foreach ($dispo as $d): ?>
                                <li><?= esc_html($d) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>

            <!-- OCZEKIWANIA SZEROKIE -->
            <div class="pflegejob-description-box">
                <h2><i class="fas fa-user-check"></i> Oczekiwania</h2>
                <div class="info-grid">
                    <p><strong>Prawo jazdy:</strong> <?= esc_html($license) ?></p>
                    <p><strong>Niepaląca opiekunka:</strong> <?= esc_html($nonSmokerReq) ?></p>
                    <p><strong>Język:</strong> <?= $lang_name ?><?= $lang_level ? ' – '.$lang_level : '' ?></p>
                    <?php if ($petsCare !== '-'): ?>
                        <p><strong>Opieka nad zwierzętami:</strong> <?= $petsCare ?></p>
                    <?php endif; ?>
                    <p><strong>Doświadczenie:</strong> —</p>
                    <p><strong>Referencje:</strong> —</p>
                </div>
            </div>

            <?php
                $careTasks = [];
                if (arr_get($job,'client.requirement.helpDress',null)) $careTasks[] = 'pomoc w ubraniu';
                if (arr_get($job,'client.requirement.bodyHygiene',null)) $careTasks[] = 'pomoc w higienie';
                if (arr_get($job,'client.requirement.intimateCare',null)) $careTasks[] = 'pielęgnacja intymna';
                if (arr_get($job,'client.requirement.helpFoodIntake',null)) $careTasks[] = 'pomoc w jedzeniu';
                if (arr_get($job,'client.requirement.helpToilet',null)) $careTasks[] = 'pomoc w toalecie';
                if (arr_get($job,'client.requirement.commonActivity',null)) $careTasks[] = 'organizacja dnia / wspólne aktywności';

                $homeTasks = [];
                if (arr_get($job,'client.requirement.prepareFood',null)) $homeTasks[] = 'gotowanie';
                if (arr_get($job,'client.requirement.cleaningRooms',null)) $homeTasks[] = 'sprzątanie';
                if (arr_get($job,'client.requirement.ironingLaundry',null)) $homeTasks[] = 'prasowanie';
                if (arr_get($job,'client.requirement.foodShopping',null)) $homeTasks[] = 'zakupy';

                $otherTasks = [];
                if ($dl === 'Tak') $otherTasks[] = 'jazda samochodem';
                if (arr_get($job,'client.requirement.petsCare',null)) $otherTasks[] = 'opieka nad zwierzętami';
                if (arr_get($job,'client.requirement.arrangeMedicalAppointments',null)) $otherTasks[] = 'organizacja wizyt lekarskich';
                if (arr_get($job,'client.requirement.planLeisureTimeOutside',null)) $otherTasks[] = 'spacery / wyjścia';
            ?>
            <div class="pflegejob-description-box">
                <h2><i class="fa-solid fa-list-check"></i> Zakres obowiązków</h2>
                <?php if (!empty($careTasks)): ?>
                    <div class="sublist">
                        <strong>Czynności opiekuńcze:</strong>
                        <ul>
                            <?php foreach ($careTasks as $t): ?>
                                <li><?= esc_html($t) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if (!empty($homeTasks)): ?>
                    <div class="sublist">
                        <strong>Obowiązki domowe:</strong>
                        <ul>
                            <?php foreach ($homeTasks as $t): ?>
                                <li><?= esc_html($t) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if (!empty($otherTasks)): ?>
                    <div class="sublist">
                        <strong>Pozostałe zadania:</strong>
                        <ul>
                            <?php foreach ($otherTasks as $t): ?>
                                <li><?= esc_html($t) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if ($helpDevicesStr !== ''): ?>
                    <div class="sublist">
                        <strong>Pomoce medyczne na zleceniu:</strong>
                        <ul>
                            <?php foreach (explode(', ', $helpDevicesStr) as $hd): ?>
                                <li><?= esc_html($hd) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($secondPerson): ?>
            <div class="pflegejob-description-box" style="margin-top:16px;">
                <h2><i class="fas fa-user-friends"></i> Podopieczny/a — Osoba 2</h2>
                <div class="info-row">
                    <p><strong>Płeć:</strong> <?= $gender2 ?></p>
                    <p><strong>Wiek:</strong> <?= $age2 ?></p>
                    <p><strong>Wzrost:</strong> <?= $height2 ?></p>
                    <p><strong>Waga:</strong> <?= $weight2 ?></p>
                    <p><strong>Pflegegrad:</strong> <?= $pflege2 ?></p>
                </div>
            </div>
        <?php endif; ?>

        <div class="pflegejob-btn-wrapper">
            <a href="/formularz-aplikacyjny?jobid=<?= esc_attr($jobId) ?>" class="pflegejob-btn">Aplikuj teraz</a>
        </div>
    </div>

    <style>
        body { margin:0; font-family: 'Quicksand', sans-serif; background:#f6f7f8; color:#333; }
        .pflegejob-detail-container { position:relative; background:#fff; overflow:hidden; padding:28px; max-width:1000px; margin:40px auto; border-radius:16px; box-shadow:0 6px 18px rgba(0,0,0,.05); }
        .pflegejob-detail-container svg.bcg { position:absolute; top:0; left:0; width:100%; height:100%; z-index:0; pointer-events:none; }
        .pflegejob-detail-container > *:not(svg) { position:relative; z-index:1; }
        .pflegejob-title { font-size:32px; color:#f78060; text-align:center; margin-bottom:30px; }
        .pflegejob-info-bar { display:flex; flex-wrap:wrap; justify-content:space-between; gap:16px; padding:16px 24px; background:rgba(255,245,242,.8); border:1px solid #fdded6; border-radius:12px; margin-bottom:32px; font-size:16px; }
        .pflegejob-info-bar p { margin:0; flex:1 1 30%; line-height:1.6; }
        .pflegejob-label { color:#f78060; font-weight:600; }
        .spec-summary { display:grid; grid-template-columns: repeat(3, 1fr); gap:12px 16px; background:#fff; border:1px solid #fdded6; border-radius:12px; padding:14px 18px; margin:-12px 0 26px; }
        .spec-item { display:flex; align-items:center; gap:10px; font-size:14px; color:#50433f; }
        .spec-item i { color:#f78060; width:18px; text-align:center; }
        .spec-item .k { color:#7b6d68; min-width:160px; font-weight:600; }
        .spec-item .v { color:#2e2a28; }
        .pflegejob-grid-2x2 { display:grid; grid-template-columns: 1fr 1fr; gap:32px; margin-bottom:30px; }
        .pflegejob-description-box, .pflegejob-map { background:transparent; border:1px solid #fdded6; border-radius:16px; padding:24px; box-shadow:0 2px 4px rgba(0,0,0,.03); }
        .pflegejob-description-box h2, .pflegejob-map h2 { color:#f78060; font-size:22px; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
        .info-row { display:flex; gap:24px; flex-wrap:wrap; margin-bottom:12px; }
        .sublist ul { margin:8px 0 0 18px; }
        .info-grid { display:grid; grid-template-columns: 1fr 1fr; gap:8px 24px; }
        .pflegejob-map iframe { width:100%; height:300px; border:none; border-radius:12px; }
        .pflegejob-btn-wrapper { text-align:center; margin-top:30px; }
        .pflegejob-btn { background:#f78060; padding:14px 28px; color:#fff; border-radius:30px; text-decoration:none; font-weight:bold; box-shadow:0 4px 14px rgba(0,0,0,.08); transition: background .3s ease; }
        .pflegejob-btn:hover { background:#e76948; }
        @media (max-width: 768px) {
            .spec-summary { grid-template-columns: 1fr; }
            .pflegejob-grid-2x2 { grid-template-columns:1fr; }
            .pflegejob-info-bar { flex-direction:column; }
            .info-grid { grid-template-columns: 1fr; }
        }
    </style>
    <?php
    return ob_get_clean();
}

/* =========================================================
   Shortcode: Formularz aplikacyjny (wysyłka maila)
   ========================================================= */

add_shortcode('pflege_bewerbung', 'pflege_bewerbung_form');

function pflege_bewerbung_form() {
    $jobId = isset($_GET['jobid']) ? sanitize_text_field($_GET['jobid']) : '';

    $errors = [];
    $ok_msg = '';
    $values = [
        'imie'     => '',
        'nazwisko' => '',
        'telefon'  => '',
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pflege_apply_submit'])) {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'pflege_bewerbung')) {
            $errors[] = 'Nieprawidłowy token bezpieczeństwa. Odśwież stronę.';
        } else {
            $values['imie']     = sanitize_text_field($_POST['imie'] ?? '');
            $values['nazwisko'] = sanitize_text_field($_POST['nazwisko'] ?? '');
            $values['telefon']  = preg_replace('/[^0-9+\s()-]/', '', $_POST['telefon'] ?? '');
            $agree              = isset($_POST['agree']) ? (int)$_POST['agree'] : 0;
            $jobId              = sanitize_text_field($_POST['jobid'] ?? $jobId);

            if ($values['imie'] === '')     $errors[] = 'Imię jest wymagane.';
            if ($values['nazwisko'] === '') $errors[] = 'Nazwisko jest wymagane.';
            if ($values['telefon'] === '')  $errors[] = 'Numer telefonu jest wymagany.';
            if ($agree !== 1)               $errors[] = 'Musisz zaakceptować Politykę prywatności.';

            if (empty($errors)) {
                // Pobierz miasto/okres dla maila (opcjonalne)
                $jobCity=''; $period='';
                $jobs = maidplus_fetch_open_positions();
                if (!is_wp_error($jobs)) {
                    foreach ($jobs as $j) {
                        if ((string)arr_get($j,'jobOfferId','') === (string)$jobId) {
                            $jobCity = arr_get($j,'client.city','');
                            $period  = fmt_date_pl(arr_get($j,'startDate','')).' – '.fmt_date_pl(arr_get($j,'endDate',''));
                            break;
                        }
                    }
                }

                $to      = 'rekrutacja@helpcare.pl';
                $subject = sprintf('Nowa aplikacja – JobID %s – %s %s', $jobId ?: '-', $values['imie'], $values['nazwisko']);
                $body    = '<h2>Nowa aplikacja</h2>'.
                           '<p><strong>Imię:</strong> '.esc_html($values['imie']).'<br>'.
                           '<strong>Nazwisko:</strong> '.esc_html($values['nazwisko']).'<br>'.
                           '<strong>Telefon:</strong> '.esc_html($values['telefon']).'<br>'.
                           '<strong>JobID:</strong> '.esc_html($jobId).'<br>'.
                           '<strong>Miejscowość:</strong> '.esc_html($jobCity).'<br>'.
                           '<strong>Okres:</strong> '.esc_html($period).'</p>'.
                           '<p>Wiadomość wygenerowana z formularza na stronie.</p>';

                $headers = ['Content-Type: text/html; charset=UTF-8'];

                if (wp_mail($to, $subject, $body, $headers)) {
                    $ok_msg = 'Dziękujemy! Zgłoszenie zostało wysłane.';
                    $values = ['imie'=>'','nazwisko'=>'','telefon'=>''];
                } else {
                    $errors[] = 'Nie udało się wysłać wiadomości. Spróbuj ponownie.';
                }
            }
        }
    }

    ob_start();
    echo '<link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;600&display=swap" rel="stylesheet">';
    echo '<style>
    .apply-wrap{display:flex;justify-content:center;padding:40px 16px}
    .apply-card{max-width:640px;width:100%;background:rgba(255,245,242,.7);backdrop-filter:blur(8px);border:1px solid #fdded6;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.08);padding:28px}
    .apply-title{font-family:Quicksand,sans-serif;color:#f78060;font-size:28px;font-weight:600;margin:0 0 16px;display:flex;gap:10px;align-items:center}
    .apply-row{margin-bottom:14px}
    .apply-label{display:block;font-size:14px;color:#7b6d68;margin:0 0 6px}
    .apply-input{width:100%;border:1px solid #fdded6;border-radius:12px;padding:12px 14px;font-size:16px;background:#fff;outline-color:#2b69ff}
    .apply-check{display:flex;gap:8px;align-items:flex-start;margin-top:6px}
    .apply-actions{margin-top:18px}
    .apply-btn{display:inline-block;width:100%;background:#f78060;color:#fff;border:none;border-radius:999px;padding:14px 20px;font-size:18px;font-weight:700;cursor:pointer}
    .apply-msg{margin-bottom:12px}
    .apply-msg.ok{color:#1e8174}
    .apply-msg.err{color:#b00020}
    </style>';

    echo '<div class="apply-wrap"><div class="apply-card">';
    echo '<h2 class="apply-title"><span style="transform:rotate(-12deg)">📨</span> Formularz aplikacyjny</h2>';

    if (!empty($ok_msg)) {
        echo '<div class="apply-msg ok">'.esc_html($ok_msg).'</div>';
    }
    if (!empty($errors)) {
        echo '<div class="apply-msg err"><ul style="margin:0;padding-left:18px">';
        foreach ($errors as $e) echo '<li>'.esc_html($e).'</li>';
        echo '</ul></div>';
    }

    echo '<form method="post">';
    wp_nonce_field('pflege_bewerbung');
    echo '<input type="hidden" name="jobid" value="'.esc_attr($jobId).'">';

    echo '<div class="apply-row"><label class="apply-label" for="imie">Imię <span style="color:#b00020">*</span></label>
          <input class="apply-input" id="imie" name="imie" type="text" value="'.esc_attr($values['imie']).'" required></div>';

    echo '<div class="apply-row"><label class="apply-label" for="nazw">Nazwisko <span style="color:#b00020">*</span></label>
          <input class="apply-input" id="nazw" name="nazwisko" type="text" value="'.esc_attr($values['nazwisko']).'" required></div>';

    echo '<div class="apply-row"><label class="apply-label" for="tel">Numer telefonu <span style="color:#b00020">*</span></label>
          <input class="apply-input" id="tel" name="telefon" type="tel" inputmode="tel" value="'.esc_attr($values['telefon']).'" required></div>';

    echo '<div class="apply-row apply-check">
            <input id="agree" name="agree" type="checkbox" value="1" required>
            <label for="agree">Zapoznałem(-am) się z <a href="/polityka-prywatnosci" target="_blank" rel="noopener">Polityką prywatności</a> i ją akceptuję.</label>
          </div>';

    echo '<div class="apply-actions"><button type="submit" name="pflege_apply_submit" class="apply-btn">Wyślij</button></div>';
    echo '</form>';

    echo '</div></div>';

    return ob_get_clean();
}
