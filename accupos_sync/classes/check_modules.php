<?php
/**
 * Проверка модулей PrestaShop
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
    
    echo "=== Установленные модули ===\n\n";
    
    $result = $conn->query("SELECT name, active, version FROM {$prefix}module ORDER BY name");
    $total = 0;
    $active = 0;
    $critical = ['cardcom', 'prestatillstockperstore', 'ps_checkout', 'ps_emailsubscription'];
    
    $modules = [];
    while ($row = $result->fetch_assoc()) {
        $modules[] = $row;
        $total++;
        if ($row['active'] == 1) {
            $active++;
        }
    }
    
    echo "Всего модулей: $total\n";
    echo "Активных: $active\n\n";
    
    echo "=== Критичные модули ===\n";
    foreach ($critical as $moduleName) {
        $found = false;
        foreach ($modules as $module) {
            if ($module['name'] === $moduleName) {
                $status = $module['active'] == 1 ? '✅ Активен' : '⚠️ Отключён';
                echo "$moduleName (v{$module['version']}) - $status\n";
                $found = true;
                break;
            }
        }
        if (!$found) {
            echo "$moduleName - ❌ НЕ УСТАНОВЛЕН\n";
        }
    }
    
    echo "\n=== Все модули ===\n";
    foreach ($modules as $module) {
        $status = $module['active'] == 1 ? '✓' : '✗';
        echo "[$status] {$module['name']} (v{$module['version']})\n";
    }
    
    echo "\n=== Проверка директорий модулей ===\n";
    $modulesDir = '/var/www/dev-ps8-test.geek-shelter.com/modules';
    
    foreach ($critical as $moduleName) {
        $path = "$modulesDir/$moduleName";
        if (is_dir($path)) {
            echo "✓ $moduleName - папка существует\n";
        } else {
            echo "✗ $moduleName - папка НЕ найдена\n";
        }
    }
    
    $conn->close();
    echo "\n✅ Проверка завершена!\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}

