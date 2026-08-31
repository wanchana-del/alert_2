<?php
// ============================================================
// เฝ้าแนวโน้มระดับน้ำ แล้วรายงานเข้า LINE อัตโนมัติเมื่อกราฟ "เชิดหัวขึ้น"
// รันด้วย cron ทุก 5-15 นาที (สูตรคำนวณอยู่ใน trend_lib.php)
// ============================================================

date_default_timezone_set('Asia/Bangkok');
require __DIR__ . '/trend_lib.php';
require __DIR__ . '/line_push.php';

if (php_sapi_name() !== 'cli') header('Content-Type: text/plain; charset=UTF-8');

$config = tk_load_config();
$cfg    = tk_trend_cfg($config);

if (!$cfg['enabled']) {
    echo "ระบบรายงานแนวโน้มถูกปิดอยู่ (เปิดได้ที่หน้า admin.php)\n";
    exit;
}

$labels     = tk_station_labels();
$thresholds = $config['thresholds'] ?? [];

$history = file_exists(TK_HISTORY_FILE) ? (json_decode(file_get_contents(TK_HISTORY_FILE), true) ?: []) : [];
$state   = file_exists(TK_STATE_FILE)   ? (json_decode(file_get_contents(TK_STATE_FILE), true) ?: []) : [];

$now       = time();
$results   = [];   // เก็บผลของทุกสถานีไว้ใช้คาดการณ์มวลน้ำหลังวนครบ
$sentNow   = [];   // สถานีที่ส่งข้อความในรอบนี้
$messages  = [];
$imageUrls = [];
$summary   = [];

foreach ($cfg['stations'] as $stId) {
    if (!isset(TK_STATION_NID[$stId])) continue;

    $data = tk_fetch_latest(TK_STATION_NID[$stId]);
    sleep(1); // ถ่วงเวลากันเซิร์ฟเวอร์ต้นทางมองว่าเป็นบอทยิงสแปม
    if (!$data) { $summary[] = "$stId: ดูดข้อมูลไม่ได้"; continue; }

    // ---------- เก็บประวัติ (ข้ามถ้าเวลาบันทึกซ้ำของเดิม) ----------
    $hist = $history[$stId] ?? [];
    $last = end($hist);
    if (!$last || $last[0] !== $data['ts']) $hist[] = [$data['ts'], $data['level']];
    $hist = array_values(array_filter($hist, fn($p) => $p[0] > $now - 48 * 3600));
    usort($hist, fn($a, $b) => $a[0] <=> $b[0]);
    $history[$stId] = $hist;

    // ---------- คำนวณแนวโน้ม ----------
    $cut = $data['ts'] - $cfg['window_minutes'] * 60;
    $win = array_values(array_filter($hist, fn($p) => $p[0] >= $cut));

    $slope    = tk_slope_per_hour($win);
    $netRise  = count($win) >= 2 ? ($win[count($win) - 1][1] - $win[0][1]) : 0;
    $newState = tk_classify($slope, $netRise, $cfg);

    $warnLvl  = $thresholds[$stId]['warn'] ?? null;
    $critLvl  = $thresholds[$stId]['crit'] ?? null;
    $stage    = tk_level_stage($data['level'], $warnLvl, $critLvl);

    // ---------- ตัดสินใจว่าจะส่งไหม ----------
    $prev      = $state[$stId] ?? ['state' => 'flat', 'stage' => 'normal', 'last_alert' => 0];
    $prevState = $prev['state'] ?? 'flat';
    $prevStage = $prev['stage'] ?? 'normal';
    $rank      = ['flat' => 0, 'rise' => 1, 'fast' => 2];
    $stageRank = ['normal' => 0, 'warn' => 1, 'crit' => 2];
    $cooldown  = $cfg['cooldown_minutes'] * 60;

    $shouldSend = false;
    if ($newState !== 'flat') {
        if ($rank[$newState] > $rank[$prevState]) $shouldSend = true;              // เพิ่งเริ่มขึ้น หรือขึ้นแรงกว่าเดิม
        elseif ($now - ($prev['last_alert'] ?? 0) >= $cooldown) $shouldSend = true; // ยังขึ้นอยู่ ย้ำซ้ำ
    } elseif ($cfg['notify_calm'] && $prevState !== 'flat') {
        $shouldSend = true;                                                        // กลับมาทรงตัว
    }

    // 🔴 ถึงระดับเฝ้าระวัง/วิกฤต = แจ้งเสมอ แม้น้ำจะนิ่ง (ระดับสูงค้างก็ยังอันตราย)
    $stageCrossed = $stageRank[$stage] > $stageRank[$prevStage];
    if ($stageCrossed) $shouldSend = true;

    if ($shouldSend) {
        $messages[] = tk_build_message([
            'st_id'          => $stId,
            'name'           => $labels[$stId] ?? $stId,
            'level'          => $data['level'],
            'net_rise'       => $netRise,
            'slope'          => $slope,
            'state'          => $newState,
            'window_minutes' => $cfg['window_minutes'],
            'warn'           => $warnLvl,
            'crit'           => $critLvl,
            'record_time'    => $data['record_time'],
        ]);
        $prev['last_alert'] = $now;

        // 🔴 แนบภาพรูปตัดลำน้ำเมื่อระดับถึงเฝ้าระวังขึ้นไป
        //    ส่งตอน "ข้ามขั้น" หรือครบคูลดาวน์ ไม่ส่งรูปซ้ำทุกรอบให้รก
        $wantImage = !empty($cfg['send_image'])
                     && $stage !== 'normal'
                     && ($stageCrossed || $now - ($prev['last_image'] ?? 0) >= $cooldown);

        if ($wantImage) {
            $url = tk_cross_section_url($stId, $data['level'], $warnLvl, $critLvl);
            $b64 = $url ? tk_fetch_image_base64($url) : null;
            $up  = $b64 ? tk_imgbb_upload($b64, $config) : null;
            if ($up) {
                $imageUrls[] = $up;
                $prev['last_image'] = $now;
                $summary[] = "$stId: แนบภาพรูปตัดลำน้ำ";
            } else {
                $summary[] = "$stId: ดึงภาพรูปตัดไม่สำเร็จ (ส่งเฉพาะข้อความ)";
            }
        }
    }

    $state[$stId] = [
        'state'       => $newState,
        'stage'       => $stage,
        'last_image'  => $prev['last_image'] ?? 0,
        'slope'       => $slope !== null ? round($slope, 4) : null,
        'net_rise'    => round($netRise, 4),
        'level'       => $data['level'],
        'points'      => count($win),
        'record_time' => $data['record_time'],
        'checked_at'  => date('Y-m-d H:i:s', $now),
        'last_alert'  => $prev['last_alert'] ?? 0,
    ];

    $results[$stId] = [
        'name'      => $labels[$stId] ?? $stId,
        'level'     => $data['level'],
        'q'         => $data['q'] ?? null,
        'net_rise'  => $netRise,
        'slope'     => $slope,
        'state'     => $newState,
        'stage'     => $stage,
        'record_ts' => $data['ts'],
        'warn'      => $warnLvl,
        'crit'      => $critLvl,
    ];
    if ($shouldSend) $sentNow[$stId] = true;

    $summary[] = sprintf(
        "%s: %.3f ม. | ชัน %s ม./ชม. | แนวโน้ม %s | ระดับ %s%s",
        $stId, $data['level'],
        $slope !== null ? number_format($slope, 3) : '-',
        $newState, $stage,
        $shouldSend ? ' (ส่ง LINE)' : ''
    );
}

