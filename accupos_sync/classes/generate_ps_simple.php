<?php
/**
 * Простая генерация product_shop - только критичные поля
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
    
    echo "=== ГЕНЕРАЦИЯ PRODUCT_SHOP (простой метод) ===\n\n";
    
    $shop = $conn->query("SELECT id_shop FROM {$prefix}shop LIMIT 1")->fetch_assoc();
    $id_shop = $shop['id_shop'];
    echo "ID магазина: $id_shop\n\n";
    
    $conn->query("TRUNCATE TABLE {$prefix}product_shop");
    echo "✓ product_shop очищена\n";
    
    echo "Генерация записей...\n";
    
    $products = $conn->query("SELECT * FROM {$prefix}product");
    $inserted = 0;
    $errors = 0;
    
    while ($row = $products->fetch_assoc()) {
        $id_product = $row['id_product'];
        $id_category_default = $row['id_category_default'] ?? 2;
        $id_tax_rules_group = $row['id_tax_rules_group'] ?? 1;
        $price = $row['price'] ?? 0;
        $active = $row['active'] ?? 1;
        $date_add = $row['date_add'] ?? date('Y-m-d H:i:s');
        $date_upd = $row['date_upd'] ?? date('Y-m-d H:i:s');
        
        $sql = "INSERT INTO {$prefix}product_shop (
            id_product, id_shop, id_category_default, id_tax_rules_group,
            price, active, available_for_order, show_price, indexed, visibility,
            date_add, date_upd
        ) VALUES (
            $id_product, $id_shop, $id_category_default, $id_tax_rules_group,
            $price, $active, 1, 1, 1, 'both',
            '$date_add', '$date_upd'
        )";
        
        if ($conn->query($sql)) {
            $inserted++;
        } else {
            $errors++;
        }
        
        if ($inserted % 500 == 0) {
            echo "  Обработано: $inserted товаров...\r";
        }
    }
    
    echo "\n\n✓ Создано записей: $inserted\n";
    if ($errors > 0) {
        echo "⚠️ Ошибок: $errors\n";
    }
    
    // Статистика
    echo "\n=== ПРОВЕРКА ===\n";
    $stats = $conn->query("
        SELECT COUNT(*) as cnt FROM {$prefix}product_shop WHERE id_shop = $id_shop
    ")->fetch_assoc();
    
    echo "Записей в product_shop: {$stats['cnt']}\n\n";
    
    // Очистка кэша
    echo "=== Очистка кэша ===\n";
    exec("rm -rf /var/www/dev-ps8-test.geek-shelter.com/var/cache/prod/* 2>&1");
    echo "✓ Кэш очищен\n\n";
    
    $conn->close();
    echo "✅ Готово! Обновите страницу в админке (Ctrl+Shift+R)\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}

