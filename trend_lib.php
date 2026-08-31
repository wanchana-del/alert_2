<?php
// ============================================================
// ไลบรารีกลางของระบบเฝ้าแนวโน้มระดับน้ำ
// ใช้ร่วมกันโดย trend_watch.php (ตัวจริงที่รันด้วย cron) และ trend_test.php (ตัวทดสอบ)
// เก็บสูตรไว้ที่เดียว ตัวทดสอบจะได้สะท้อนพฤติกรรมจริงเสมอ
// ============================================================

require_once __DIR__ . '/config.php';

const TK_STATION_NID = [
    'ST.14' => 51,  // นางรอง
    'ST.15' => 52,  // วังตระไคร้
    'ST.16' => 53,  // สาริกา
    'ST.01' => 61,  // NY.1B
    'ST.13' => 62,  // NY.7
];

// พารามิเตอร์ภาพรูปตัดลำน้ำของแต่ละสถานี (ค่าเดียวกับ CROSS_SECTION ใน tele.js)
const TK_CROSS_SECTION = [
    'ST.14' => ['n_id' => 51, 't' => 28.418, 'b' => 23.073],
    'ST.15' => ['n_id' => 52, 't' => 32.242, 'b' => 24.900],
    'ST.16' => ['n_id' => 53, 't' => 19.340, 'b' => 15.000],
    'ST.01' => ['n_id' => 61, 't' => 11.291, 'b' => 3.111],
    'ST.13' => ['n_id' => 62, 't' => 8.919,  'b' => -1.945],
];

const TK_HISTORY_FILE = __DIR__ . '/level_history.json';
const TK_STATE_FILE   = __DIR__ . '/trend_state.json';

/** อ่านค่าตั้งค่าของระบบเฝ้าแนวโน้ม พร้อมเติมค่า default ที่ขาด */
function tk_trend_cfg($config = null) {
    if ($config === null) $config = tk_load_config();
    $cfg = $config['trend_watch'] ?? [];
    return [
        'enabled'          => !empty($cfg['enabled']),
        'window_minutes'   => max(10, intval($cfg['window_minutes'] ?? 30)),
        'rise_warn'        => floatval($cfg['rise_warn'] ?? 0.10),
        'rise_fast'        => floatval($cfg['rise_fast'] ?? 0.30),
        'min_rise'         => floatval($cfg['min_rise'] ?? 0.02),
        'cooldown_minutes' => max(5, intval($cfg['cooldown_minutes'] ?? 30)),
        'notify_calm'      => !empty($cfg['notify_calm']),
        'send_image'       => !isset($cfg['send_image']) ? true : !empty($cfg['send_image']),
        'stations'         => $cfg['stations'] ?? array_keys(TK_STATION_NID),
    ];
}

/** แปลงวัน-เวลาไทย (รองรับปี พ.ศ.) เป็น timestamp */
function tk_parse_thai_datetime($dateStr, $timeStr) {
    $dateStr = str_replace('/', '-', $dateStr);
    $parts = explode('-', $dateStr);
    if (count($parts) === 3) {
        if ($parts[2] > 2500) $parts[2] -= 543;
        if ($parts[0] > 2500) $parts[0] -= 543;
        $dateStr = $parts[0] . '-' . $parts[1] . '-' . $parts[2];
    }
    $unix = strtotime("$dateStr $timeStr");
    return $unix ?: time();
}

/** ดูดค่าล่าสุดของสถานีจากเว็บกรมชลฯ คืน null ถ้าล้มเหลว */
function tk_fetch_latest($n_id) {
    $url = "http://khundan-tele.rid.go.th/station_detail.php?n_id=" . intval($n_id);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || empty($html)) return null;

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $rows = $xpath->query('//table[contains(@class, "table-striped")]/tbody/tr');
    if ($rows->length === 0) return null;

    $row  = $rows->item(0);
    $date = trim($xpath->query('./td[1]', $row)->item(0)->nodeValue);
    $time = trim($xpath->query('./td[2]', $row)->item(0)->nodeValue);
    $lvl  = trim($xpath->query('./td[3]', $row)->item(0)->nodeValue);
    if ($lvl === '' || !is_numeric($lvl)) return null;

    $qNode = $xpath->query('./td[4]', $row);

    return [
        'level'       => floatval($lvl),
        'q'           => $qNode->length > 0 ? floatval(trim($qNode->item(0)->nodeValue)) : 0,
        'record_time' => trim("$date $time"),
        'ts'          => tk_parse_thai_datetime($date, $time),
    ];
}

