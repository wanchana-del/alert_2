<?php
// ⚠️ ไฟล์นี้ถูกสร้าง/แก้ไขโดยระบบอัตโนมัติผ่านหน้า admin.php
// ไม่ควรแก้ไขด้วยมือ เว้นแต่จำเป็นจริง ๆ (แก้ผิดพลาดระบบอาจไม่ทำงาน)
return array (
  'admin_password_hash' => '$2y$10$aR6cqKnMohNRO8.UQpiyye8XQ5ob7rsnumUxW5hQUtATc2Ds2IuuO',
  'line_channel_access_token' => 'EI6Cj6mF1LwZCRwPKdTUhe3Y6RDPoL6frVN2vbHkJUMwfsPmoR9fen3iCLPmzK5SWY7ZJh9RgfoWgNfv6BHOlUoqnRCsfLivIpNanSADKDufnJn7KNgMDJTpHr6FIPO0l6yn0xfeyEBtjzuvd4bbpgdB04t89/1O/w1cDnyilFU=',
  'line_target_id' => 'C9d07743376f92c7604919831f84972e6',
  'imgbb_api_key' => '2ff5679bf882e637b3553a428758f109',
  'trend_minutes' => 60,
  'thresholds' => 
  array (
    'ST.00' => 
    array (
      'warn' => 80.0,
      'crit' => 100.0,
    ),
    'ST.01' => 
    array (
      'warn' => 7.5,
      'crit' => 8.0,
    ),
    'ST.13' => 
    array (
      'warn' => 6.0,
      'crit' => 6.25,
    ),
    'ST.14' => 
    array (
      'warn' => 26.0,
      'crit' => 27.0,
    ),
    'ST.15' => 
    array (
      'warn' => 29.5,
      'crit' => 30.5,
    ),
    'ST.16' => 
    array (
      'warn' => 17.5,
      'crit' => 18.5,
    ),
  ),
  'trend_watch' => 
  array (
    'enabled' => 1,
    'window_minutes' => 15,
    'rise_warn' => 0.1,
    'rise_fast' => 0.3,
    'min_rise' => 0.02,
    'cooldown_minutes' => 30,
    'notify_calm' => 1,
    'stations' => 
    array (
      0 => 'ST.14',
      1 => 'ST.15',
      2 => 'ST.16',
      3 => 'ST.01',
      4 => 'ST.13',
    ),
    'send_image' => 1,
  ),
  'lag_forecast' => 
  array (
    'enabled' => 1,
    'attenuation' => 0.7,
    'min_hours' => 1,
    'max_hours' => 24,
    'pairs' => 
    array (
      0 => 
      array (
        'from' => 'ST.15',
        'to' => 'ST.01',
        'seg1_km' => 6.5,
        'seg2_km' => 6.5,
        'area_from' => 120.5,
        'area_to' => 250.0,
      ),
    ),
  ),
);
