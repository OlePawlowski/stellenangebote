<?php
/**
 * Plugin Name: Oferty Pracy Opiekunek (Maidplus)
 * Description: Oferty pracy + szczegóły + formularz aplikacyjny (Maidplus API).
 * Version: 1.2
 * Author: Ole Pawlowski (extended)
 */

/* =========================================================
   Helper
   ========================================================= */

/** Wiek z ISO (obsługa formatu z T i milisekundami, np. 2025-09-08T00:00:00.000+00:00). */
function fmt_age($iso) {
    if (empty($iso)) return '-';
    $datePart = null;
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', (string)$iso, $m)) {
        $datePart = $m[1];
    }
    if ($datePart) {
        $birth = DateTime::createFromFormat('Y-m-d', $datePart);
    } else {
        $birth = date_create((string)$iso);
    }
    if (!$birth) return '-';
    $today = new DateTime('today');
    $age = $birth->diff($today)->y;
    return $age.' lat';
}


/** Join nur nicht-leere Strings. */
function join_nonempty(array $items, $sep = ', ') {
    $items = array_values(array_filter(array_map('trim', $items), fn($v) => $v !== '' && $v !== null));
    return implode($sep, $items);
}

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

/** Wynagrodzenie jako €/30 dni. */
function fmt_salary30($val) {
    if ($val === null || $val === '') return '-';
    return number_format((float)$val, 2, ',', '') . ' € / msc';
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

/** Pobiera wartość wynagrodzenia (tylko offeredSalary – stawka miesięczna) jako float. */
function job_salary_value($job) {
    $val = arr_get($job, 'offeredSalary', null);
    return $val !== null && $val !== '' ? floatval($val) : 0.0;
}

/** Prosta walidacja URL bez rozszerzenia filter (fallback). */
function looks_like_url($s) {
    if (!is_string($s) || $s === '') return false;
    return (bool) preg_match('/^https?:\/\//i', $s);
}

/** Pobiera otwarte oferty dla wskazanego użytkownika (pojedyncza organizacja). */
function maidplus_fetch_open_positions_single($username, $password) {
    $url = 'https://integration.maidplus.de/working-position/open';
    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
        ],
        'timeout' => 20,
    ]);
    if (is_wp_error($response)) return $response;
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    return is_array($data) ? $data : [];
}

