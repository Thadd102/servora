<?php
require_once __DIR__ . '/../config/database.php';

$pubKey = 'BI7X9AOzrFRQom92iB_ka-e6yB5EmFbsyCJwLx1nWx9geQ6Amb_2aJlBekKqy0dNws_a29IxM_ZDFg_loD4FkFY';
$privKey = 'v-IDYrY_DvniG3LG0hAh45Du3tUzriDqe9GHJuNS0bk';
$subject = 'mailto:support@subnext.com.ng';

$settings = [
    'vapid_public_key' => $pubKey,
    'vapid_private_key' => $privKey,
    'vapid_subject' => $subject,
    'apk_download_enabled' => '1',
    'apk_download_url' => 'downloads/subnext.apk',
];

foreach ($settings as $k => $v) {
    $stmt = $pdo->prepare("
        INSERT INTO app_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$k, $v]);
}

echo "VAPID keys and APK settings successfully configured in database.\n";
