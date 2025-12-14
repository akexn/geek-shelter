<?php
/**
 * Докопирование всех данных товаров
 * Цены, описания, количества, характеристики
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
    
    echo "=== ДОКОПИРОВАНИЕ ДАННЫХ ТОВАРОВ ===\n\n";
    
    $conn->query("SET FOREIGN_KEY_CHECKS=0");
    $conn->query("SET SQL_MODE=''");
    
    $id_shop = 1;
    
    // 1. Обновляем product_shop с данными из production
    echo "1. Обновление product_shop (цены, доступность)...\n";
    
    $result = $conn->query("
        SELECT ps.*, p.price 
        FROM $dbProd.{$prefix}product_shop ps
        JOIN $dbProd.{$prefix}product p ON ps.id_product = p.id_product
        WHERE ps.id_shop = $id_shop
    ");
    
    $updated = 0;
    while ($row = $result->fetch_assoc()) {
        $id_product = $row['id_product'];
        $price = $row['price'] ?? 0;
        $wholesale_price = $row['wholesale_price'] ?? 0;
        $unity = $conn->real_escape_string($row['unity'] ?? '');
        $unit_price_ratio = $row['unit_price_ratio'] ?? 0;
        $ecotax = $row['ecotax'] ?? 0;
        $minimal_quantity = $row['minimal_quantity'] ?? 1;
        $additional_shipping_cost = $row['additional_shipping_cost'] ?? 0;
        $available_for_order = $row['available_for_order'] ?? 1;
        $show_price = $row['show_price'] ?? 1;
        $online_only = $row['online_only'] ?? 0;
        $on_sale = $row['on_sale'] ?? 0;
        
        $conn->query("
            UPDATE $dbTest.{$prefix}product_shop SET
                price = $price,
                wholesale_price = $wholesale_price,
                unity = '$unity',
                unit_price_ratio = $unit_price_ratio,
                ecotax = $ecotax,
                minimal_quantity = $minimal_quantity,
                additional_shipping_cost = $additional_shipping_cost,
                available_for_order = $available_for_order,
                show_price = $show_price,
                online_only = $online_only,
                on_sale = $on_sale
            WHERE id_product = $id_product AND id_shop = $id_shop
        ");
        
        $updated++;
        if ($updated % 500 == 0) {
            echo "  Обновлено: $updated товаров...\r";
        }
    }
    echo "\n  ✓ Обновлено цен и доступности: $updated\n\n";
    
    // 2. Копируем описания (product_lang)
    echo "2. Копирование описаний (product_lang)...\n";
    $conn->query("TRUNCATE TABLE $dbTest.{$prefix}product_lang");
    
    $result = $conn->query("
        INSERT INTO $dbTest.{$prefix}product_lang
        SELECT * FROM $dbProd.{$prefix}product_lang
    ");
    
    $count = $conn->query("SELECT COUNT(*) as cnt FROM $dbTest.{$prefix}product_lang")->fetch_assoc()['cnt'];
    echo "  ✓ Скопировано описаний: $count\n\n";
    
    // 3. Копируем количества (stock_available)
    echo "3. Копирование количеств (stock_available)...\n";
    $conn->query("TRUNCATE TABLE $dbTest.{$prefix}stock_available");
    
    $result = $conn->query("
        INSERT INTO $dbTest.{$prefix}stock_available
        SELECT * FROM $dbProd.{$prefix}stock_available
    ");
    
    $count = $conn->query("SELECT COUNT(*) as cnt FROM $dbTest.{$prefix}stock_available")->fetch_assoc()['cnt'];
    echo "  ✓ Скопировано остатков: $count\n\n";
    
    // 4. Копируем характеристики (feature_product, feature_value_lang)
    echo "4. Копирование характеристик...\n";
    
    // feature
    $conn->query("TRUNCATE TABLE $dbTest.{$prefix}feature");
    $conn->query("INSERT IGNORE INTO $dbTest.{$prefix}feature SELECT * FROM $dbProd.{$prefix}feature");
    
    // feature_lang
    $conn->query("TRUNCATE TABLE $dbTest.{$prefix}feature_lang");
    $conn->query("INSERT IGNORE INTO $dbTest.{$prefix}feature_lang SELECT * FROM $dbProd.{$prefix}feature_lang");
    
    // feature_value
    $conn->query("TRUNCATE TABLE $dbTest.{$prefix}feature_value");
    $conn->query("INSERT IGNORE INTO $dbTest.{$prefix}feature_value SELECT * FROM $dbProd.{$prefix}feature_value");
    
    // feature_value_lang
    $conn->query("TRUNCATE TABLE $dbTest.{$prefix}feature_value_lang");
    $conn->query("INSERT IGNORE INTO $dbTest.{$prefix}feature_value_lang SELECT * FROM $dbProd.{$prefix}feature_value_lang");
    
    // feature_product
    $conn->query("TRUNCATE TABLE $dbTest.{$prefix}feature_product");
    $conn->query("INSERT IGNORE INTO $dbTest.{$prefix}feature_product SELECT * FROM $dbProd.{$prefix}feature_product");
    
    $count = $conn->query("SELECT COUNT(*) as cnt FROM $dbTest.{$prefix}feature_product")->fetch_assoc()['cnt'];
    echo "  ✓ Скопировано характеристик товаров: $count\n\n";
    
    // 5. Копируем комбинации (product_attribute + связанные)
    echo "5. Копирование комбинаций...\n";
    
    $conn->query("TRUNCATE TABLE $dbTest.{$prefix}product_attribute");
    $conn->query("INSERT IGNORE INTO $dbTest.{$prefix}product_attribute SELECT * FROM $dbProd.{$prefix}product_attribute");
    
    $conn->query("TRUNCATE TABLE $dbTest.{$prefix}product_attribute_shop");
    $conn->query("INSERT IGNORE INTO $dbTest.{$prefix}product_attribute_shop SELECT * FROM $dbProd.{$prefix}product_attribute_shop");
    
    $conn->query("TRUNCATE TABLE $dbTest.{$prefix}product_attribute_combination");
    $conn->query("INSERT IGNORE INTO $dbTest.{$prefix}product_attribute_combination SELECT * FROM $dbProd.{$prefix}product_attribute_combination");
    
    $conn->query("TRUNCATE TABLE $dbTest.{$prefix}product_attribute_image");
    $conn->query("INSERT IGNORE INTO $dbTest.{$prefix}product_attribute_image SELECT * FROM $dbProd.{$prefix}product_attribute_image");
    
    $count = $conn->query("SELECT COUNT(*) as cnt FROM $dbTest.{$prefix}product_attribute")->fetch_assoc()['cnt'];
    echo "  ✓ Скопировано комбинаций: $count\n\n";
    
    // 6. Обновляем основную таблицу product с ценами
    echo "6. Обновление цен в таблице product...\n";
    
    $result = $conn->query("SELECT id_product, price FROM $dbProd.{$prefix}product");
    $updated = 0;
    
    while ($row = $result->fetch_assoc()) {
        $conn->query("
            UPDATE $dbTest.{$prefix}product 
            SET price = {$row['price']} 
            WHERE id_product = {$row['id_product']}
        ");
        $updated++;
    }
    echo "  ✓ Обновлено цен: $updated\n\n";
    
    $conn->query("SET FOREIGN_KEY_CHECKS=1");
    
    // Статистика
    echo "=== ФИНАЛЬНАЯ СТАТИСТИКА ===\n";
    $stats = $conn->query("
        SELECT 'Товары' as Type, COUNT(*) as Count FROM $dbTest.{$prefix}product
        UNION ALL SELECT 'Описания', COUNT(*) FROM $dbTest.{$prefix}product_lang
        UNION ALL SELECT 'Цены (product_shop)', COUNT(*) FROM $dbTest.{$prefix}product_shop
        UNION ALL SELECT 'Остатки', COUNT(*) FROM $dbTest.{$prefix}stock_available
        UNION ALL SELECT 'Характеристики', COUNT(*) FROM $dbTest.{$prefix}feature_product
        UNION ALL SELECT 'Комбинации', COUNT(*) FROM $dbTest.{$prefix}product_attribute
        UNION ALL SELECT 'Изображения', COUNT(*) FROM $dbTest.{$prefix}image
    ");
    
    while ($row = $stats->fetch_assoc()) {
        echo sprintf("  %-25s %s\n", $row['Type'] . ':', $row['Count']);
    }
    
    // Очистка кэша
    echo "\n=== Очистка кэша ===\n";
    exec("rm -rf /var/www/dev-ps8-test.geek-shelter.com/var/cache/prod/* 2>&1");
    echo "✓ Кэш очищен\n\n";
    
    $conn->close();
    echo "✅ Готово! Обновите админку (Ctrl+Shift+R) и проверьте товары!\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}

