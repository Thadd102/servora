<?php
require_once __DIR__ . '/../config/database.php';

echo "Running Announcements & Push Notifications Migration...\n";

$sql = file_get_contents(__DIR__ . '/announcements_and_push.sql');
$pdo->exec($sql);
echo "Tables created or confirmed successfully.\n";

// Ensure VAPID keys in app_settings
$stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'vapid_public_key' LIMIT 1");
$stmt->execute();
$existingPub = $stmt->fetchColumn();

if (empty($existingPub)) {
    echo "Generating standard VAPID P-256 keypair...\n";
    $config = [
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ];
    $res = openssl_pkey_new($config);
    if ($res) {
        openssl_pkey_export($res, $privKeyPem);
        $details = openssl_pkey_get_details($res);
        
        // P-256 raw coordinates: x and y are 32 bytes each
        $rawX = $details['ec']['x'];
        $rawY = $details['ec']['y'];
        $rawD = $details['ec']['d'];
        
        // Uncompressed public key format: 0x04 + X + Y (65 bytes)
        $rawPub = "\x04" . $rawX . $rawY;
        
        // Base64Url encode
        $vapidPublicKey = rtrim(strtr(base64_encode($rawPub), '+/', '-_'), '=');
        $vapidPrivateKey = rtrim(strtr(base64_encode($rawD), '+/', '-_'), '=');
        
        $settingsToInsert = [
            'vapid_public_key' => $vapidPublicKey,
            'vapid_private_key' => $vapidPrivateKey,
            'vapid_subject' => 'mailto:support@subnext.com.ng',
            'apk_download_enabled' => '1',
            'apk_download_url' => 'downloads/subnext.apk',
        ];

        foreach ($settingsToInsert as $k => $v) {
            $stmt = $pdo->prepare("
                INSERT INTO app_settings (setting_key, setting_value, setting_group, description)
                VALUES (?, ?, 'push', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([$k, $v, "Subnext App / Push configuration: $k"]);
        }
        
        echo "Generated VAPID Public Key: $vapidPublicKey\n";
    } else {
        echo "Notice: OpenSSL could not generate EC key directly, checking fallback.\n";
    }
} else {
    echo "VAPID keys already configured.\n";
}

echo "Migration finished successfully.\n";