// ---------- คาดการณ์มวลน้ำจากต้นน้ำสู่ปลายน้ำ ----------
$lagCfg = tk_lag_cfg($config);
if ($lagCfg['enabled']) {
    foreach ($lagCfg['pairs'] as $pair) {
        $from = $pair['from'] ?? null;
        $to   = $pair['to'] ?? null;
        if (!$from || !$to || !isset($results[$from])) continue;

        $up = $results[$from];
        // เตือนล่วงหน้าเฉพาะตอนต้นน้ำกำลังขึ้นหรือถึงเกณฑ์แล้ว และเป็นรอบที่มีการแจ้งอยู่แล้ว
        if (empty($sentNow[$from])) continue;
        if ($up['state'] === 'flat' && $up['stage'] === 'normal') continue;

        // ปลายน้ำอาจไม่ได้อยู่ในรายการเฝ้า จึงดึงค่าล่าสุดมาเพิ่มถ้ายังไม่มี
        $down = $results[$to] ?? null;
        if (!$down && isset(TK_STATION_NID[$to])) {
            $d = tk_fetch_latest(TK_STATION_NID[$to]);
            sleep(1);
            if ($d) {
                $down = [
                    'name'  => $labels[$to] ?? $to,
                    'level' => $d['level'],
                    'warn'  => $thresholds[$to]['warn'] ?? null,
                    'crit'  => $thresholds[$to]['crit'] ?? null,
                ];
            }
        }
        if (!$down) { $summary[] = "$from → $to: ไม่มีข้อมูลปลายทาง"; continue; }

        $msg = tk_lag_message($up, $down, $pair, $lagCfg);
        if ($msg) {
            $messages[] = $msg;
            $summary[]  = "$from → $to: แนบคาดการณ์มวลน้ำ";
        } else {
            $summary[] = "$from → $to: คำนวณไม่ได้ (ขาดค่า Q)";
        }
    }
}

file_put_contents(TK_HISTORY_FILE, json_encode($history, JSON_UNESCAPED_UNICODE));
file_put_contents(TK_STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

if ($messages) {
    $res = tk_line_push_text(tk_wrap_report($messages), $imageUrls);
    $summary[] = $res['ok'] ? "ส่ง LINE สำเร็จ" : "ส่ง LINE ไม่สำเร็จ: " . $res['error'];
}

echo implode("\n", $summary) . "\n";
?>