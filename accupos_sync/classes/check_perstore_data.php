<?php
/**
 * Проверка данных модуля prestatillstockperstore
 */

$host = '127.0.0.1';
$user = 'impulse';
$password = '4667750Dima';
$prefix = '4667750Dima';
$dbTest = 'prestashop_dev_ps8_test';

try {
    $conn = new mysqli($host, $user, $password, $dbTest);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    echo "=== ПРОВЕРКА ДАННЫХ PRESTATILLSTOCKPERSTORE ===\n\n";
    
    // Проверяем таблицы модуля
    $tables = [
        'prestatill_stock_per_store' => 'Запасы по складам',
        'prestatill_stock_mvt_warehouse' => 'Движения склада',
        'prestatill_in_out_mvt_product' => 'Приход/Расход товаров',
        'prestatill_warehouse_product_location' => 'Местоположения товаров',
        'prestatill_warehouse_order_detail' => 'Детали складских заказов',
        'store' => 'Склады (stores)',
    ];
    
    foreach ($tables as $table => $description) {
        $fullTable = "{$prefix}{$table}";
        $result = $conn->query("SELECT COUNT(*) as cnt FROM $fullTable");
        
        if ($result) {
            $row = $result->fetch_assoc();
            $status = $row['cnt'] > 0 ? '✓' : '⚠️';
            echo sprintf("  %s %-40s %s записей\n", $status, $description . ':', $row['cnt']);
        } else {
            echo "  ✗ $description - таблица не существует\n";
        }
    }
    
    // Проверяем связь товары-склады
    echo "\n=== СВЯЗЬ ТОВАРЫ-СКЛАДЫ ===\n";
    
    $result = $conn->query("
        SELECT 
            s.id_store,
            sl.name as store_name,
            COUNT(sps.id_product) as products_count,
            SUM(sps.quantity) as total_quantity
        FROM {$prefix}store s
        LEFT JOIN {$prefix}store_lang sl ON s.id_store = sl.id_store AND sl.id_lang = 1
        LEFT JOIN {$prefix}prestatill_stock_per_store sps ON s.id_store = sps.id_warehouse
        GROUP BY s.id_store, sl.name
    ");
    
    while ($row = $result->fetch_assoc()) {
        echo sprintf("  Склад %d (%s): %d товаров, %d единиц\n", 
            $row['id_store'], 
            $row['store_name'], 
            $row['products_count'], 
            $row['total_quantity']
        );
    }
    
    // Проверяем движения
    echo "\n=== ДВИЖЕНИЯ ТОВАРОВ ===\n";
    
    $mvt = $conn->query("
        SELECT 
            COUNT(*) as total,
            COUNT(DISTINCT id_product) as unique_products,
            SUM(physical_quantity) as total_quantity
        FROM {$prefix}prestatill_stock_mvt_warehouse
    ")->fetch_assoc();
    
    echo "  Всего движений: {$mvt['total']}\n";
    echo "  Уникальных товаров: {$mvt['unique_products']}\n";
    echo "  Общее количество: {$mvt['total_quantity']}\n";
    
    // Проверяем настройки модуля
    echo "\n=== НАСТРОЙКИ МОДУЛЯ ===\n";
    
    $result = $conn->query("
        SELECT name, value 
        FROM {$prefix}configuration 
        WHERE name LIKE '%PRESTATILL%' OR name LIKE '%TILL%'
        ORDER BY name
    ");
    
    while ($row = $result->fetch_assoc()) {
        echo "  {$row['name']} = {$row['value']}\n";
    }
    
    $conn->close();
    echo "\n✅ Проверка завершена!\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}

