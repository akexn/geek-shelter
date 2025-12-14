<?php
/**
 * Миграция товаров из PrestaShop 1.7 в PrestaShop 8
 * Учитывает изменения структуры таблиц
 */

$host = '127.0.0.1';
$user = 'impulse';
$password = '4667750Dima';
$prefix = '4667750Dima';
$dbProd = 'prestashop';
$dbTest = 'prestashop_dev_ps8_test';

try {
    $conn = new mysqli($host, $user, $password);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    echo "=== МИГРАЦИЯ ТОВАРОВ PS 1.7 → PS 8 ===\n\n";
    
    // 1. Копируем товары с исправлением дат
    echo "1. Копирование товаров (product)...\n";
    $conn->query("SET FOREIGN_KEY_CHECKS=0");
    $conn->query("TRUNCATE TABLE $dbTest.{$prefix}product");
    
    // Получаем структуру целевой таблицы
    $columns = [];
    $result = $conn->query("DESCRIBE $dbTest.{$prefix}product");
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
    
    // Копируем с исправлением дат
    $sql = "INSERT INTO $dbTest.{$prefix}product 
            SELECT " . implode(', ', array_map(function($col) {
                if ($col == 'available_date') {
                    return "IF(available_date = '0000-00-00', NULL, available_date) as available_date";
                }
                return $col;
            }, $columns)) . "
            FROM $dbProd.{$prefix}product";
    
    if ($conn->query($sql)) {
        $count = $conn->query("SELECT COUNT(*) as cnt FROM $dbTest.{$prefix}product")->fetch_assoc()['cnt'];
        echo "  ✓ Скопировано товаров: $count\n";
    } else {
        echo "  ✗ Ошибка: " . $conn->error . "\n";
    }
    
    // 2. Копируем product_shop (сложная структура)
    echo "\n2. Копирование product_shop...\n";
    $conn->query("TRUNCATE TABLE $dbTest.{$prefix}product_shop");
    
    // Получаем общие колонки
    $prodCols = [];
    $result = $conn->query("DESCRIBE $dbProd.{$prefix}product_shop");
    while ($row = $result->fetch_assoc()) {
        $prodCols[] = $row['Field'];
    }
    
    $testCols = [];
    $result = $conn->query("DESCRIBE $dbTest.{$prefix}product_shop");
    while ($row = $result->fetch_assoc()) {
        $testCols[] = $row['Field'];
    }
    
    $commonCols = array_intersect($prodCols, $testCols);
    
    $sql = "INSERT INTO $dbTest.{$prefix}product_shop (" . implode(', ', $commonCols) . ")
            SELECT " . implode(', ', array_map(function($col) {
                if ($col == 'available_date') {
                    return "IF(available_date = '0000-00-00', NULL, available_date)";
                }
                return $col;
            }, $commonCols)) . "
            FROM $dbProd.{$prefix}product_shop";
    
    if ($conn->query($sql)) {
        $count = $conn->query("SELECT COUNT(*) as cnt FROM $dbTest.{$prefix}product_shop")->fetch_assoc()['cnt'];
        echo "  ✓ Скопировано product_shop: $count\n";
    } else {
        echo "  ✗ Ошибка: " . $conn->error . "\n";
    }
    
    // 3. Копируем product_attribute
    echo "\n3. Копирование product_attribute...\n";
    $conn->query("TRUNCATE TABLE $dbTest.{$prefix}product_attribute");
    
    // Получаем общие колонки
    $prodAttrCols = [];
    $result = $conn->query("DESCRIBE $dbProd.{$prefix}product_attribute");
    while ($row = $result->fetch_assoc()) {
        $prodAttrCols[] = $row['Field'];
    }
    
    $testAttrCols = [];
    $result = $conn->query("DESCRIBE $dbTest.{$prefix}product_attribute");
    while ($row = $result->fetch_assoc()) {
        $testAttrCols[] = $row['Field'];
    }
    
    $commonAttrCols = array_intersect($prodAttrCols, $testAttrCols);
    
    if (count($commonAttrCols) > 0) {
        $sql = "INSERT INTO $dbTest.{$prefix}product_attribute (" . implode(', ', $commonAttrCols) . ")
                SELECT " . implode(', ', $commonAttrCols) . "
                FROM $dbProd.{$prefix}product_attribute";
        
        if ($conn->query($sql)) {
            $count = $conn->query("SELECT COUNT(*) as cnt FROM $dbTest.{$prefix}product_attribute")->fetch_assoc()['cnt'];
            echo "  ✓ Скопировано комбинаций: $count\n";
        } else {
            echo "  ✗ Ошибка: " . $conn->error . "\n";
        }
    }
    
    // 4. Копируем specific_price с исправлением дат
    echo "\n4. Копирование specific_price...\n";
    $conn->query("TRUNCATE TABLE $dbTest.{$prefix}specific_price");
    
    $sql = "INSERT INTO $dbTest.{$prefix}specific_price
            SELECT 
                id_specific_price,
                id_specific_price_rule,
                id_cart,
                id_product,
                id_shop,
                id_shop_group,
                id_currency,
                id_country,
                id_group,
                id_customer,
                id_product_attribute,
                price,
                from_quantity,
                reduction,
                reduction_tax,
                reduction_type,
                IF(`from` = '0000-00-00 00:00:00', '1970-01-01 00:00:00', `from`) as `from`,
                IF(`to` = '0000-00-00 00:00:00', '2038-01-01 00:00:00', `to`) as `to`
            FROM $dbProd.{$prefix}specific_price";
    
    if ($conn->query($sql)) {
        $count = $conn->query("SELECT COUNT(*) as cnt FROM $dbTest.{$prefix}specific_price")->fetch_assoc()['cnt'];
        echo "  ✓ Скопировано спец. цен: $count\n";
    } else {
        echo "  ✗ Ошибка: " . $conn->error . "\n";
    }
    
    $conn->query("SET FOREIGN_KEY_CHECKS=1");
    
    // Статистика
    echo "\n=== ИТОГОВАЯ СТАТИСТИКА ===\n";
    $stats = $conn->query("
        SELECT 'Товары' as Type, COUNT(*) as Count FROM $dbTest.{$prefix}product
        UNION ALL SELECT 'Категории', COUNT(*) FROM $dbTest.{$prefix}category
        UNION ALL SELECT 'Производители', COUNT(*) FROM $dbTest.{$prefix}manufacturer
        UNION ALL SELECT 'Изображения', COUNT(*) FROM $dbTest.{$prefix}image
        UNION ALL SELECT 'Комбинации', COUNT(*) FROM $dbTest.{$prefix}product_attribute
        UNION ALL SELECT 'Наличие на складе', COUNT(*) FROM $dbTest.{$prefix}stock_available
    ");
    
    while ($row = $stats->fetch_assoc()) {
        echo sprintf("  %-20s %s\n", $row['Type'] . ':', $row['Count']);
    }
    
    $conn->close();
    echo "\n✅ Миграция товаров завершена!\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}

