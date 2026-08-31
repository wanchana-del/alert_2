<?php
// รับข้อมูลที่ LINE ส่งมา
$content = file_get_contents('php://input');
$events = json_decode($content, true);

if (!empty($events['events'])) {
    foreach ($events['events'] as $event) {
        // เช็คว่ามีคนแชทมาจาก "กลุ่ม" ใช่หรือไม่
        if ($event['source']['type'] === 'group') {
            $groupId = $event['source']['groupId'];
            // เซฟ Group ID ลงไฟล์ text ให้เราไปก๊อปปี้ง่ายๆ
            file_put_contents('group_id.txt', "Group ID ของคุณคือ: " . $groupId);
        }
    }
}
echo "200 OK";
?>