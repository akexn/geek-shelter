<?php
/**
 * Миграция товаров из PrestaShop 1.7 в PrestaShop 8
 * Исправленная версия с экранированием ключевых слов
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
    
    $conn->query("SET FOREIGN_KEY_CHECKS=0");
    $conn->query("SET SQL_MODE=''");
    
    // 1. Простое копирование с заменой дат
    echo "1. Копирование товаров (product)...\n";
    $conn->query("TRUNCATE TABLE $dbTest.{$prefix}product");
    
    $result = $conn->query("
        INSERT INTO $dbTest.{$prefix}product 
        SELECT * FROM $dbProd.{$prefix}product
    ");
    
    if ($result) {
        // Исправляем даты
        $conn->query("UPDATE $dbTest.{$prefix}product SET available_date = NULL WHERE available_date = '0000-00-00'");
        
        $count = $conn->query("SELECT COUNT(*) as cnt FROM $dbTest.{$prefix}product")->fetch_assoc()['cnt'];
        echo "  ✓ Скопировано товаров: $count\n";
    } else {
        echo "  ✗ Ошибка: " . $conn->error . "\n";
        echo "  Пробуем альтернативный метод...\n";
        
        // Альтернатива - копируем через mysqldump
        exec("mysqldump -h127.0.0.1 -uimpulse -p4667750Dima --no-create-info $dbProd {$prefix}product 2>&1 | mysql -h127.0.0.1 -uimpulse -p4667750Dima $dbTest", $output, $ret);
        
        if ($ret == 0) {
            $count = $conn->query("SELECT COUNT(*) as cnt FROM $dbTest.{$prefix}product")->fetch_assoc()['cnt'];
            echo "  ✓ Скопировано через dump: $count\n";
            $conn->query("UPDATE $dbTest.{$prefix}product SET available_date = NULL WHERE available_date = '0000-00-00'");
        }
    }
    
    // 2. product_shop
    echo "\n2. Копирование product_shop...\n";
    $conn->query("TRUNCATE TABLE $dbTest.{$prefix}product_shop");
    
    exec("mysqldump -h127.0.0.1 -uimpulse -p4667750Dima --no-create-info $dbProd {$prefix}product_shop 2>&1 | mysql -h127.0.0.1 -uimpulse -p4667750Dima $dbTest", $output, $ret);
    
    if ($ret == 0) {
        $conn->query("UPDATE $dbTest.{$prefix}product_shop SET available_date = NULL WHERE available_date = '0000-00-00'");
        $count = $conn->query("SELECT COUNT(*) as cnt FROM $dbTest.{$prefix}product_shop")->fetch_assoc()['cnt'];
        echo "  ✓ Скопировано: $count\n";
    } else {
        echo "  ✗ Не удалось скопировать\n";
    }
    
    // 3. product_attribute  
    echo "\n3. Копирование product_attribute...\n";
    $conn->query("TRUNCATE TABLE $dbTest.{$prefix}product_attribute");
    
    exec("mysqldump -h127.0.0.1 -uimpulse -p4667750Dima --no-create-info $dbProd {$prefix}product_attribute 2>&1 | mysql -h127.0.0.1 -uimpulse -p4667750Dima $dbTest", $output, $ret);
    
    if ($ret == 0) {
        $count = $conn->query("SELECT COUNT(*) as cnt FROM $dbTest.{$prefix}product_attribute")->fetch_assoc()['cnt'];
        echo "  ✓ Скопировано: $count\n";
    }
    
    // 4. specific_price
    echo "\n4. Копирование specific_price...\n";
    $conn->query("TRUNCATE TABLE $dbTest.{$prefix}specific_price");
    
    $conn->query("
        INSERT INTO $dbTest.{$prefix}specific_price
        SELECT * FROM $dbProd.{$prefix}specific_price
    ");
    
    $conn->query("UPDATE $dbTest.{$prefix}specific_price SET `from` = '1970-01-01 00:00:00' WHERE `from` = '0000-00-00 00:00:00'");
    $conn->query("UPDATE $dbTest.{$prefix}specific_price SET `to` = '2038-01-01 00:00:00' WHERE `to` = '0000-00-00 00:00:00'");
    
    $count = $conn->query("SELECT COUNT(*) as cnt FROM $dbTest.{$prefix}specific_price")->fetch_assoc()['cnt'];
    echo "  ✓ Скопировано: $count\n";
    
    $conn->query("SET FOREIGN_KEY_CHECKS=1");
    
    // Статистика
    echo "\n=== ИТОГОВАЯ СТАТИСТИКА ===\n";
    $stats = $conn->query("
        SELECT 'Товары' as Type, COUNT(*) as Count FROM $dbTest.{$prefix}product
        UNION ALL SELECT 'Товары (shop)', COUNT(*) FROM $dbTest.{$prefix}product_shop
        UNION ALL SELECT 'Категории', COUNT(*) FROM $dbTest.{$prefix}category
        UNION ALL SELECT 'Производители', COUNT(*) FROM $dbTest.{$prefix}manufacturer
        UNION ALL SELECT 'Изображения', COUNT(*) FROM $dbTest.{$prefix}image
        UNION ALL SELECT 'Комбинации', COUNT(*) FROM $dbTest.{$prefix}product_attribute
        UNION ALL SELECT 'Наличие на складе', COUNT(*) FROM $dbTest.{$prefix}stock_available
        UNION ALL SELECT 'Спец. цены', COUNT(*) FROM $dbTest.{$prefix}specific_price
    ");
    
    while ($row = $stats->fetch_assoc()) {
        echo sprintf("  %-25s %s\n", $row['Type'] . ':', $row['Count']);
    }
    
    $conn->close();
    echo "\n✅ Миграция товаров завершена!\n";
    echo "\n💡 Проверьте каталог в админке!\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}

