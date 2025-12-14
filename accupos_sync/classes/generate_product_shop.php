<?php
/**
 * Генерация product_shop для всех товаров
 * PrestaShop 8 требует обязательное наличие записей в product_shop
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
    
    echo "=== ГЕНЕРАЦИЯ PRODUCT_SHOP ===\n\n";
    
    // Получаем ID магазина
    $shop = $conn->query("SELECT id_shop FROM {$prefix}shop LIMIT 1")->fetch_assoc();
    $id_shop = $shop['id_shop'];
    
    echo "ID магазина: $id_shop\n\n";
    
    // Очищаем product_shop
    $conn->query("TRUNCATE TABLE {$prefix}product_shop");
    echo "✓ product_shop очищена\n\n";
    
    // Генерируем записи для всех товаров
    echo "Генерация записей product_shop...\n";
    
    $sql = "
        INSERT INTO {$prefix}product_shop (
            id_product,
            id_shop,
            id_category_default,
            id_tax_rules_group,
            on_sale,
            online_only,
            ecotax,
            minimal_quantity,
            low_stock_threshold,
            low_stock_alert,
            price,
            wholesale_price,
            unity,
            unit_price_ratio,
            additional_shipping_cost,
            customizable,
            uploadable_files,
            text_fields,
            active,
            redirect_type,
            id_type_redirected,
            available_for_order,
            available_date,
            show_condition,
            condition,
            show_price,
            indexed,
            visibility,
            cache_default_attribute,
            advanced_stock_management,
            date_add,
            date_upd,
            pack_stock_type
        )
        SELECT 
            id_product,
            $id_shop,
            id_category_default,
            id_tax_rules_group,
            on_sale,
            online_only,
            ecotax,
            minimal_quantity,
            COALESCE(low_stock_threshold, NULL),
            COALESCE(low_stock_alert, 0),
            price,
            wholesale_price,
            unity,
            unit_price_ratio,
            additional_shipping_cost,
            customizable,
            uploadable_files,
            text_fields,
            active,
            redirect_type,
            id_type_redirected,
            available_for_order,
            IF(available_date = '0000-00-00', NULL, available_date),
            show_condition,
            `condition`,
            show_price,
            indexed,
            visibility,
            cache_default_attribute,
            advanced_stock_management,
            date_add,
            date_upd,
            pack_stock_type
        FROM {$prefix}product
    ";
    
    if ($conn->query($sql)) {
        $count = $conn->affected_rows;
        echo "✓ Создано записей: $count\n\n";
    } else {
        echo "✗ Ошибка: " . $conn->error . "\n\n";
        
        // Альтернативный метод - копируем поштучно
        echo "Пробуем альтернативный метод...\n";
        
        $products = $conn->query("SELECT id_product FROM {$prefix}product");
        $inserted = 0;
        
        while ($row = $products->fetch_assoc()) {
            $id_product = $row['id_product'];
            
            $insert = "
                INSERT IGNORE INTO {$prefix}product_shop (
                    id_product, id_shop, active, available_for_order, 
                    show_price, indexed, visibility, date_add, date_upd
                )
                VALUES (
                    $id_product, $id_shop, 1, 1, 
                    1, 1, 'both', NOW(), NOW()
                )
            ";
            
            if ($conn->query($insert)) {
                $inserted++;
            }
            
            if ($inserted % 100 == 0) {
                echo "  Обработано: $inserted товаров\r";
            }
        }
        
        echo "\n✓ Создано записей: $inserted\n\n";
    }
    
    // Статистика
    echo "=== ПРОВЕРКА ===\n";
    $result = $conn->query("
        SELECT 
            COUNT(p.id_product) as total_products,
            COUNT(ps.id_product) as products_in_shop,
            COUNT(ps.id_product) * 100 / COUNT(p.id_product) as percentage
        FROM {$prefix}product p
        LEFT JOIN {$prefix}product_shop ps ON p.id_product = ps.id_product AND ps.id_shop = $id_shop
    ");
    
    $stats = $result->fetch_assoc();
    echo "Всего товаров: {$stats['total_products']}\n";
    echo "В магазине: {$stats['products_in_shop']}\n";
    echo "Покрытие: " . round($stats['percentage'], 2) . "%\n\n";
    
    // Очищаем кэш
    echo "=== Очистка кэша ===\n";
    exec("rm -rf /var/www/dev-ps8-test.geek-shelter.com/var/cache/prod/*");
    echo "✓ Кэш очищен\n\n";
    
    $conn->close();
    echo "✅ Готово! Проверьте каталог в админке!\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}