/** Pobiera i scala otwarte oferty z PL oraz DE organizacji. */
function maidplus_fetch_open_positions_all() {
    // PL organizacja (istniejące uprawnienia)
    $pl = maidplus_fetch_open_positions_single('helpcarepl', 'hallo');
    // DE organizacja (zgodnie z prośbą użytkownika)
    $de = maidplus_fetch_open_positions_single('helpcare', 'nrJTyyouKzbdiwA');

    // Jeżeli któryś zwrócił błąd, ignoruj błąd i korzystaj z drugiego
    $pl_list = is_wp_error($pl) ? [] : $pl;
    $de_list = is_wp_error($de) ? [] : $de;

    // Scal listy i usuń duplikaty po jobOfferId
    $byId = [];
    foreach ([$pl_list, $de_list] as $list) {
        foreach ($list as $job) {
            $id = (string)arr_get($job, 'jobOfferId', '');
            if ($id !== '') {
                $byId[$id] = $job; // ostatni wygrywa
            } else {
                // brak ID – dodaj jako unikalny wpis
                $byId[spl_object_hash((object)$job)] = $job;
            }
        }
    }
    return array_values($byId);
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
    $jobs = maidplus_fetch_open_positions_all();
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
            if (job_salary_value($job) < $min_salary) return false;
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
            'salary_desc' => job_salary_value($b) <=> job_salary_value($a),
            'salary_asc'  => job_salary_value($a) <=> job_salary_value($b),
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
    echo '<h2 class="job-listing-title" style="color: #5b5b5b;">Aktualne oferty pracy w opiece</h2>';

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
        echo '<div class="filter-item"><label for="min_salary"><i class="fa-solid fa-euro-sign"></i> Min. wynagrodzenie (€/msc)</label><input type="number" step="0.01" min="0" id="min_salary" name="min_salary" value="'.esc_attr($min_salary ?? '').'" placeholder="np. 2200"></div>';
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
            $start        = fmt_date_pl(arr_get($job, 'startDate', ''));
            $end          = fmt_date_pl(arr_get($job, 'endDate', ''));
            $salary       = esc_html(fmt_salary30(job_salary_value($job)));
            $secondPerson = (bool)arr_get($job, 'secondPerson', false);
            $dl           = arr_get($job,'client.requirement.drivingLicense',null) ? 'Tak' : 'Nie';
            $lang_level  = (string)arr_get($job, 'client.requirement.languageSkill.languageLevel', '-');
            $lang_name   = (string)arr_get($job, 'client.requirement.languageSkill.language', 'German');
            // Translacje stałych wartości -> PL
            $trLang = [
              'German' => 'Niemiecki', 'English' => 'Angielski'
            ];
            $trLangLevel = [
              'A0 (keine)' => 'A0 (brak)', 'A1 (Grund)' => 'A1 (podstawy)', 'A2 (mittel)' => 'A2 (średni)',
              'B1 (gut)' => 'B1 (dobry)', 'B2 (sehr gut)' => 'B2 (bardzo dobry)', 'C1 (perfekt)' => 'C1 (perfekcyjny)', 'C2' => 'C2'
            ];
            $lang_name = esc_html($trLang[$lang_name] ?? $lang_name);
            $lang_level = esc_html($trLangLevel[$lang_level] ?? $lang_level);

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
    .job-listing-container{position:relative;z-index:1;max-width:960px;margin:40px auto 24px;padding:12px}
    .job-listing-title{text-align:center;font-size:32px;color:#f78060;margin-bottom:24px}
    .job-filters{background:rgba(255,245,242,.35);backdrop-filter:blur(6px);border:1px solid #fdded6;border-radius:12px;padding:12px;margin-bottom:12px}
    .filters-row{display:flex;flex-wrap:wrap;gap:10px}
    .filter-item{display:flex;flex-direction:column;min-width:140px;flex:1 1 160px}
    .filter-item.checkbox{justify-content:flex-end;flex:0 0 auto;min-width:auto}
    .filter-item label{font-size:12px;color:#f78060;margin-bottom:6px;display:flex;gap:6px;align-items:center}
    .filter-item input[type=text],.filter-item input[type=number],.filter-item input[type=date],.filter-item select{padding:8px 10px;border:1px solid #fdded6;border-radius:10px;background:#fff;font-size:14px}
    .filter-actions{margin-left:auto;display:flex;gap:8px;align-items:flex-end}
    .btn-primary{background:#f78060;color:#fff;border:none;border-radius:10px;padding:9px 12px;cursor:pointer;font-weight:600;font-size:14px}
    .btn-secondary{display:inline-block;padding:9px 12px;border-radius:10px;border:1px solid #fdded6;color:#f78060;text-decoration:none;background:#fff;font-size:14px}
    .result-info{margin:10px 4px 18px;color:#7b6d68;font-size:14px}
    .job-cards{display:flex;flex-direction:column;gap:12px}
    .job-card{display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px;background:rgba(255,245,242,.35);backdrop-filter:blur(4px);padding:14px;border-radius:12px;border:1px solid #fdded6;box-shadow:0 4px 12px rgba(0,0,0,.04);transition:.2s ease;cursor:pointer}
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
    @media(max-width:768px){
      .job-card{flex-direction:column}
      .filters-row{gap:8px}
      /* mobile: show only key filters in first row */
      .filter-item:nth-child(n+5){ display:none; }
      .filter-item{flex:1 1 100%;min-width:100%}
      .filter-actions{width:100%;margin-top:8px;justify-content:stretch}
      .filter-actions .btn-primary, .filter-actions .btn-secondary{flex:1}
    }
    @media(max-width:480px){
      .job-listing-title{font-size:22px}
      .btn-primary,.btn-secondary{padding:7px 9px;font-size:12px;border-radius:8px}
      .job-card{padding:12px;border-radius:10px}
      .job-card-section div{font-size:13px}
      .pill{padding:4px 7px;font-size:10px}
    }
    </style>';

    return ob_get_clean();
}

/* =========================================================
   Shortcode: Szczegóły oferty (mapa zbliżona + 2 osoby)
   ========================================================= */

add_shortcode('pflegejob_detail', 'pokaz_szczegoly_oferty');

function pokaz_szczegoly_oferty() {
    if (!isset($_GET['jobid'])) return '<p>Brak oferty pracy.</p>';

    $jobId = sanitize_text_field($_GET['jobid']);
    $jobs  = maidplus_fetch_open_positions_all();
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
    $salary30    = fmt_salary30(job_salary_value($job));
    $salary30    = fmt_salary30(job_salary_value($job));
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
    $caregiverGenderRaw = (string)arr_get($job,'client.requirement.caregiverGender','');
    $trCaregiverGender = [
        'No preference' => 'bez preferencji',
        'Female' => 'opiekunki',
        'Male' => 'opiekuna'
    ];
    $caregiverGender = $trCaregiverGender[$caregiverGenderRaw] ?? ($caregiverGenderRaw !== '' ? strtolower($caregiverGenderRaw) : 'bez preferencji');
    $nonSmokerReq= yesno_pl(arr_get($job, 'client.requirement.nonSmoker', null));
    $lang_level  = (string)arr_get($job, 'client.requirement.languageSkill.languageLevel', '-');
    $lang_name   = (string)arr_get($job, 'client.requirement.languageSkill.language', 'German');
    // Translacje stałych wartości -> PL
    $trLang = [
      'German' => 'Niemiecki', 'English' => 'Angielski'
    ];
    $trLangLevel = [
      'A0 (keine)' => 'A0 (brak)', 'A1 (Grund)' => 'A1 (podstawy)', 'A2 (mittel)' => 'A2 (średni)',
      'B1 (gut)' => 'B1 (dobry)', 'B2 (sehr gut)' => 'B2 (bardzo dobry)', 'C1 (perfekt)' => 'C1 (perfekcyjny)', 'C2' => 'C2'
    ];
    $lang_name = esc_html($trLang[$lang_name] ?? $lang_name);
    $lang_level = esc_html($trLangLevel[$lang_level] ?? $lang_level);
    $nightRaw    = (string)arr_get($job, 'client.requirement.nightSorties', '0');
    $trNight = [
      '1 x Night' => '1x w nocy',
      'Several times a night' => 'kilka razy w nocy',
      'gelegentlich' => 'okazjonalnie',
      'gt2' => 'kilka razy w nocy'
    ];
    $nightSorties= esc_html($trNight[$nightRaw] ?? $nightRaw);
    $petRaw      = (string)arr_get($job, 'client.requirement.petType.name', '—');
    $trPets = ['Big Dog'=>'Duży pies','Small Dog'=>'Mały pies','Cat'=>'Kot','Bird'=>'Ptak','Mouse'=>'Mysz','Snake'=>'Wąż'];
    $petType     = esc_html($trPets[$petRaw] ?? $petRaw);
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
    // Translacja urządzeń pomocniczych (DE/EN -> PL)
    $trHelpDevice = [
        // EN values
        'Anti-decubitus mattress' => 'Materac przeciwodleżynowy',
        'Care Bed' => 'Łóżko pielęgnacyjne',
        'Lifter Bath' => 'Podnośnik kąpielowy',
        'Bath Lifter' => 'Podnośnik kąpielowy',
        'Lifter Bed' => 'Podnośnik do łóżka',
        'Rollator' => 'Rollator',
        'Stair Lifter' => 'Winda schodowa',
        'Walking Stick' => 'Laska',
        'Wheelchair' => 'Wózek inwalidzki',
        // DE fallbacks (in case API returns DE)
        'Anti-Dekubitus-Matratze' => 'Materac przeciwodleżynowy',
        'Pflegebett' => 'Łóżko pielęgnacyjne',
        'Badlifter' => 'Podnośnik kąpielowy',
        'Bett Lift' => 'Podnośnik do łóżka',
        'Treppen Lift' => 'Winda schodowa',
        'Gehstock' => 'Laska',
        'Rollstuhl' => 'Wózek inwalidzki',
    ];
    $helpDeviceNames = array_map(function($name) use ($trHelpDevice) {
        return $trHelpDevice[$name] ?? $name;
    }, $helpDeviceNames);
    $helpDevicesStr = esc_html(join_nonempty($helpDeviceNames, ', '));

    // Opis/uwagi tekstowe – osoba 1
    $desc        = esc_html(arr_get($job, 'client.house.houseDescription', ''));
    $add_req     = esc_html(arr_get($job, 'client.requirement.additionalRequirement', ''));

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
    $houseTypeRaw= (string)arr_get($job, 'client.house.houseType.name', '—');
    $trHouse = ['Apartment with elevator'=>'Mieszkanie z windą','Apartment'=>'Mieszkanie','Apartment House'=>'Dom wielorodzinny','Detached House'=>'Dom jednorodzinny'];
    $houseType   = esc_html($trHouse[$houseTypeRaw] ?? $houseTypeRaw);
    $internetRaw = (string)arr_get($job, 'client.house.houseInternetConnectionType.name', '—');
    $trInternet = ['Kabel'=>'Kabel','Not Available'=>'Brak','Ordered'=>'Zamówiony','Surfstick'=>'Modem USB','Wifi'=>'Wi‑Fi'];
    $internet    = esc_html($trInternet[$internetRaw] ?? $internetRaw);
    $ownBath     = yesno_pl(arr_get($job, 'client.house.ownBathroomForCaregiver', null));
    $ownApt      = yesno_pl(arr_get($job, 'client.house.ownApartmentForCaregiver', null));
    $sqm         = arr_get($job, 'client.house.squareMetres', null);
    $sqmStr      = $sqm !== null ? esc_html((string)$sqm).' m²' : '-';
    $smokerHH    = yesno_pl(arr_get($job, 'client.house.smokerHousehold', null));
    $petsDesc    = esc_html(arr_get($job, 'client.house.petsDescription', ''));
    $housePets   = arr_get($job, 'client.house.housePets', []);
    $housePetNames = [];
    if (is_array($housePets)) {
        foreach ($housePets as $pet) {
            if (is_array($pet) && isset($pet['name'])) {
                $housePetNames[] = trim((string)$pet['name']);
            } elseif (is_string($pet)) {
                $housePetNames[] = trim($pet);
            }
        }
    }
    $housePetsStr= esc_html(join_nonempty($housePetNames, ', '));
    // Sklepy w pobliżu – może przyjść jako tablica
    $shoppingRaw = arr_get($job, 'client.house.shoppingFacility', '');
    if (is_array($shoppingRaw)) {
        $shoppingFac = esc_html(join_nonempty(array_map(function($v){
            if (is_array($v) && isset($v['name'])) return trim((string)$v['name']);
            return is_string($v) ? trim($v) : '';
        }, $shoppingRaw), ', '));
    } else {
        $shoppingFac = esc_html((string)$shoppingRaw);
    }
    $toClean     = esc_html((string)arr_get($job, 'client.house.toClean', ''));
    $carModel    = esc_html(arr_get($job, 'client.house.carModel', ''));
    $gearbox     = esc_html(arr_get($job, 'client.house.gearbox', ''));
    $mobOptions  = arr_get($job, 'client.house.mobilityOptions', []);
    $mobOptionNames = [];
    if (is_array($mobOptions)) {
        foreach ($mobOptions as $mo) {
            if (is_array($mo) && isset($mo['name'])) {
                $mobOptionNames[] = trim((string)$mo['name']);
            } elseif (is_string($mo)) {
                $mobOptionNames[] = trim($mo);
            }
        }
    }
    $trMob = ['Access to public transport'=>'Dostęp do komunikacji miejskiej','Bike'=>'Rower','Car'=>'Samochód','E-Bike'=>'E‑rower'];
    $mobOptionsStr = esc_html(join_nonempty(array_map(function($v) use ($trMob){return $trMob[$v] ?? $v;}, $mobOptionNames), ', '));
    // Okolica – zabezpieczenie przed wyświetleniem "Array" + translacja Rural/Urban
    $surroundingRaw = arr_get($job, 'client.house.surroundingArea', '');
    if (is_array($surroundingRaw)) {
        $surroundingStr = join_nonempty(array_map(function($v){
            if (is_array($v) && isset($v['name'])) return trim((string)$v['name']);
            return is_string($v) ? trim($v) : '';
        }, $surroundingRaw), ', ');
    } else {
        $surroundingStr = (string)$surroundingRaw;
    }
    $trSurrounding = ['Rural' => 'Wiejski', 'Urban' => 'Miejski'];
    $surrounding = esc_html($trSurrounding[$surroundingStr] ?? $surroundingStr);

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
    ?>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

    <style>
      :root{
        --primary:#f78060; --text:#313131; --text-muted:#5b5b5b;
        --border:#eee1de; --radius:18px;
        --shadow:0 8px 24px rgba(0,0,0,.08);
      }
      html,body{ scroll-behavior:smooth; }
      .pflegejob-wrap{ max-width:1100px; margin:40px auto; padding:24px; position:relative; }
      .pflegejob-card{ background:#fff; border:1px solid var(--border); border-radius:28px; box-shadow:var(--shadow); overflow:hidden; position:relative; }
      .pflegejob-ribbon{ position:absolute; top:18px; right:-46px; background:var(--primary); color:#fff; padding:10px 64px; transform:rotate(25deg); font-weight:800; text-transform:uppercase; letter-spacing:.02em; box-shadow:0 2px 10px rgba(0,0,0,.06); }

      /* Platz rechts für fixe CTA (Desktop) */
      @media (min-width:1100px){ .pflegejob-page-pad{ padding-right:360px; } }
      @media (max-width:1024px){ .pflegejob-page-pad{ padding-right:0; } .cta-fixed{ position:static; width:auto; border-radius:16px; margin:12px 16px 0; } }
      @media (max-width:640px){ .pflegejob-wrap{ padding:12px; margin:20px auto; } .section{ padding:14px; border-radius:14px; } .facts{ gap:10px; } .icon{ width:34px; height:34px; } .title{ font-size:clamp(20px,3vw + 12px,28px); } }

      /* Unveränderte Hero-Section (aus deinem Layout) */
      .header{ padding:28px 28px 6px; }
      .job-id{ display:inline-flex; gap:.5rem; background:#ffe9e2; color:#a44328; padding:6px 12px; border-radius:999px; font-weight:800; }
      .title{ margin:14px 0 4px; font-size:clamp(22px,2.2vw + 12px,36px); font-weight:900; letter-spacing:.2px; }
      .subtitle{ margin:0 28px 18px; color:var(--text-muted); font-size:clamp(16px,1.2vw + 8px,20px); }

      .left{ padding:0 28px 28px; }
      .facts{ display:grid; grid-template-columns:1fr; gap:14px; }
      @media (min-width:640px){ .facts{ grid-template-columns:1fr 1fr; } }
      .fact{ display:flex; gap:12px; align-items:flex-start; padding:14px; border:1px dashed var(--border); border-radius:14px; background:#fff; }
      .icon{ width:40px;height:40px; border-radius:12px; background:#fff3ef; display:grid; place-items:center; flex:0 0 40px; border:1px solid #ffd7cc; color:var(--primary); }
      .fact strong{ display:block; font-weight:800; }
      .fact .value{ font-weight:700; }

      .location{ margin-top:18px; background:#faf7f6; border:1px solid var(--border); border-radius:18px; padding:16px; }
      .map-btn{ display:inline-flex; align-items:center; gap:.55rem; margin-top:10px; padding:10px 14px; border-radius:999px; border:1px solid var(--primary); color:var(--primary); background:#fff; font-weight:800; transition:.2s; cursor:pointer; }
      .map-btn:hover{ background:var(--primary); color:#fff; box-shadow:0 6px 18px rgba(247,128,96,.35); transform:translateY(-1px); }

      /* Lesbare Unter-Sektionen mit FontAwesome */
      .section{ margin-top:22px; border:1px solid var(--border); border-radius:18px; padding:20px; background:#fff; }
      .section h3{ margin:0 0 12px; color:var(--primary); font-size:20px; display:flex; align-items:center; gap:10px; }
      .section h3 i{ color:var(--primary); }

      /* Key/Value – mobil dürfen die Unterpunkte nebeneinander sein */
      .kv{ display:grid; grid-template-columns:1fr 1fr; gap:10px 22px; align-items:start; }
      .kv.stack-mobile{ grid-template-columns:1fr 2fr; }
      .kv .label{ display:flex; align-items:flex-start; gap:10px; }
      .kv .label i{ color:var(--primary); margin-top:2px; width:18px; text-align:center; }
      .kv b{ font-weight:900; }
      @media (max-width:420px){ .kv{ grid-template-columns:1fr; } .kv.stack-mobile{ grid-template-columns:1fr; } } /* nur sehr klein einspaltig */

      .bullets{ list-style:none; padding:0; margin:8px 0 0; }
      .bullets li{ display:flex; gap:10px; align-items:flex-start; margin:.4rem 0; }
      .bullets li i{ color:var(--primary); margin-top:2px; }

      .map iframe{ width:100%; height:300px; border:0; border-radius:12px; }
      @media (max-width:420px){ .map iframe{ height:240px; } #price-tag {font-size: 1.5rem !important;} .title{font-size: 1.65rem !important;} }

      .cta-fixed{
        position:fixed; right:20px; top: 136px; width:320px; z-index:100;
        background:#fff; border:1px solid var(--border); border-radius:22px; box-shadow:var(--shadow);
        padding:20px; display:flex; flex-direction:column; gap:14px;
      }
      .cta-fixed .btn{ display:flex; justify-content:center; align-items:center; gap:.55rem; border-radius:999px; padding:14px 18px; font-weight:800; border:2px solid transparent; cursor:pointer; }
      .cta-fixed .btn-outline{ background:#fff; color:var(--text); border-color:var(--border); }
      .cta-fixed .btn-outline:hover{ border-color:var(--primary); color:var(--primary); }
      .cta-fixed .btn-primary{ background:var(--primary); color:#fff; box-shadow:0 8px 22px rgba(247,128,96,.35); }
      .cta-fixed .badge{ display:inline-flex; align-items:center; gap:.5rem; padding:8px 12px; border-radius:999px; background:#e8fff4; color:#127a4b; font-weight:800; border:1px solid #c9f2e2; width:max-content; }

      @media (max-width:1099px){
        .cta-fixed{ right:0; left:0; bottom:0; top:auto; width:auto; border-radius:16px 16px 0 0; flex-direction:row; flex-wrap:wrap; justify-content:center; }
        .cta-fixed .badge{ display:none; }
        .cta-fixed .btn{ flex:1; min-width:120px; }
        .pflegejob-page-pad{ padding-right:0; }
      }

      /* Hintergrund-SVG */
      .bcg{
        position:fixed; top:0; left:0; width:100%; height:100%;
        z-index:-1; opacity:.9; pointer-events:none;
      }
    </style>

    <!-- Hintergrund SVG -->
    <svg class="bcg" preserveAspectRatio="xMidYMid slice" viewBox="10 10 80 80" aria-hidden="true">
      <defs><style>
        @keyframes rotate{0%{transform:rotate(0)}100%{transform:rotate(360deg)}}
        .out-top{animation:rotate 20s linear infinite;transform-origin:13px 25px}
        .in-top{animation:rotate 10s linear infinite;transform-origin:13px 25px}
        .out-bottom{animation:rotate 25s linear infinite;transform-origin:84px 93px}
        .in-bottom{animation:rotate 15s linear infinite;transform-origin:84px 93px}
      </style></defs>
      <path fill="#f780600f" class="out-top" d="M37-5C25.1-14.7,5.7-19.1-9.2-10-28.5,1.8-32.7,31.1-19.8,49c15.5,21.5,52.6,22,67.2,2.3C59.4,35,53.7,8.5,37-5Z"/>
      <path fill="#f780600f" class="in-top" d="M20.6,4.1C11.6,1.5-1.9,2.5-8,11.2-16.3,23.1-8.2,45.6,7.4,50S42.1,38.9,41,24.5C40.2,14.1,29.4,6.6,20.6,4.1Z"/>
      <path fill="#f780600f" class="out-bottom" d="M105.9,48.6c-12.4-8.2-29.3-4.8-39.4,0.8-23.4,12.8-37.7,51.9-19.1,74.1s63.9,15.3,76-5.6c7.6-13.3,1.8-31.1-2.3-43.8C117.6,63.3,114.7,54.3,105.9,48.6Z"/>
      <path fill="#f780600f" class="in-bottom" d="M102,67.1c-9.6-6.1-22-3.1-29.5,2-15.4,10.7-19.6,37.5-7.6,47.8s35.9,3.9,44.5-12.5C115.5,92.6,113.9,74.6,102,67.1Z"/>
    </svg>

    <div class="pflegejob-page-pad">
      <div class="pflegejob-wrap">
        <article class="pflegejob-card">

          <!-- HERO (identisch wie bei dir) -->
          <header class="header">
            <h1 class="title" style="color: #f78060"><?php
              echo 'Zlecenie opieki w '. $city;
            ?></h1>
          </header>
          <p class="subtitle" style="display:flex; justify-content:space-between; align-items:center;">
            <?php
              $where = trim(join_nonempty([$zip, $city, $state], ' '));
              $salTxt = $salary30 && $salary30 !== '-' ? $salary30 : '—';
            ?>
            <span><?php echo esc_html($start).' – '.esc_html($end).' • '.esc_html($where); ?></span>
            <span id="price-tag" style="font-size:1.8rem; font-weight:bold; color:#2c3e50;">
              <?php echo 'Stawka: '.esc_html($salTxt); ?>
            </span>
          </p>

          <section class="left">
            <!-- Fakty -->
            <div class="facts">
              <div class="fact"><div class="icon"><i class="fa-solid fa-city"></i></div><div><strong>Miejscowość</strong><div class="value"><?php echo $city.($state?' / '.$state:''); ?></div></div></div>
              <div class="fact"><div class="icon"><i class="fa-regular fa-calendar"></i></div><div><strong>Okres</strong><div class="value"><?php echo esc_html($start).' – '.esc_html($end); ?></div></div></div>
              <div class="fact"><div class="icon"><i class="fa-solid fa-coins"></i></div><div><strong>Wynagrodzenie</strong><div class="value"><?php echo $salary30; ?></div></div></div>
              <div class="fact"><div class="icon"><i class="fa-solid fa-user-check"></i></div><div><strong>Stopień opieki</strong><div class="value"><?php echo $pflegegrad; ?></div></div></div>
              <div class="fact"><div class="icon"><i class="fa-solid fa-language"></i></div><div><strong>Język</strong><div class="value"><?php echo $lang_name.($lang_level?' – '.$lang_level:''); ?></div></div></div>
              <div class="fact"><div class="icon"><i class="fa-solid fa-id-card"></i></div><div><strong>Prawo jazdy</strong><div class="value"><?php echo esc_html($license); ?></div></div></div>
            </div>

            <div class="location">
              <div><strong>Niemcy</strong> · <?php echo esc_html($state?:''); ?> · <?php echo esc_html(trim(join_nonempty([$zip,$city],' '))); ?></div>
              <a href="#map-section" class="map-btn"><i class="fa-solid fa-location-dot"></i> Sprawdź na mapie</a>
            </div>

            <!-- OSOBA 1 -->
            <div class="section">
              <h3><i class="fa-solid fa-user"></i> Podopieczny/a <?php echo $firstName1 ? ' ('.$firstName1.')' : ''; ?></h3>
              <div class="kv" aria-label="Profil osoby 1">
                <div class="label"><i class="fa-solid fa-venus-mars"></i><b>Płeć</b></div><div><?php echo $gender1; ?></div>
                <div class="label"><i class="fa-solid fa-hourglass-half"></i><b>Wiek</b></div><div><?php echo $age1; ?></div>
                <div class="label"><i class="fa-solid fa-ruler-vertical"></i><b>Wzrost</b></div><div><?php echo $height1; ?></div>
                <div class="label"><i class="fa-solid fa-weight-scale"></i><b>Waga</b></div><div><?php echo $weight1; ?></div>
                <div class="label"><i class="fa-regular fa-circle-check"></i><b>Stopień opieki</b></div><div><?php echo $pflegegrad; ?></div>
                <?php if ($residents !== ''): ?>
                  <div class="label"><i class="fa-solid fa-people-roof"></i><b>Mieszkańcy</b></div><div><?php echo $residents; ?></div>
                <?php endif; ?>
                <?php if ($petType !== '—'): ?>
                  <div class="label"><i class="fa-solid fa-paw"></i><b>Zwierzęta</b></div><div><?php echo $petType.' (opieka: '.$petsCare.')'; ?></div>
                <?php endif; ?>
                <?php if ($nightSorties !== '' && $nightSorties !== '0'): ?>
                  <div class="label"><i class="fa-regular fa-moon"></i><b>Nocne wyjścia</b></div><div><?php echo $nightSorties; ?>/mies.</div>
                <?php endif; ?>
              </div>

              <?php if (!empty($conditions)): ?>
                <?php
                  $condIconMap = [
                    'Demencja' => 'fa-brain',
                    'Typ demencji' => 'fa-brain',
                    'Osoba leżąca' => 'fa-bed',
                    'Transfer' => 'fa-people-arrows',
                    'Pomoc w mobilności' => 'fa-person-walking',
                    'Pieluchy' => 'fa-toilet-paper',
                    'Pomoc w toalecie' => 'fa-restroom',
                    'Pomoc przy jedzeniu' => 'fa-utensils',
                    'Higiena ciała' => 'fa-hand-sparkles',
                    'Pielęgnacja intymna' => 'fa-hand-holding-heart',
                    'Pomoc w ubieraniu' => 'fa-shirt',
                    'Anamneza' => 'fa-notes-medical',
                  ];
                ?>
                <div class="kv" style="margin-top:6px" aria-label="Stan zdrowia i opieka">
                  <?php foreach ($conditions as $c): ?>
                    <?php $parts = explode(':', $c, 2); $lbl = trim($parts[0]); $val = isset($parts[1]) ? trim($parts[1]) : ''; $ico = $condIconMap[$lbl] ?? 'fa-circle-check'; ?>
                    <div class="label"><i class="fa-solid <?php echo esc_attr($ico); ?>"></i><b><?php echo esc_html($lbl); ?></b></div>
                    <div><?php echo esc_html($val); ?></div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <?php if ($helpDevicesStr !== ''): ?>
                <div class="kv stack-mobile" style="margin-top:10px">
                  <div class="label"><i class="fa-solid fa-crutch"></i><b>Urządzenia pomocnicze</b></div><div><?php echo $helpDevicesStr; ?></div>
                </div>
              <?php endif; ?>

              <?php if ($add_req !== ''): ?>
                <div class="kv stack-mobile" style="margin-top:6px">
                  <div class="label"><i class="fa-regular fa-note-sticky"></i><b>Dodatkowe informacje</b></div><div><?php echo $add_req; ?></div>
                </div>
              <?php endif; ?>
            </div>

            <!-- ZAKWATEROWANIE -->
            <div class="section">
              <h3><i class="fa-solid fa-house"></i> Zakwaterowanie</h3>
              <?php if ($desc !== ''): ?><p><?php echo $desc; ?></p><?php endif; ?>

              <div class="kv" aria-label="Szczegóły zakwaterowania">
                <div class="label"><i class="fa-solid fa-house-chimney"></i><b>Typ domu</b></div><div><?php echo $houseType; ?></div>
                <div class="label"><i class="fa-solid fa-wifi"></i><b>Internet</b></div><div><?php echo $internet; ?></div>
                <div class="label"><i class="fa-solid fa-shower"></i><b>Własna łazienka</b></div><div><?php echo $ownBath; ?></div>
                <div class="label"><i class="fa-solid fa-person-shelter"></i><b>Oddzielny pokoj</b></div><div><?php echo $ownApt; ?></div>
                <div class="label"><i class="fa-solid fa-ban-smoking"></i><b>Dom palących</b></div><div><?php echo $smokerHH; ?></div>

                <?php if ($housePetsStr !== ''): ?>
                  <div class="label"><i class="fa-solid fa-paw"></i><b>Zwierzęta domowe</b></div><div><?php echo $housePetsStr . ($petsDesc ? ' ('.$petsDesc.')' : ''); ?></div>
                <?php endif; ?>

                <?php if ($shoppingFac !== ''): ?>
                  <div class="label"><i class="fa-solid fa-store"></i><b>Sklepy w pobliżu</b></div><div><?php echo $shoppingFac; ?></div>
                <?php endif; ?>

                <?php if ($toClean !== ''): ?>
                  <div class="label"><i class="fa-solid fa-broom"></i><b>Pow. do sprzątania</b></div><div><?php echo $toClean; ?></div>
                <?php endif; ?>

                <?php if ($mobOptionsStr !== ''): ?>
                  <div class="label"><i class="fa-solid fa-bus"></i><b>Srodek transportu</b></div><div><?php echo $mobOptionsStr; ?></div>
                <?php endif; ?>

                <?php if ($carModel !== '' || $gearbox !== ''): ?>
                  <div class="label"><i class="fa-solid fa-car-side"></i><b>Auto do dyspozycji</b></div><div><?php echo trim(join_nonempty([$carModel, $gearbox], ' / ')); ?></div>
                <?php endif; ?>

                <?php if ($surrounding !== ''): ?>
                  <div class="label"><i class="fa-regular fa-compass"></i><b>Okolica</b></div><div><?php echo $surrounding; ?></div>
                <?php endif; ?>
              </div>
            </div>

            <!-- 2. OSOBA -->
            <?php if ($secondPerson): ?>
              <div class="section">
                <h3><i class="fa-solid fa-user-group"></i> Podopieczny/a — Osoba 2</h3>
                <div class="kv">
                  <div class="label"><i class="fa-solid fa-venus-mars"></i><b>Płeć</b></div><div><?php echo $gender2; ?></div>
                  <div class="label"><i class="fa-solid fa-hourglass-half"></i><b>Wiek</b></div><div><?php echo $age2; ?></div>
                  <div class="label"><i class="fa-solid fa-ruler-vertical"></i><b>Wzrost</b></div><div><?php echo $height2; ?></div>
                  <div class="label"><i class="fa-solid fa-weight-scale"></i><b>Waga</b></div><div><?php echo $weight2; ?></div>
                  <div class="label"><i class="fa-regular fa-circle-check"></i><b>Stopień opieki</b></div><div><?php echo $pflege2; ?></div>
                </div>
              </div>
            <?php endif; ?>

            <!-- MAPA -->
            <div class="section map" id="map-section">
              <h3><i class="fa-solid fa-location-dot"></i> Lokalizacja</h3>
              <iframe src="<?php echo esc_url($iframeSrc); ?>" loading="lazy"></iframe>
              <p style="margin-top:8px;">
                <a href="<?php echo esc_url($mapLink); ?>" target="_blank" rel="noopener">Zobacz większą mapę</a>
              </p>
            </div>
          </section>
        </article>
      </div>
    </div>

    <!-- FIXED CTA (rechts / mobil unten) -->
    <aside class="cta-fixed" aria-label="Akcje">
      <a class="btn btn-outline" href="tel:+48800010150" role="button"><i class="fa-solid fa-phone"></i> ZADZWOŃ</a>
      <a class="btn btn-primary" href="<?php echo esc_url( add_query_arg(['jobid'=>$jobId], '/formularz-aplikacyjny') ); ?>" role="button"><i class="fa-solid fa-square-check"></i> APLIKUJ</a>
      <div class="badge"><i class="fa-solid fa-briefcase"></i> <?php echo esc_html__('popularne miejsce pracy',''); ?></div>
    </aside>

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
                // (Opcjonalnie) pobierz miasto/okres dla maila
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
                    // czyść wartości
                    $values = ['imie'=>'','nazwisko'=>'','telefon'=>''];
                } else {
                    $errors[] = 'Nie udało się wysłać wiadomości. Spróbuj ponownie.';
                }
            }
        }
    }

    ob_start();
    echo '<link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;600&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>';
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
