<?php
/**
 * Исправление остатков и проверка изображений
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
    
    echo "=== ПРОВЕРКА ОСТАТКОВ И ИЗОБРАЖЕНИЙ ===\n\n";
    
    // 1. Проверка остатков
    echo "1. Проверка stock_available...\n";
    
    $stats = $conn->query("
        SELECT 
            COUNT(DISTINCT p.id_product) as total_products,
            COUNT(DISTINCT sa.id_product) as products_with_stock,
            SUM(sa.quantity) as total_quantity
        FROM {$prefix}product p
        LEFT JOIN {$prefix}stock_available sa ON p.id_product = sa.id_product
    ")->fetch_assoc();
    
    echo "  Всего товаров: {$stats['total_products']}\n";
    echo "  С остатками: {$stats['products_with_stock']}\n";
    echo "  Общее количество: {$stats['total_quantity']}\n";
    
    if ($stats['products_with_stock'] < $stats['total_products']) {
        echo "  ⚠️ Не у всех товаров есть остатки!\n";
        echo "  Создаём недостающие записи...\n";
        
        // Создаём stock_available для товаров без остатков
        $conn->query("
            INSERT IGNORE INTO {$prefix}stock_available (id_product, id_product_attribute, id_shop, id_shop_group, quantity, depends_on_stock, out_of_stock)
            SELECT p.id_product, 0, 1, 0, 0, 0, 2
            FROM {$prefix}product p
            WHERE NOT EXISTS (
                SELECT 1 FROM {$prefix}stock_available sa 
                WHERE sa.id_product = p.id_product AND sa.id_product_attribute = 0
            )
        ");
        
        $added = $conn->affected_rows;
        echo "  ✓ Добавлено записей: $added\n";
    } else {
        echo "  ✓ Все товары имеют остатки\n";
    }
    
    // 2. Проверка изображений
    echo "\n2. Проверка изображений...\n";
    
    $images = $conn->query("
        SELECT 
            COUNT(*) as total_images,
            COUNT(DISTINCT id_product) as products_with_images
        FROM {$prefix}image
    ")->fetch_assoc();
    
    echo "  Записей изображений в БД: {$images['total_images']}\n";
    echo "  Товаров с изображениями: {$images['products_with_images']}\n";
    
    // Проверяем наличие файлов
    $imgDir = '/var/www/dev-ps8-test.geek-shelter.com/img/p';
    if (is_dir($imgDir)) {
        $fileCount = exec("find $imgDir -type f -name '*.jpg' | wc -l");
        echo "  Файлов изображений на диске: $fileCount\n";
        
        if ($fileCount == 0) {
            echo "  ⚠️ ФАЙЛЫ ИЗОБРАЖЕНИЙ ОТСУТСТВУЮТ!\n";
            echo "  Необходимо скопировать из production:\n";
            echo "  rsync -avz /var/www/dev.geek-shelter.com/img/p/ /var/www/dev-ps8-test.geek-shelter.com/img/p/\n";
        } else {
            echo "  ✓ Файлы изображений присутствуют\n";
        }
    }
    
    // 3. Проверка связи товар-изображение
    echo "\n3. Проверка связи товар-изображение...\n";
    
    $check = $conn->query("
        SELECT 
            COUNT(DISTINCT p.id_product) as total,
            COUNT(DISTINCT i.id_product) as with_images
        FROM {$prefix}product p
        LEFT JOIN {$prefix}image i ON p.id_product = i.id_product
        WHERE p.active = 1
    ")->fetch_assoc();
    
    echo "  Активных товаров: {$check['total']}\n";
    echo "  С изображениями: {$check['with_images']}\n";
    echo "  Без изображений: " . ($check['total'] - $check['with_images']) . "\n";
    
    // Статистика
    echo "\n=== ИТОГОВАЯ СТАТИСТИКА ===\n";
    $final = $conn->query("
        SELECT 
            'Товары' as Type, COUNT(*) as Count FROM {$prefix}product
        UNION ALL SELECT 'Stock_available', COUNT(*) FROM {$prefix}stock_available
        UNION ALL SELECT 'Изображения (БД)', COUNT(*) FROM {$prefix}image
        UNION ALL SELECT 'Товары с изображениями', COUNT(DISTINCT id_product) FROM {$prefix}image
    ");
    
    while ($row = $final->fetch_assoc()) {
        echo sprintf("  %-30s %s\n", $row['Type'] . ':', $row['Count']);
    }
    
    $conn->close();
    
    echo "\n✅ Проверка завершена!\n";
    echo "\n💡 Для копирования изображений выполните на сервере:\n";
    echo "rsync -avz /var/www/dev.geek-shelter.com/img/p/ /var/www/dev-ps8-test.geek-shelter.com/img/p/\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}

