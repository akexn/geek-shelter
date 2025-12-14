<?php
/**
 * Исправление PS_SHOP_ENABLE для dev-ps8-test
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
    
    echo "=== Текущий статус ===\n";
    $result = $conn->query("SELECT name, value FROM {$prefix}configuration WHERE name = 'PS_SHOP_ENABLE'");
    if ($row = $result->fetch_assoc()) {
        echo "PS_SHOP_ENABLE = " . ($row['value'] ?? 'NULL') . "\n\n";
    }
    
    echo "=== Включаем магазин ===\n";
    $conn->query("UPDATE {$prefix}configuration SET value = '1' WHERE name = 'PS_SHOP_ENABLE'");
    
    if ($conn->affected_rows > 0) {
        echo "✅ PS_SHOP_ENABLE установлен в 1\n\n";
    } else {
        // Если записи нет, создаём
        $conn->query("INSERT INTO {$prefix}configuration (name, value, date_add, date_upd) VALUES ('PS_SHOP_ENABLE', '1', NOW(), NOW())");
        echo "✅ PS_SHOP_ENABLE создан со значением 1\n\n";
    }
    
    echo "=== Проверка структуры ps_shop ===\n";
    $result = $conn->query("DESCRIBE {$prefix}shop");
    echo "Колонки таблицы {$prefix}shop:\n";
    while ($row = $result->fetch_assoc()) {
        echo "  - {$row['Field']} ({$row['Type']})\n";
    }
    echo "\n";
    
    echo "=== Проверка магазина ===\n";
    $result = $conn->query("SELECT * FROM {$prefix}shop WHERE id_shop = 1");
    if ($row = $result->fetch_assoc()) {
        echo "Shop ID 1:\n";
        foreach ($row as $key => $value) {
            echo "  {$key} = " . ($value ?? 'NULL') . "\n";
        }
    }
    
    echo "\n=== Финальный статус ===\n";
    $result = $conn->query("SELECT name, value FROM {$prefix}configuration WHERE name IN ('PS_SHOP_ENABLE', 'PS_MAINTENANCE_IP')");
    while ($row = $result->fetch_assoc()) {
        echo "{$row['name']} = " . ($row['value'] ?? 'NULL') . "\n";
    }
    
    $conn->close();
    echo "\n✅ Готово!\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}

