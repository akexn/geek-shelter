<?php
/**
 * Копирование настроек prestatillstockperstore из production в test
 */

$host = '127.0.0.1';
$user = 'impulse';
$password = '4667750Dima';
$prefix = '4667750Dima';

$dbProd = 'prestashop';  // Production БД
$dbTest = 'prestashop_dev_ps8_test';  // Test БД

try {
    $conn = new mysqli($host, $user, $password);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    echo "=== Копирование настроек prestatillstockperstore ===\n\n";
    
    // 1. Копируем настройки модуля из ps_configuration
    echo "1. Настройки модуля (configuration):\n";
    $result = $conn->query("SELECT name, value FROM $dbProd.{$prefix}configuration WHERE name LIKE '%PERSTORE%' OR name LIKE '%TILL%'");
    
    $configs = [];
    while ($row = $result->fetch_assoc()) {
        $configs[] = $row;
        echo "  - {$row['name']}\n";
    }
    
    if (count($configs) > 0) {
        foreach ($configs as $config) {
            $name = $conn->real_escape_string($config['name']);
            $value = $conn->real_escape_string($config['value']);
            
            // Проверяем существование
            $check = $conn->query("SELECT id_configuration FROM $dbTest.{$prefix}configuration WHERE name = '$name'");
            
            if ($check->num_rows > 0) {
                // UPDATE
                $conn->query("UPDATE $dbTest.{$prefix}configuration SET value = '$value' WHERE name = '$name'");
                echo "  ✓ Обновлено: $name\n";
            } else {
                // INSERT
                $conn->query("INSERT INTO $dbTest.{$prefix}configuration (name, value, date_add, date_upd) VALUES ('$name', '$value', NOW(), NOW())");
                echo "  ✓ Добавлено: $name\n";
            }
        }
    } else {
        echo "  ⚠️ Настройки не найдены в production\n";
    }
    
    echo "\n2. Таблицы модуля:\n";
    
    // Получаем список таблиц модуля
    $tables = [
        'prestashop_order_store',
        'till_store_products',
        'till_stores',
        'till_stock_per_store'
    ];
    
    foreach ($tables as $table) {
        $fullTable = "{$prefix}{$table}";
        
        // Проверяем существование таблицы в production
        $checkProd = $conn->query("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = '$dbProd' AND table_name = '$fullTable'");
        $rowProd = $checkProd->fetch_assoc();
        
        if ($rowProd['cnt'] > 0) {
            // Проверяем существование в test
            $checkTest = $conn->query("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = '$dbTest' AND table_name = '$fullTable'");
            $rowTest = $checkTest->fetch_assoc();
            
            if ($rowTest['cnt'] > 0) {
                // Очищаем test таблицу
                $conn->query("TRUNCATE TABLE $dbTest.$fullTable");
                
                // Копируем данные
                $conn->query("INSERT INTO $dbTest.$fullTable SELECT * FROM $dbProd.$fullTable");
                
                $count = $conn->query("SELECT COUNT(*) as cnt FROM $dbTest.$fullTable")->fetch_assoc()['cnt'];
                echo "  ✓ $table - скопировано $count записей\n";
            } else {
                echo "  ⚠️ $table - таблица не существует в test БД (нужно создать)\n";
            }
        } else {
            echo "  ℹ️ $table - таблица не существует в production\n";
        }
    }
    
    echo "\n3. Проверка скопированных данных:\n";
    
    // Проверяем stores
    $result = $conn->query("SELECT * FROM $dbTest.{$prefix}till_stores");
    if ($result && $result->num_rows > 0) {
        echo "  Склады (till_stores):\n";
        while ($row = $result->fetch_assoc()) {
            echo "    - ID: {$row['id_store']}, Name: {$row['name']}, Active: {$row['active']}\n";
        }
    }
    
    // Проверяем связь заказов
    $result = $conn->query("SELECT COUNT(*) as cnt FROM $dbTest.{$prefix}prestashop_order_store");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "  Связь заказов → склады: {$row['cnt']} записей\n";
    }
    
    $conn->close();
    echo "\n✅ Копирование завершено!\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}

