<?php
/**
 * Исправление redirect loop для PrestaShop
 */

$host = '127.0.0.1';
$user = 'impulse';
$password = '4667750Dima';
$database = 'prestashop_dev_ps8_test';
$prefix = '4667750Dima';

try {
    $conn = new mysqli($host, $user, $password, $database);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    echo "=== Текущие настройки SSL/Domain ===\n\n";
    
    $result = $conn->query("SELECT name, value FROM {$prefix}configuration WHERE name IN ('PS_SSL_ENABLED', 'PS_SSL_ENABLED_EVERYWHERE', 'PS_SHOP_DOMAIN', 'PS_SHOP_DOMAIN_SSL')");
    while ($row = $result->fetch_assoc()) {
        echo "{$row['name']} = {$row['value']}\n";
    }
    
    echo "\n=== shop_url ===\n";
    $result = $conn->query("SELECT * FROM {$prefix}shop_url WHERE id_shop = 1");
    while ($row = $result->fetch_assoc()) {
        echo "domain = {$row['domain']}\n";
        echo "domain_ssl = {$row['domain_ssl']}\n";
        echo "physical_uri = {$row['physical_uri']}\n";
        echo "virtual_uri = {$row['virtual_uri']}\n";
    }
    
    echo "\n=== ИСПРАВЛЕНИЕ ===\n";
    
    // Включаем SSL везде
    $conn->query("UPDATE {$prefix}configuration SET value = '1' WHERE name = 'PS_SSL_ENABLED'");
    $conn->query("UPDATE {$prefix}configuration SET value = '1' WHERE name = 'PS_SSL_ENABLED_EVERYWHERE'");
    
    // Устанавливаем правильные домены
    $conn->query("UPDATE {$prefix}configuration SET value = 'dev-ps8-test.geek-shelter.com' WHERE name = 'PS_SHOP_DOMAIN'");
    $conn->query("UPDATE {$prefix}configuration SET value = 'dev-ps8-test.geek-shelter.com' WHERE name = 'PS_SHOP_DOMAIN_SSL'");
    
    // Обновляем shop_url
    $conn->query("UPDATE {$prefix}shop_url SET domain = 'dev-ps8-test.geek-shelter.com', domain_ssl = 'dev-ps8-test.geek-shelter.com' WHERE id_shop = 1");
    
    echo "✓ SSL enabled everywhere\n";
    echo "✓ Domains updated\n\n";
    
    echo "=== Очистка кэша ===\n";
    exec("rm -rf /var/www/dev-ps8-test.geek-shelter.com/var/cache/prod/* 2>&1", $output);
    echo "✓ Cache cleared\n\n";
    
    echo "=== Финальные настройки ===\n";
    $result = $conn->query("SELECT name, value FROM {$prefix}configuration WHERE name IN ('PS_SSL_ENABLED', 'PS_SSL_ENABLED_EVERYWHERE', 'PS_SHOP_DOMAIN', 'PS_SHOP_DOMAIN_SSL')");
    while ($row = $result->fetch_assoc()) {
        echo "{$row['name']} = {$row['value']}\n";
    }
    
    $conn->close();
    echo "\n✅ Готово! Обновите страницу (Ctrl+Shift+R)\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}