/** ความชันของกราฟด้วยการถดถอยเชิงเส้น หน่วยเมตร/ชั่วโมง ต้องมีอย่างน้อย 3 จุด */
function tk_slope_per_hour($points) {
    $n = count($points);
    if ($n < 3) return null;

    $sx = $sy = $sxy = $sxx = 0;
    foreach ($points as $p) {
        $x = $p[0] / 3600;
        $y = $p[1];
        $sx += $x; $sy += $y; $sxy += $x * $y; $sxx += $x * $x;
    }
    $den = ($n * $sxx) - ($sx * $sx);
    if (abs($den) < 1e-9) return null;
    return (($n * $sxy) - ($sx * $sy)) / $den;
}

/** ตัดสินสถานะจากความชัน: flat / rise / fast */
function tk_classify($slope, $netRise, $cfg) {
    if ($slope === null || $netRise < $cfg['min_rise']) return 'flat';
    if ($slope >= $cfg['rise_fast']) return 'fast';
    if ($slope >= $cfg['rise_warn']) return 'rise';
    return 'flat';
}

function tk_fmt_dur($hours) {
    if ($hours === null || $hours <= 0 || $hours > 72) return null;
    $m = (int) round($hours * 60);
    if ($m < 60) return "$m นาที";
    $h = intdiv($m, 60); $r = $m % 60;
    return $r ? "$h ชม. $r นาที" : "$h ชม.";
}

/**
 * ปั้นข้อความแจ้งเตือนของสถานีเดียว
 * @param array $d ['st_id','name','level','net_rise','slope','state','window_minutes','warn','crit','record_time']
 */
function tk_build_message($d) {
    if ($d['state'] === 'flat') {
        return "✅ {$d['name']} ({$d['st_id']})\n"
             . "แนวโน้มทรงตัวแล้ว\n"
             . "ระดับน้ำ: " . number_format($d['level'], 3) . " ม.\n"
             . "เวลาบันทึก: {$d['record_time']}";
    }

    $head  = $d['state'] === 'fast' ? "🚨 น้ำขึ้นเร็ว" : "🔺 น้ำเริ่มขึ้น";
    $lines = [
        "{$head} — {$d['name']} ({$d['st_id']})",
        "ระดับน้ำ: " . number_format($d['level'], 3) . " ม.",
        "ขึ้น " . number_format($d['net_rise'], 3) . " ม. ใน {$d['window_minutes']} นาทีที่ผ่านมา",
        "อัตราขึ้น: " . number_format($d['slope'], 3) . " ม./ชม.",
    ];

    if ($d['slope'] > 0) {
        if ($d['warn'] !== null && $d['level'] < $d['warn']) {
            $eta = tk_fmt_dur(($d['warn'] - $d['level']) / $d['slope']);
            if ($eta) $lines[] = "⚠️ ถึงระดับเฝ้าระวัง " . number_format($d['warn'], 2) . " ม. ในอีกราว {$eta}";
        }
        if ($d['crit'] !== null && $d['level'] < $d['crit']) {
            $eta = tk_fmt_dur(($d['crit'] - $d['level']) / $d['slope']);
            if ($eta) $lines[] = "🚨 ถึงระดับวิกฤต " . number_format($d['crit'], 2) . " ม. ในอีกราว {$eta}";
        }
    }
    if ($d['crit'] !== null && $d['level'] >= $d['crit'])      $lines[] = "🚨 ขณะนี้เกินระดับวิกฤตแล้ว";
    elseif ($d['warn'] !== null && $d['level'] >= $d['warn'])  $lines[] = "⚠️ ขณะนี้เกินระดับเฝ้าระวังแล้ว";

    $lines[] = "เวลาบันทึก: {$d['record_time']}";
    return implode("\n", $lines);
}

