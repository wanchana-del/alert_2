<?php
// ============================================================
// ดูดข้อมูลทุกสถานีมากองไว้ที่ water_data.json
//
// ต่างจากเวอร์ชันเดิม 4 จุด:
//   1. บอกสาเหตุรายสถานี ว่าพังตรงไหน (HTTP อะไร, ได้กี่ไบต์, เจอกี่แถว)
//   2. ลองใหม่ได้ 3 ครั้งถ้าล้มเหลว พร้อมเว้นจังหวะเพิ่มขึ้นเรื่อยๆ
//   3. ส่ง User-Agent และใช้ connection เดิมซ้ำ ลดโอกาสถูกเซิร์ฟเวอร์ต้นทางปัดตก
//   4. รวมกับข้อมูลเดิมแทนการเขียนทับ สถานีที่ดึงไม่ได้รอบนี้จะไม่หายไปจากไฟล์
// ============================================================

date_default_timezone_set('Asia/Bangkok');
require __DIR__ . '/config.php';
$config = tk_load_config();

if (php_sapi_name() !== 'cli') header('Content-Type: text/plain; charset=UTF-8');

$stations = [
    "ST.14" => 51, "ST.15" => 52, "ST.16" => 53,
    "ST.01" => 61, "ST.13" => 62,
];

$JSON_FILE = __DIR__ . '/water_data.json';
$LOG_FILE  = __DIR__ . '/cron_log.txt';

$trendSeconds = ($config['trend_minutes'] ?? 15) * 60;
$labels       = tk_station_labels();

// ---------- โหลดของเดิมไว้ก่อน กันข้อมูลหายตอนดึงไม่สำเร็จ ----------
$results = [];
if (file_exists($JSON_FILE)) {
    $old = json_decode(file_get_contents($JSON_FILE), true);
    if (is_array($old)) $results = $old;
}

function parseToUnix($dateStr, $timeStr) {
    $dateStr = str_replace('/', '-', $dateStr);
    $parts = explode('-', $dateStr);
    if (count($parts) == 3) {
        if ($parts[2] > 2500) $parts[2] -= 543;
        if ($parts[0] > 2500) $parts[0] -= 543;
        $dateStr = $parts[0] . '-' . $parts[1] . '-' . $parts[2];
    }
    return strtotime("$dateStr $timeStr");
}

// ---------- ใช้ curl handle เดียวทั้งรอบ เพื่อใช้ connection ซ้ำ ----------
$ch = curl_init();
$cookieJar = sys_get_temp_dir() . '/tk_rid_cookie.txt';
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_ENCODING       => '',   // รับ gzip ได้ โหลดเร็วขึ้น
    CURLOPT_COOKIEJAR      => $cookieJar,
    CURLOPT_COOKIEFILE     => $cookieJar,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    CURLOPT_HTTPHEADER     => [
        'Accept: text/html,application/xhtml+xml',
        'Accept-Language: th,en;q=0.8',
        'Referer: http://khundan-tele.rid.go.th/',
    ],
]);

/** ดึงหน้าเว็บ ลองใหม่ได้หลายครั้ง คืนรายละเอียดไว้วินิจฉัย */
function fetchStationHtml($ch, $n_id, $tries = 3) {
    $url = "http://khundan-tele.rid.go.th/station_detail.php?n_id=" . intval($n_id);
    $last = ['html' => '', 'code' => 0, 'err' => '', 'ms' => 0, 'attempt' => 0];

    for ($i = 1; $i <= $tries; $i++) {
        curl_setopt($ch, CURLOPT_URL, $url);
        $t0   = microtime(true);
        $html = curl_exec($ch);
        $ms   = (int) round((microtime(true) - $t0) * 1000);

        $last = [
            'html'    => $html ?: '',
            'code'    => curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'err'     => curl_error($ch),
            'ms'      => $ms,
            'attempt' => $i,
        ];

        if ($last['code'] === 200 && strlen($last['html']) > 500) return $last;
        if ($i < $tries) sleep($i * 2);   // 2 วิ แล้ว 4 วิ
    }
    return $last;
}

$report = [];
$okCount = 0;

