<?php
session_start();
require __DIR__ . '/config.php';

$config = tk_load_config(); // โหลดตั้งค่าอื่นๆ (LINE, ฯลฯ) มาใช้ตามปกติ
$message = '';
$messageType = '';
$json_file = __DIR__ . '/admin_db.json';

// ---------- LOGOUT ----------
if (isset($_GET['logout'])) {
    unset($_SESSION['tk_admin_logged_in']);
    session_destroy();
    header('Location: admin.php');
    exit;
}

// ---------- LOGIN (ใช้ JSON) ----------
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $input_user = $_POST['username'] ?? '';
    $input_pass = $_POST['password'] ?? '';

    if (file_exists($json_file)) {
        $admin_db = json_decode(file_get_contents($json_file), true);
        
        if ($input_user === $admin_db['username'] && password_verify($input_pass, $admin_db['password_hash'])) {
            $_SESSION['tk_admin_logged_in'] = true;
            header('Location: admin.php');
            exit;
        } else {
            $message = '❌ ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
            $messageType = 'error';
        }
    } else {
        $message = '⚠️ ระบบพัง: หาไฟล์ admin_db.json ไม่เจอ!';
        $messageType = 'error';
    }
}

$isLoggedIn = !empty($_SESSION['tk_admin_logged_in']);