/** หัวข้อความรวมของทุกสถานี */
function tk_wrap_report($messages) {
    return "📈 รายงานแนวโน้มระดับน้ำอัตโนมัติ\n"
         . "เวลา " . date('d/m/Y H:i') . "\n\n"
         . implode("\n\n", $messages);
}

/** ปั้น URL ภาพรูปตัดลำน้ำจากเว็บกรมชลฯ (ภาพเดียวกับที่หน้าเว็บใช้ผ่าน proxy_image.php) */
function tk_cross_section_url($stId, $level, $warn, $crit) {
    if (!isset(TK_CROSS_SECTION[$stId])) return null;
    $cs = TK_CROSS_SECTION[$stId];
    return sprintf(
        'http://khundan-tele.rid.go.th/cs/cs.php?node=%d&t=%s&b=%s&v=%s&cri=%s&alt=%s',
        $cs['n_id'],
        number_format($cs['t'], 3, '.', ''),
        number_format($cs['b'], 3, '.', ''),
        number_format($level, 3, '.', ''),
        number_format($crit ?? 0, 3, '.', ''),
        number_format($warn ?? 0, 3, '.', '')
    );
}

/** ดาวน์โหลดภาพแล้วคืนเป็น base64 (คืน null ถ้าโหลดไม่ได้หรือไม่ใช่รูป) */
function tk_fetch_image_base64($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $bin  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($code !== 200 || empty($bin)) return null;
    if ($type && stripos($type, 'image') === false) return null;
    if (strlen($bin) < 500) return null; // เล็กเกินไป น่าจะเป็นหน้า error
    return base64_encode($bin);
}

/** ระดับน้ำอยู่ขั้นไหน: normal / warn / crit */
function tk_level_stage($level, $warn, $crit) {
    if ($crit !== null && $level >= $crit) return 'crit';
    if ($warn !== null && $level >= $warn) return 'warn';
    return 'normal';
}

// ============================================================
// คาดการณ์มวลน้ำเดินทางจากต้นน้ำสู่ปลายน้ำ
// ต่อยอดจาก getDynamicLagTime() ใน tele.js: หาความเร็วน้ำจาก Q ÷ พื้นที่หน้าตัด
// แล้วแปลงเป็นเวลาเดินทาง จากนั้นคาดว่าปลายน้ำจะขึ้นเท่าไหร่และเมื่อไหร่
// ============================================================

/** ค่าตั้งต้นของการคาดการณ์มวลน้ำ (แก้ได้ในหน้า admin) */
function tk_lag_cfg($config = null) {
    if ($config === null) $config = tk_load_config();
    $c = $config['lag_forecast'] ?? [];
    return [
        'enabled'     => !isset($c['enabled']) ? true : !empty($c['enabled']),
        'attenuation' => floatval($c['attenuation'] ?? 0.70), // มวลน้ำแผ่ตัวระหว่างทาง ปลายน้ำขึ้นน้อยกว่าต้นน้ำ
        'min_hours'   => floatval($c['min_hours'] ?? 1),
        'max_hours'   => floatval($c['max_hours'] ?? 24),
        'pairs'       => $c['pairs'] ?? [[
            'from'      => 'ST.15',   // วังตระไคร้ (ต้นทาง)
            'to'        => 'ST.01',   // NY.1B (ปลายทาง)
            'seg1_km'   => 6.5,
            'seg2_km'   => 6.5,
            'area_from' => 120.5,     // ⚠️ ค่าเดียวกับใน tele.js ที่ยังเป็นค่าประมาณ ควรแก้ให้ตรงจริง
            'area_to'   => 250.0,
        ]],
    ];
}

/**
 * เวลาเดินทางของมวลน้ำ (ชั่วโมง) — สูตรเดียวกับ getDynamicLagTime() ใน tele.js
 * แบ่ง 2 ท่อน เพราะลำน้ำกว้างขึ้นเมื่อลงมาปลายน้ำ ความเร็วจึงลดลง
 */
