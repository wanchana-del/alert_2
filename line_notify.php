<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require __DIR__ . '/config.php';
$config = tk_load_config();

// 🔴 ดึงค่าจากไฟล์ตั้งค่าแทนการ hardcode ในไฟล์นี้ (แก้ไขได้ผ่านหน้า admin.php โดยไม่ต้องแตะโค้ด)
$channelAccessToken = $config['line_channel_access_token'] ?? '';
$targetId = $config['line_target_id'] ?? '';
$imgbbApiKey = $config['imgbb_api_key'] ?? '';

$messageText = isset($_POST['message']) ? $_POST['message'] : '🚨 แจ้งเตือนน้ำท่วม!';
$imageData = isset($_POST['image']) ? $_POST['image'] : '';

// 🔴 ไม่มีรูปก็ส่งได้ (ข้อความล้วน) ใช้กับการแจ้งเตือนแนวโน้มและการทดสอบ
$textOnly = empty($imageData);

if (empty($channelAccessToken) || empty($targetId)) {
    echo json_encode(["status" => "error", "message" => "ยังไม่ได้ตั้งค่า LINE Token / Target ID กรุณาตั้งค่าที่หน้า admin.php ก่อน"]);
    exit;
}
if (!$textOnly && empty($imgbbApiKey)) {
    echo json_encode(["status" => "error", "message" => "ยังไม่ได้ตั้งค่า imgbb API Key (จำเป็นเฉพาะตอนส่งรูป)"]);
    exit;
}

// ---------- ส่งข้อความล้วน จบตรงนี้ ----------
if ($textOnly) {
    $postData = [
        "to" => $targetId,
        "messages" => [["type" => "text", "text" => mb_substr($messageText, 0, 4900)]]
    ];
    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $channelAccessToken
    ]);
    $lineResult = curl_exec($ch);
    $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr    = curl_error($ch);
    curl_close($ch);

    echo json_encode([
        "status"         => ($httpCode === 200 && !$curlErr) ? "success" : "error",
        "mode"           => "text",
        "message"        => $curlErr ?: ($httpCode !== 200 ? "LINE ตอบกลับ HTTP $httpCode" : "ส่งข้อความสำเร็จ"),
        "line_response"  => json_decode($lineResult, true)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 🔴 ตัดหัว data URI ของทุกชนิดภาพ (png/jpeg/webp) ไม่ใช่เฉพาะ png
// ภาพรูปตัดลำน้ำจาก proxy_image.php เป็น jpeg หัวจึงไม่ตรงกับของเดิมที่ตัดแค่ png
$imageData = preg_replace('#^data:image/[^;]+;base64,#i', '', $imageData);
$imageData = str_replace(' ', '+', trim($imageData));

// อัปโหลดรูปไป imgbb ก่อน เพื่อให้ได้ URL สาธารณะที่ LINE เข้าถึงได้จริง
$ch = curl_init('https://api.imgbb.com/1/upload');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'key'        => $imgbbApiKey,
    'image'      => $imageData,
    'expiration' => 2592000, // ลบอัตโนมัติหลังจาก 30 วัน
]);
$imgbbResponse = curl_exec($ch);
$imgbbCurlErr  = curl_error($ch);
curl_close($ch);

if ($imgbbCurlErr) {
    echo json_encode(["status" => "error", "message" => "เชื่อมต่อ imgbb ไม่ได้: " . $imgbbCurlErr]);
    exit;
}
$imgbbResult = json_decode($imgbbResponse, true);
if (empty($imgbbResult['data']['url'])) {
    $why = $imgbbResult['error']['message'] ?? '';
    if (stripos($why, 'invalid') !== false || stripos($why, 'api key') !== false) {
        $why = 'imgbb API Key ไม่ถูกต้อง กรุณาตั้งค่าใหม่ที่หน้า admin.php';
    }
    echo json_encode([
        "status"      => "error",
        "message"     => "อัปโหลด imgbb ไม่สำเร็จ" . ($why ? ": $why" : ""),
        "image_bytes" => strlen($imageData),
        "raw"         => $imgbbResponse
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$imageUrl = $imgbbResult['data']['url'];

// ส่งข้อความ + URL รูปจาก imgbb เข้า LINE
$postData = [
    "to" => $targetId,
    "messages" => [
        ["type" => "text", "text" => $messageText],
        ["type" => "image", "originalContentUrl" => $imageUrl, "previewImageUrl" => $imageUrl]
    ]
];

$ch2 = curl_init('https://api.line.me/v2/bot/message/push');
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($postData));
curl_setopt($ch2, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $channelAccessToken
]);
$lineResult  = curl_exec($ch2);
$lineCurlErr = curl_error($ch2);
curl_close($ch2);

echo json_encode([
    "status"        => "success",
    "image_url"      => $imageUrl,
    "line_response"  => json_decode($lineResult, true),
    "line_curl_error" => $lineCurlErr
]);
?>