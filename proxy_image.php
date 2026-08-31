<?php
// รับค่าตัวแปรทั้งหมดที่ส่งมาจาก JS
$node = isset($_GET['node']) ? $_GET['node'] : '';
$t = isset($_GET['t']) ? $_GET['t'] : '';
$b = isset($_GET['b']) ? $_GET['b'] : '';
$v = isset($_GET['v']) ? $_GET['v'] : '';
$cri = isset($_GET['cri']) ? $_GET['cri'] : '';
$alt = isset($_GET['alt']) ? $_GET['alt'] : '';

// ถ้าไม่มีรหัสสถานีให้หยุดทำงาน
if (empty($node)) {
    http_response_code(400);
    die("Error: No Node ID provided");
}

// ปั้นลิงก์ไปดูดรูปต้นทาง (ที่เป็น HTTP ธรรมดา)
$targetUrl = "http://khundan-tele.rid.go.th/cs/cs.php?node={$node}&t={$t}&b={$b}&v={$v}&cri={$cri}&alt={$alt}";

// บอกเบราว์เซอร์ว่า "นี่คือรูปภาพนะ ไม่ใช่ข้อความ"
header('Content-Type: image/jpeg');

// ปิด Error ชั่วคราวกันพัง แล้วโหลดภาพมาแสดงผลตรงๆ
@readfile($targetUrl);
?>