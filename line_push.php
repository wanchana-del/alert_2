<?php
// ============================================================
// ส่งข้อความ LINE แบบข้อความล้วน (ไม่ต้องแนบรูป)
// ใช้โดย trend_watch.php ที่รันจาก cron ซึ่งไม่มีเบราว์เซอร์ให้แคปหน้าจอ
// ============================================================

require_once __DIR__ . '/config.php';

/**
 * อัปโหลดรูป base64 ขึ้น imgbb เพื่อให้ได้ URL สาธารณะที่ LINE เข้าถึงได้
 * @return string|null URL ของรูป
 */
function tk_imgbb_upload($base64, $config = null) {
    if ($config === null) $config = tk_load_config();
    $key = $config['imgbb_api_key'] ?? '';
    if (empty($key) || empty($base64)) return null;

    $base64 = preg_replace('#^data:image/\w+;base64,#', '', $base64);

    $ch = curl_init('https://api.imgbb.com/1/upload');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'key'        => $key,
        'image'      => $base64,
        'expiration' => 2592000, // ลบอัตโนมัติใน 30 วัน
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($res, true);
    return $data['data']['url'] ?? null;
}

/**
 * ส่งข้อความเข้า LINE พร้อมแนบรูปได้หลายใบ
 * @param string $text ข้อความ
 * @param array $imageUrls รายการ URL รูป (ถ้ามี)
 * @return array ['ok' => bool, 'error' => string]
 */
function tk_line_push_text($text, $imageUrls = []) {
    $config = tk_load_config();
    $token  = $config['line_channel_access_token'] ?? '';
    $target = $config['line_target_id'] ?? '';

    if (empty($token) || empty($target)) {
        return ['ok' => false, 'error' => 'ยังไม่ได้ตั้งค่า LINE Token หรือ Target ID'];
    }

    $messages = [['type' => 'text', 'text' => mb_substr($text, 0, 4900)]];
    foreach (array_slice($imageUrls, 0, 4) as $url) {   // LINE จำกัด 5 ข้อความต่อครั้ง
        if (!empty($url)) {
            $messages[] = ['type' => 'image', 'originalContentUrl' => $url, 'previewImageUrl' => $url];
        }
    }
    $payload = ['to' => $target, 'messages' => $messages];

    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['ok' => false, 'error' => 'เชื่อมต่อ LINE ไม่ได้: ' . $err];
    if ($code !== 200) return ['ok' => false, 'error' => "LINE ตอบกลับ HTTP $code: $res"];
    return ['ok' => true, 'error' => ''];
}
?>