// ---------- SAVE SETTINGS ----------
if ($isLoggedIn && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    $config['line_channel_access_token'] = trim($_POST['line_channel_access_token'] ?? '');
    $config['line_target_id']            = trim($_POST['line_target_id'] ?? '');
    $config['imgbb_api_key']             = trim($_POST['imgbb_api_key'] ?? '');

    $trendMinutes = isset($_POST['trend_minutes']) ? intval($_POST['trend_minutes']) : 15;
    if ($trendMinutes < 1) $trendMinutes = 1;
    if ($trendMinutes > 1440) $trendMinutes = 1440;
    $config['trend_minutes'] = $trendMinutes;

    foreach (tk_station_labels() as $stId => $label) {
        $warnKey = 'warn_' . $stId;
        $critKey = 'crit_' . $stId;
        if (isset($_POST[$warnKey]) && isset($_POST[$critKey])) {
            $config['thresholds'][$stId] = [
                'warn' => floatval($_POST[$warnKey]),
                'crit' => floatval($_POST[$critKey]),
            ];
        }
    }

    // ---------- ระบบรายงานแนวโน้มอัตโนมัติ ----------
    $tw = $config['trend_watch'] ?? [];
    $tw['enabled']          = isset($_POST['tw_enabled']) ? 1 : 0;
    $tw['notify_calm']      = isset($_POST['tw_notify_calm']) ? 1 : 0;
    $tw['send_image']       = isset($_POST['tw_send_image']) ? 1 : 0;
    $tw['window_minutes']   = max(10, min(360, intval($_POST['tw_window_minutes'] ?? 30)));
    $tw['cooldown_minutes'] = max(5, min(720, intval($_POST['tw_cooldown_minutes'] ?? 30)));
    $tw['rise_warn']        = max(0.01, floatval($_POST['tw_rise_warn'] ?? 0.10));
    $tw['rise_fast']        = max($tw['rise_warn'], floatval($_POST['tw_rise_fast'] ?? 0.30));
    $tw['min_rise']         = max(0, floatval($_POST['tw_min_rise'] ?? 0.02));
    $tw['stations']         = isset($_POST['tw_stations']) && is_array($_POST['tw_stations'])
                              ? array_values($_POST['tw_stations']) : [];
    $config['trend_watch']  = $tw;

    // ---------- คาดการณ์มวลน้ำเดินทาง ----------
    $lf = $config['lag_forecast'] ?? [];
    $lf['enabled']     = isset($_POST['lf_enabled']) ? 1 : 0;
    $lf['attenuation'] = max(0.1, min(1.0, floatval($_POST['lf_attenuation'] ?? 0.70)));
    $pair = $lf['pairs'][0] ?? ['from' => 'ST.15', 'to' => 'ST.01'];
    $pair['seg1_km']   = max(0.1, floatval($_POST['lf_seg1_km'] ?? 6.5));
    $pair['seg2_km']   = max(0.1, floatval($_POST['lf_seg2_km'] ?? 6.5));
    $pair['area_from'] = max(0.1, floatval($_POST['lf_area_from'] ?? 120.5));
    $pair['area_to']   = max(0.1, floatval($_POST['lf_area_to'] ?? 250.0));
    $lf['pairs'] = [$pair];
    $config['lag_forecast'] = $lf;

    if (tk_save_config($config)) {
        file_put_contents(__DIR__ . '/config_updated.txt', time()); // ตัวส่งซิกให้หน้าเว็บ F5 ตัวเอง
        $message = '✅ บันทึกการตั้งค่าเรียบร้อยแล้ว';
        $messageType = 'success';
    } else {
        $message = '❌ บันทึกไม่สำเร็จ (เช็คสิทธิ์เขียนไฟล์ในโฟลเดอร์นี้)';
        $messageType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ระบบตั้งค่า - โทรมาตรขุนด่านปราการชล</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="L08-65.png">
<style>
    * { box-sizing: border-box; margin:0; padding:0; font-family:'Sarabun', sans-serif; }
    body { background:#f4f6f7; color:#2c3e50; padding: 30px 15px; }
    .wrap { max-width: 720px; margin: 0 auto; }
    h1 { font-size: 22px; margin-bottom: 20px; color:#3b5062; }
    .card { background:#fff; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.08); padding:24px; margin-bottom:20px; }
    .card h2 { font-size:16px; color:#3b5062; margin-bottom:14px; border-bottom:1px solid #eee; padding-bottom:8px; }
    label { display:block; font-size:13px; color:#555; margin-bottom:4px; margin-top:12px; }
    input[type=text], input[type=password], input[type=number], textarea {
        width:100%; padding:10px; border:1px solid #ccc; border-radius:6px; font-size:14px; font-family:'Sarabun',sans-serif;
    }
    textarea { min-height:70px; resize:vertical; }
    button { margin-top:16px; padding:10px 20px; border:none; border-radius:6px; background:#2980b9; color:#fff; font-size:14px; font-weight:600; cursor:pointer; }
    button:hover { background:#1f618d; }
    .btn-secondary { background:#7f8c8d; }
    .btn-secondary:hover { background:#636e72; }
    .msg { padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:14px; font-weight:600; }
    .msg.success { background:#e8f8f5; color:#1e8449; border:1px solid #a3e4d7; }
    .msg.error { background:#fdedec; color:#c0392b; border:1px solid #f5b7b1; }
    table { width:100%; border-collapse:collapse; margin-top:8px; }
    th, td { padding:8px; text-align:left; font-size:13px; border-bottom:1px solid #eee; }
    th { color:#7f8c8d; font-weight:600; }
    td input { width:100px; }
    .login-box { max-width:360px; margin:80px auto; }
    .logout-link { display:inline-block; margin-bottom:16px; font-size:13px; color:#c0392b; text-decoration:none; }
    .hint { font-size:12px; color:#888; margin-top:4px; }
    .hint code { background:#eef2f5; padding:1px 5px; border-radius:4px; }
    label.switch { display:flex; align-items:center; gap:8px; font-size:14px; color:#2c3e50; margin-top:10px; cursor:pointer; }
    label.switch input { width:auto; }
    a { color:#2980b9; }
    /* 🌙 ระบบเปลี่ยนธีม (Dark Mode) */
body.dark-mode { background: #121212; color: #e0e0e0; }
body.dark-mode .card { background: #1e1e1e; box-shadow: none; border: 1px solid #333; }
body.dark-mode h1, body.dark-mode .card h2 { color: #fff; border-bottom-color: #333; }
body.dark-mode label, body.dark-mode .hint, body.dark-mode th, body.dark-mode td { color: #bbb; border-bottom-color: #333; }
body.dark-mode input, body.dark-mode textarea, body.dark-mode select { background: #2a2a2a; color: #fff; border-color: #555; }
body.dark-mode a { color: #5bc0de; }
body.dark-mode pre { background: #000; color: #00ffcc; border: 1px solid #333; } 
.theme-toggle { position: fixed; top: 15px; right: 15px; background: #2c3e50; color: #fff; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: bold; z-index: 9999; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
body.dark-mode .theme-toggle { background: #f39c12; color: #121212; }
</style>
</head>
<body>
    <button id="themeToggle" class="theme-toggle">🌗 เปลี่ยนธีม</button>
<script>
    const themeBtn = document.getElementById('themeToggle');
    // โหลดค่าเดิมที่เคยเลือกไว้
    if (localStorage.getItem('dark_theme') === 'true') {
        document.body.classList.add('dark-mode');
    }
    // คำสั่งสลับธีมเมื่อกดปุ่ม
    themeBtn.onclick = () => {
        document.body.classList.toggle('dark-mode');
        localStorage.setItem('dark_theme', document.body.classList.contains('dark-mode'));
    };
</script>

<?php if (!$isLoggedIn): ?>

    <div class="wrap login-box">
        <div class="card">
            <h2>🔒 เข้าสู่ระบบผู้ดูแล</h2>
            <?php if ($message): ?>
                <div class="msg <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="login">
                
                <label for="username">ชื่อผู้ใช้</label>
                <input type="text" id="username" name="username" required autofocus>
                
                <label for="password">รหัสผ่าน</label>
                <input type="password" id="password" name="password" required>
                
                <button type="submit">เข้าสู่ระบบ</button>
            </form>
            
        </div>
    </div>

<?php else: ?>

    <div class="wrap">
        <a href="admin.php?logout=1" class="logout-link">🚪 ออกจากระบบ</a>
        <h1>⚙️ ตั้งค่าระบบโทรมาตร</h1>

        <?php if ($message): ?>
            <div class="msg <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="action" value="save_settings">

            <div class="card">
                <h2>📨 การแจ้งเตือน LINE</h2>
                <label for="line_channel_access_token">LINE Channel Access Token</label>
                <textarea name="line_channel_access_token" id="line_channel_access_token"><?= htmlspecialchars($config['line_channel_access_token'] ?? '') ?></textarea>

                <label for="line_target_id">LINE Target ID (User/Group ID)</label>
                <input type="text" name="line_target_id" id="line_target_id" value="<?= htmlspecialchars($config['line_target_id'] ?? '') ?>">

                <label for="imgbb_api_key">imgbb API Key</label>
                <input type="text" name="imgbb_api_key" id="imgbb_api_key" value="<?= htmlspecialchars($config['imgbb_api_key'] ?? '') ?>">
                <p class="hint">สมัครฟรีได้ที่ <a href="https://api.imgbb.com/" target="_blank">api.imgbb.com</a></p>
            </div>

            <div class="card">
                <h2>⏱️ ช่วงเวลาเปรียบเทียบระดับน้ำ (น้ำขึ้น/น้ำลง)</h2>
                <label for="trend_minutes">เทียบกับระดับน้ำเมื่อกี่นาทีที่แล้ว</label>
                <input type="number" name="trend_minutes" id="trend_minutes" min="1" max="1440" value="<?= (int)($config['trend_minutes'] ?? 15) ?>">
                <p class="hint">ค่าเริ่มต้นคือ 15 นาที (ใช้แสดงลูกศร 🔺น้ำขึ้น / 🔻น้ำลง ในหน้าเว็บ)</p>
            </div>

            <div class="card">
                <h2>📈 รายงานอัตโนมัติเมื่อกราฟน้ำเชิดหัวขึ้น</h2>
                <?php $tw = $config['trend_watch'] ?? []; ?>

                <label class="switch">
                    <input type="checkbox" name="tw_enabled" value="1" <?= !empty($tw['enabled']) ? 'checked' : '' ?>>
                    เปิดระบบเฝ้าแนวโน้มและส่ง LINE อัตโนมัติ
                </label>

                <label for="tw_window_minutes">วัดความชันจากข้อมูลย้อนหลัง (นาที)</label>
                <input type="number" name="tw_window_minutes" id="tw_window_minutes" min="10" max="360"
                       value="<?= (int)($tw['window_minutes'] ?? 30) ?>">
                <p class="hint">สั้นไปจะไวต่อค่าแกว่ง ยาวไปจะรู้ตัวช้า แนะนำ 30 นาที</p>

                <label for="tw_rise_warn">เกณฑ์ “เริ่มขึ้น” (เมตร/ชั่วโมง)</label>
                <input type="number" step="0.01" name="tw_rise_warn" id="tw_rise_warn"
                       value="<?= htmlspecialchars($tw['rise_warn'] ?? 0.10) ?>">

                <label for="tw_rise_fast">เกณฑ์ “ขึ้นเร็ว” (เมตร/ชั่วโมง)</label>
                <input type="number" step="0.01" name="tw_rise_fast" id="tw_rise_fast"
                       value="<?= htmlspecialchars($tw['rise_fast'] ?? 0.30) ?>">

                <label for="tw_min_rise">ต้องขึ้นรวมอย่างน้อย (เมตร)</label>
                <input type="number" step="0.01" name="tw_min_rise" id="tw_min_rise"
                       value="<?= htmlspecialchars($tw['min_rise'] ?? 0.02) ?>">
                <p class="hint">กันกรณีค่าเซ็นเซอร์แกว่งขึ้นลงเล็กน้อยแล้วถูกอ่านว่าน้ำขึ้น</p>

                <label for="tw_cooldown_minutes">ถ้ายังขึ้นอยู่ ให้ย้ำเตือนซ้ำทุกกี่นาที</label>
                <input type="number" name="tw_cooldown_minutes" id="tw_cooldown_minutes" min="5" max="720"
                       value="<?= (int)($tw['cooldown_minutes'] ?? 30) ?>">

                <label class="switch">
                    <input type="checkbox" name="tw_notify_calm" value="1" <?= !empty($tw['notify_calm']) ? 'checked' : '' ?>>
                    แจ้งเตือนตอนน้ำกลับมาทรงตัวด้วย
                </label>

                <label class="switch">
                    <input type="checkbox" name="tw_send_image" value="1" <?= (!isset($tw['send_image']) || !empty($tw['send_image'])) ? 'checked' : '' ?>>
                    แนบภาพรูปตัดลำน้ำเมื่อระดับถึงเฝ้าระวังขึ้นไป
                </label>
                <p class="hint">ใช้ภาพเดียวกับที่หน้าเว็บแสดง อัปโหลดผ่าน imgbb ก่อนส่งเข้า LINE (ต้องตั้ง imgbb API Key)</p>

                <label>สถานีที่ต้องการเฝ้า</label>
                <?php
                $watchable = ['ST.14', 'ST.15', 'ST.16', 'ST.01', 'ST.13'];
                $picked = $tw['stations'] ?? $watchable;
                foreach ($watchable as $sid):
                ?>
                    <label class="switch">
                        <input type="checkbox" name="tw_stations[]" value="<?= $sid ?>"
                               <?= in_array($sid, $picked) ? 'checked' : '' ?>>
                        <?= htmlspecialchars(tk_station_labels()[$sid] ?? $sid) ?> (<?= $sid ?>)
                    </label>
                <?php endforeach; ?>

                <p class="hint" style="margin-top:14px">
                    ระบบนี้ทำงานด้วย <b>cron</b> ที่เรียก <code>trend_watch.php</code> ทุก 5-15 นาที
                    เปิดหน้าเว็บทิ้งไว้หรือไม่ก็ทำงานเหมือนกัน ·
                    <a href="trend_watch.php" target="_blank">ทดสอบรันเดี๋ยวนี้</a>
                </p>
            </div>

            <div class="card">
                <h2>🌊 คาดการณ์มวลน้ำเดินทางจากต้นน้ำ</h2>
                <?php
                $lf = $config['lag_forecast'] ?? [];
                $pair = $lf['pairs'][0] ?? [];
                ?>
                <label class="switch">
                    <input type="checkbox" name="lf_enabled" value="1" <?= (!isset($lf['enabled']) || !empty($lf['enabled'])) ? 'checked' : '' ?>>
                    เตือนล่วงหน้าว่ามวลน้ำจากวังตระไคร้จะถึง NY.1B เมื่อไหร่
                </label>
                <p class="hint">ใช้สูตรเดียวกับที่หน้าเว็บใช้อยู่: ความเร็วน้ำ = อัตราการไหล ÷ พื้นที่หน้าตัด</p>

                <table>
                    <tr><th>ระยะท่อนบน (กม.)</th><th>ระยะท่อนล่าง (กม.)</th></tr>
                    <tr>
                        <td><input type="number" step="0.1" name="lf_seg1_km" value="<?= htmlspecialchars($pair['seg1_km'] ?? 6.5) ?>"></td>
                        <td><input type="number" step="0.1" name="lf_seg2_km" value="<?= htmlspecialchars($pair['seg2_km'] ?? 6.5) ?>"></td>
                    </tr>
                    <tr><th>พื้นที่หน้าตัดต้นทาง (ตร.ม.)</th><th>พื้นที่หน้าตัดปลายทาง (ตร.ม.)</th></tr>
                    <tr>
                        <td><input type="number" step="0.1" name="lf_area_from" value="<?= htmlspecialchars($pair['area_from'] ?? 120.5) ?>"></td>
                        <td><input type="number" step="0.1" name="lf_area_to" value="<?= htmlspecialchars($pair['area_to'] ?? 250.0) ?>"></td>
                    </tr>
                </table>
                <p class="hint">⚠️ ตัวเลขพื้นที่หน้าตัดสองช่องนี้ยกมาจาก tele.js ซึ่งยังเป็นค่าประมาณ
                   (ในโค้ดเดิมมีคอมเมนต์ว่า "อย่าลืมแก้เลข Area ให้ตรงกับของจริง")
                   เวลาเดินทางที่คำนวณได้จะแม่นเท่าที่ตัวเลขนี้แม่น</p>

                <label for="lf_attenuation">ปลายน้ำขึ้นเป็นสัดส่วนของต้นน้ำ (0-1)</label>
                <input type="number" step="0.05" min="0.1" max="1" name="lf_attenuation" id="lf_attenuation"
                       value="<?= htmlspecialchars($lf['attenuation'] ?? 0.70) ?>">
                <p class="hint">มวลน้ำแผ่ตัวระหว่างทาง ปลายน้ำจึงขึ้นน้อยกว่าต้นน้ำ ค่า 0.7 = ขึ้นราว 70% ของที่ต้นน้ำขึ้น</p>
            </div>

            <div class="card">
                <h2>🚨 ระดับเฝ้าระวัง / วิกฤต แต่ละสถานี</h2>
                <table>
                    <tr><th>สถานี</th><th>เฝ้าระวัง (ม.)</th><th>วิกฤต (ม.)</th></tr>
                    <?php foreach (tk_station_labels() as $stId => $label):
                        $warn = $config['thresholds'][$stId]['warn'] ?? 0;
                        $crit = $config['thresholds'][$stId]['crit'] ?? 0;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($label) ?> <span class="hint">(<?= $stId ?>)</span></td>
                        <td><input type="number" step="0.001" name="warn_<?= $stId ?>" value="<?= htmlspecialchars($warn) ?>"></td>
                        <td><input type="number" step="0.001" name="crit_<?= $stId ?>" value="<?= htmlspecialchars($crit) ?>"></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <button type="submit">💾 บันทึกการตั้งค่าทั้งหมด</button>
        </form>

        

<?php endif; ?>

</body>
</html>