function tk_travel_time_hours($q, $pair, $cfg) {
    if ($q === null || $q <= 0) return null;

    $v1 = $q / max(0.1, floatval($pair['area_from']));   // ม./วิ
    $v2 = $q / max(0.1, floatval($pair['area_to']));
    if ($v1 <= 0 || $v2 <= 0) return null;

    $t1 = floatval($pair['seg1_km']) / ($v1 * 3.6);      // กม. ÷ (กม./ชม.)
    $t2 = floatval($pair['seg2_km']) / ($v2 * 3.6);
    $total = $t1 + $t2;

    return max($cfg['min_hours'], min($cfg['max_hours'], $total));
}

/**
 * ปั้นข้อความคาดการณ์มวลน้ำ คืน null ถ้าข้อมูลไม่พอ
 * @param array $up   ผลของสถานีต้นทาง ['level','q','net_rise','record_ts','name']
 * @param array $down ผลของสถานีปลายทาง ['level','name','warn','crit']
 */
function tk_lag_message($up, $down, $pair, $cfg) {
    $hours = tk_travel_time_hours($up['q'] ?? null, $pair, $cfg);
    if ($hours === null) return null;

    $arriveTs  = ($up['record_ts'] ?? time()) + (int) round($hours * 3600);
    $expedRise = max(0, ($up['net_rise'] ?? 0)) * $cfg['attenuation'];
    $expedLvl  = ($down['level'] ?? 0) + $expedRise;

    $lines = [
        "🌊 คาดการณ์มวลน้ำเดินทาง",
        "{$up['name']} → {$down['name']}",
        "อัตราการไหลต้นทาง: " . number_format($up['q'], 2) . " ลบ.ม./วิ",
        "เวลาเดินทางราว " . number_format($hours, 1) . " ชม. (ถึงราว " . date('H:i', $arriveTs) . " น.)",
    ];

    if ($expedRise > 0.001) {
        $lines[] = "ต้นทางขึ้น " . number_format($up['net_rise'], 3) . " ม. → คาดปลายทางขึ้นราว " . number_format($expedRise, 3) . " ม.";
        $lines[] = "ระดับปลายทางตอนนี้ " . number_format($down['level'], 3) . " ม. → คาดราว " . number_format($expedLvl, 3) . " ม.";

        $warn = $down['warn'] ?? null;
        $crit = $down['crit'] ?? null;
        if ($crit !== null && $expedLvl >= $crit)      $lines[] = "🚨 คาดว่าจะถึงระดับวิกฤต " . number_format($crit, 2) . " ม.";
        elseif ($warn !== null && $expedLvl >= $warn)  $lines[] = "⚠️ คาดว่าจะถึงระดับเฝ้าระวัง " . number_format($warn, 2) . " ม.";
    }

    $lines[] = "(ประมาณจากพื้นที่หน้าตัดที่ตั้งไว้ ไม่ใช่ค่าวัดจริง)";
    return implode("\n", $lines);
}

/** สร้างชุดข้อมูลจำลองสำหรับทดสอบ: จุดทุก 5 นาที ตามรูปแบบที่เลือก */
function tk_make_fake_series($pattern, $startLevel, $windowMinutes = 30) {
    $stepSec = 300;
    $count   = max(3, (int) floor($windowMinutes * 60 / $stepSec) + 1);
    $rate    = [           // เมตรต่อชั่วโมง
        'flat'   => 0.00,
        'wobble' => 0.00,
        'slow'   => 0.06,
        'rise'   => 0.15,
        'fast'   => 0.48,
        'drop'   => -0.24,
    ][$pattern] ?? 0.0;

    $points = [];
    for ($i = 0; $i < $count; $i++) {
        $t = $i * $stepSec;
        $y = $startLevel + $rate * ($t / 3600);
        if ($pattern === 'wobble') $y += ($i % 2 ? 0.01 : -0.01); // เซ็นเซอร์แกว่ง ±1 ซม.
        $points[] = [$t, round($y, 3)];
    }
    return $points;
}

function tk_pattern_labels() {
    return [
        'flat'   => 'น้ำนิ่ง',
        'wobble' => 'เซ็นเซอร์แกว่ง ±1 ซม.',
        'slow'   => 'ขึ้นช้า 6 ซม./ชม.',
        'rise'   => 'เริ่มเชิดหัว 15 ซม./ชม.',
        'fast'   => 'ขึ้นเร็ว 48 ซม./ชม.',
        'drop'   => 'น้ำลด',
    ];
}
?>