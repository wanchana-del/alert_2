<?php
date_default_timezone_set('Asia/Bangkok');
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require __DIR__ . '/config.php';
$config = tk_load_config();

$n_id = isset($_GET['n_id']) ? intval($_GET['n_id']) : 0;
$st_id = $_GET['st_id'] ?? null;

// ดักสำหรับสถานีอนาคต (ST.00) ให้ส่งค่าว่างกลับไปเนียนๆ
if ($n_id === 0 || $n_id === 999) {
    echo json_encode([
        "status" => "success", "n_id" => $n_id, "level" => null, "q" => null,
        "record_time" => null, "level_15min_ago" => null, "chart_data" => [],
        "thresholds" => ($st_id && isset($config['thresholds'][$st_id])) ? $config['thresholds'][$st_id] : null,
    ]);
    exit;
}

$json_file = __DIR__ . '/water_data.json';

// ถ้าไฟล์ JSON ยังไม่มี (Cron ยังไม่เคยรัน)
if (!file_exists($json_file)) {
    echo json_encode(["status" => "error", "message" => "ยังไม่มีข้อมูล Cache จากระบบ Cron"]);
    exit;
}

$all_data = json_decode(file_get_contents($json_file), true);

// ถ้าไม่มีสถานีนี้ในไฟล์
if (!isset($all_data[$st_id])) {
    echo json_encode(["status" => "error", "message" => "ไม่มีข้อมูลของสถานี $st_id ใน Cache"]);
    exit;
}

// หยิบข้อมูลสถานีที่ขอมา แล้วแนบค่าแจ้งเตือนจากหน้า Admin เข้าไป
$response = $all_data[$st_id];
$response['status'] = "success";
$response['n_id'] = $n_id;
$response['thresholds'] = $config['thresholds'][$st_id] ?? null;

echo json_encode($response);
?>