foreach ($stations as $st_id => $n_id) {
    $name = $labels[$st_id] ?? $st_id;
    $r    = fetchStationHtml($ch, $n_id);

    if ($r['code'] !== 200 || strlen($r['html']) < 500) {
        $why = $r['err'] ?: ($r['code'] === 0 ? 'ต่อไม่ติด' : "HTTP {$r['code']}");
        $report[] = sprintf("❌ %-6s %-22s %s | ได้ %d ไบต์ | ลอง %d ครั้ง | %d มิลลิวินาที",
            $st_id, $name, $why, strlen($r['html']), $r['attempt'], $r['ms']);
        sleep(1);
        continue;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($r['html']);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $rows = $xpath->query('//table[contains(@class, "table-striped")]/tbody/tr');

    if ($rows->length === 0) {
        // โหลดหน้าได้แต่ไม่เจอตาราง = โครงสร้างหน้าเว็บอาจต่างจากที่คิด หรือสถานีนี้ไม่มีข้อมูล
        $anyTable = $xpath->query('//table')->length;
        $report[] = sprintf("⚠️ %-6s %-22s โหลดหน้าได้ (%d ไบต์) แต่ไม่เจอตารางข้อมูล | ในหน้ามี table ทั้งหมด %d ตัว",
            $st_id, $name, strlen($r['html']), $anyTable);
        sleep(1);
        continue;
    }

    $latest = $rows->item(0);
    $d   = trim($xpath->query('./td[1]', $latest)->item(0)->nodeValue);
    $t   = trim($xpath->query('./td[2]', $latest)->item(0)->nodeValue);
    $raw = trim($xpath->query('./td[3]', $latest)->item(0)->nodeValue);

    if ($raw === '' || !is_numeric($raw)) {
        $report[] = sprintf("⚠️ %-6s %-22s เจอตาราง %d แถว แต่ค่าระดับน้ำอ่านไม่ได้ (\"%s\")",
            $st_id, $name, $rows->length, mb_substr($raw, 0, 20));
        sleep(1);
        continue;
    }

    $lvl    = floatval($raw);
    $qNode  = $xpath->query('./td[4]', $latest);
    $q      = $qNode->length > 0 ? floatval(trim($qNode->item(0)->nodeValue)) : 0;
    $latestUnix = parseToUnix($d, $t);

    // ---------- ค่าเมื่อ X นาทีที่แล้ว ----------
    $lvl_15 = null;
    for ($i = 1; $i < $rows->length; $i++) {
        $row   = $rows->item($i);
        $rUnix = parseToUnix(
            trim($xpath->query('./td[1]', $row)->item(0)->nodeValue),
            trim($xpath->query('./td[2]', $row)->item(0)->nodeValue)
        );
        if ($latestUnix && $rUnix && ($latestUnix - $rUnix) >= $trendSeconds) {
            $lvl_15 = floatval(trim($xpath->query('./td[3]', $row)->item(0)->nodeValue));
            break;
        }
    }

    // ---------- กราฟย้อนหลัง 48 จุด ----------
    $chart = [];
    $limit = min($rows->length, 48);
    for ($i = 0; $i < $limit; $i++) {
        $row      = $rows->item($i);
        $cTime    = trim($xpath->query('./td[2]', $row)->item(0)->nodeValue);
        $cLvlNode = $xpath->query('./td[3]', $row);
        $cLvlRaw  = $cLvlNode->length > 0 ? trim($cLvlNode->item(0)->nodeValue) : '';
        $cLvl     = ($cLvlRaw !== '' && is_numeric($cLvlRaw)) ? floatval($cLvlRaw) : null;
        $cQNode   = $xpath->query('./td[4]', $row);
        $cQ       = $cQNode->length > 0 ? floatval(trim($cQNode->item(0)->nodeValue)) : 0;
        array_unshift($chart, ["time" => $cTime, "q" => $cQ, "level" => $cLvl]);
    }

    $results[$st_id] = [
        "level"           => $lvl,
        "q"               => $q,
        "record_time"     => "$d $t",
        "level_15min_ago" => $lvl_15,
        "chart_data"      => $chart,
        "fetch_time"      => date('Y-m-d H:i:s'),
    ];

    $okCount++;
    $report[] = sprintf("✅ %-6s %-22s %.3f ม. | Q %.2f | กราฟ %d จุด | %s | %d มิลลิวินาที%s",
        $st_id, $name, $lvl, $q, count($chart), "$d $t", $r['ms'],
        $r['attempt'] > 1 ? " (ลอง {$r['attempt']} ครั้ง)" : "");

    sleep(1); // ถ่วงจังหวะกันโดนบล็อก IP
}

curl_close($ch);

// ---------- เขียนไฟล์แบบรวมของเดิม ----------
file_put_contents($JSON_FILE, json_encode($results, JSON_UNESCAPED_UNICODE), LOCK_EX);

// ---------- สรุปผล ----------
$head = sprintf("[%s] สำเร็จ %d/%d สถานี", date('Y-m-d H:i:s'), $okCount, count($stations));
$out  = $head . "\n" . implode("\n", $report) . "\n";

// เตือนถ้ามีสถานีที่ค้างข้อมูลเก่าอยู่ในไฟล์
foreach ($stations as $st_id => $_) {
    if (isset($results[$st_id]['fetch_time'])) {
        $age = time() - strtotime($results[$st_id]['fetch_time']);
        if ($age > 1800) {
            $out .= sprintf("⏳ %s ใช้ข้อมูลเก่าจากเมื่อ %d นาทีที่แล้ว\n", $st_id, (int) round($age / 60));
        }
    } else {
        $out .= "🚫 $st_id ไม่มีข้อมูลในไฟล์เลย\n";
    }
}

// เก็บ log ไว้ 200 บรรทัดล่าสุด ไว้ย้อนดูว่าพังตอนไหน
$oldLog = file_exists($LOG_FILE) ? file($LOG_FILE, FILE_IGNORE_NEW_LINES) : [];
$newLog = array_merge($oldLog, explode("\n", rtrim($out)), ['']);
file_put_contents($LOG_FILE, implode("\n", array_slice($newLog, -200)));

echo $out;
?>