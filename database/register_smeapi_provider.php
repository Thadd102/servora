<?php
require_once __DIR__ . '/../config/database.php';

try {
    $stmt = $pdo->prepare("SELECT id, name, slug, status FROM api_providers WHERE slug = 'smeapi' LIMIT 1");
    $stmt->execute();
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        echo "SMEAPI provider already exists in api_providers (ID: {$existing['id']}).\n";
    } else {
        $insert = $pdo->prepare("
            INSERT INTO api_providers (name, slug, base_url, status)
            VALUES ('SMEAPI', 'smeapi', 'https://smeapi.com.ng', 'active')
        ");
        $insert->execute();
        $newId = $pdo->lastInsertId();
        echo "SMEAPI provider successfully registered in api_providers with ID: {$newId}.\n";
    }

    // Also check if app_settings has smeapi keys initialized
    $settings = [
        'smeapi_base_url' => 'https://smeapi.com.ng',
        'smeapi_api_key' => '',
        'smeapi_status' => '1'
    ];

    foreach ($settings as $key => $val) {
        $check = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        $check->execute([$key]);
        if ($check->fetchColumn() === false) {
            $add = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)");
            $add->execute([$key, $val]);
            echo "Initialized app_setting: {$key}\n";
        }
    }

} catch (Throwable $e) {
    echo "Error registering SMEAPI provider: " . $e->getMessage() . "\n";
    exit(1);
}

