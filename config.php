<?php
// ============================================================
// ไฟล์นี้เป็น "ตัวกลาง" อ่าน/เขียนค่าตั้งค่าทั้งหมดของระบบ
// ใช้ร่วมกันโดย admin.php, api.php, line_notify.php
// ค่าจริงถูกเก็บไว้ที่ config_data.php (สร้างอัตโนมัติตอนเข้าใช้งานครั้งแรก)
//
// 🔒 เหตุผลที่เก็บเป็นไฟล์ .php แทนที่จะเป็น .json:
// ถ้าเก็บเป็น .json แล้วมีคนเดา URL ไฟล์ตรง ๆ (เช่น yourdomain.com/config_data.json)
// เบราว์เซอร์จะโชว์ LINE Token และรหัสผ่าน (แบบ hash) ออกมาเป็นข้อความล้วน ๆ ทันที
// แต่ถ้าเก็บเป็น .php เซิร์ฟเวอร์จะรันโค้ดแทนการแสดงผล ทำให้ไม่มีอะไรรั่วไหลออกมาแม้ถูกเข้าตรง ๆ
// ============================================================

define('TK_CONFIG_FILE', __DIR__ . '/config_data.php');
define('TK_DEFAULT_ADMIN_PASSWORD', 'changeme123'); // 🔴 รหัสผ่านเริ่มต้น ให้เข้า admin.php แล้วเปลี่ยนทันที

function tk_default_config() {
    return [
        'admin_password_hash'       => password_hash(TK_DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT),
        // 🔒 ไม่ฝังคีย์จริงไว้ในโค้ดแล้ว ให้กรอกผ่านหน้า admin.php (ค่าจะถูกเก็บใน config_data.php)
        'line_channel_access_token' => '',
        'line_target_id'            => '',
        'imgbb_api_key'              => 'ใส่_API_KEY_ของคุณตรงนี้',
        'trend_minutes'              => 15, // ช่วงเวลาที่ใช้เทียบระดับน้ำขึ้น/ลง (นาที)

        // 🔴 ระบบรายงานอัตโนมัติเมื่อกราฟระดับน้ำ "เชิดหัวขึ้น" (ใช้โดย trend_watch.php)
        'trend_watch' => [
            'enabled'          => 1,     // เปิด/ปิดระบบ
            'window_minutes'   => 30,    // วัดความชันจากข้อมูลย้อนหลังกี่นาที
            'rise_warn'        => 0.10,  // ม./ชม. ขึ้นเกินนี้ = เริ่มเชิดหัว
            'rise_fast'        => 0.30,  // ม./ชม. ขึ้นเกินนี้ = ขึ้นเร็ว
            'min_rise'         => 0.02,  // ต้องขึ้นรวมอย่างน้อยกี่เมตร (กันค่าแกว่งหลอก)
            'cooldown_minutes' => 30,    // ถ้ายังขึ้นอยู่ ให้ย้ำเตือนซ้ำทุกกี่นาที
            'notify_calm'      => 0,     // แจ้งตอนกลับมาทรงตัวด้วยไหม
            'send_image'       => 1,     // แนบภาพรูปตัดลำน้ำเมื่อถึงระดับเฝ้าระวังขึ้นไป
            'stations'         => ['ST.14', 'ST.15', 'ST.16', 'ST.01', 'ST.13'],
        ],
        // 🔴 คาดการณ์มวลน้ำเดินทางจากต้นน้ำ (ต่อยอดจาก getDynamicLagTime ใน tele.js)
        'lag_forecast' => [
            'enabled'     => 1,
            'attenuation' => 0.70,  // ปลายน้ำขึ้นเป็นสัดส่วนเท่าไหร่ของต้นน้ำ
            'min_hours'   => 1,
            'max_hours'   => 24,
            'pairs' => [
                [
                    'from' => 'ST.15', 'to' => 'ST.01',
                    'seg1_km' => 6.5, 'seg2_km' => 6.5,
                    'area_from' => 120.5, 'area_to' => 250.0,
                ],
            ],
        ],

        'thresholds' => [
            'ST.00' => ['warn' => 80.00, 'crit' => 100.00],
            'ST.01' => ['warn' => 7.50,  'crit' => 8.00],
            'ST.13' => ['warn' => 6.00,  'crit' => 6.25],
            'ST.14' => ['warn' => 26.00, 'crit' => 27.00],
            'ST.15' => ['warn' => 29.50, 'crit' => 30.50],
            'ST.16' => ['warn' => 17.50, 'crit' => 18.50],
        ],
    ];
}

function tk_load_config() {
    if (file_exists(TK_CONFIG_FILE)) {
        $data = include TK_CONFIG_FILE;
        if (is_array($data)) {
            // เผื่อไฟล์เก่าขาด key ไหนไป (เช่นเพิ่งอัปเดตระบบ) ให้เติมค่า default กันโค้ดพัง
            $changed = false;
            foreach (tk_default_config() as $key => $val) {
                if (!array_key_exists($key, $data)) {
                    $data[$key] = $val;
                    $changed = true;
                }
            }
            if ($changed) tk_save_config($data);
            return $data;
        }
    }
    // ยังไม่เคยมีไฟล์ตั้งค่า -> สร้างค่าเริ่มต้นแล้วเซฟไว้เลย
    $default = tk_default_config();
    tk_save_config($default);
    return $default;
}

function tk_save_config($data) {
    $php = "<?php\n"
         . "// ⚠️ ไฟล์นี้ถูกสร้าง/แก้ไขโดยระบบอัตโนมัติผ่านหน้า admin.php\n"
         . "// ไม่ควรแก้ไขด้วยมือ เว้นแต่จำเป็นจริง ๆ (แก้ผิดพลาดระบบอาจไม่ทำงาน)\n"
         . "return " . var_export($data, true) . ";\n";
    return file_put_contents(TK_CONFIG_FILE, $php, LOCK_EX) !== false;
}

function tk_station_labels() {
    return [
        'ST.00' => 'เขื่อนขุนด่านปราการชล',
        'ST.01' => 'NY.1B',
        'ST.13' => 'NY.7 แม่น้ำนครนายก',
        'ST.14' => 'สถานีนางรอง',
        'ST.15' => 'สถานีวังตระไคร้',
        'ST.16' => 'สถานีสาริกา',
    ];
}
?>