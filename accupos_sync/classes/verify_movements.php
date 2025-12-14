<?php
require_once('/var/www/dev.geek-shelter.com/config/config.inc.php');

$db = Db::getInstance();

echo "=== ПРОВЕРКА ДВИЖЕНИЙ ПОСЛЕ ИНТЕГРАЦИИ ===\n\n";

// 1. Движения за сегодня
$mvtCount = $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'stock_mvt WHERE DATE(date_add) = CURDATE()');
echo "1. Движений в ps_stock_mvt за сегодня: $mvtCount\n";

if ($mvtCount > 0) {
    // 2. Последние движения
    echo "\n2. Последние 10 движений:\n";
    $movements = $db->executeS('
        SELECT 
            sm.id_stock_mvt,
            sm.physical_quantity,
            sm.sign,
            sm.employee_firstname,
            sm.employee_lastname,
            sm.date_add,
            sa.id_product,
            pl.name as product_name
        FROM ' . _DB_PREFIX_ . 'stock_mvt sm
        INNER JOIN ' . _DB_PREFIX_ . 'stock_available sa ON sm.id_stock = sa.id_stock_available
        LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl ON sa.id_product = pl.id_product AND pl.id_lang = 1
        WHERE DATE(sm.date_add) = CURDATE()
        ORDER BY sm.date_add DESC
        LIMIT 10
    ');
    
    foreach ($movements as $mvt) {
        $sign = $mvt['sign'] == -1 ? '🔴 Списание' : '🟢 Приход';
        $qty = abs($mvt['physical_quantity']);
        $employee = $mvt['employee_firstname'] ? "{$mvt['employee_firstname']} {$mvt['employee_lastname']}" : 'N/A';
        
        echo "   [{$mvt['date_add']}] {$sign}\n";
        echo "      ID движения: {$mvt['id_stock_mvt']}\n";
        echo "      Товар: {$mvt['product_name']} (ID: {$mvt['id_product']})\n";
        echo "      Количество: {$qty}\n";
        echo "      Сотрудник: {$employee}\n\n";
    }
    
    // 3. Привязка к складам
    echo "3. Привязка движений к складам:\n";
    $warehouseLinks = $db->executeS('
        SELECT 
            smw.id_stock_mvt,
            smw.id_warehouse,
            w.name as warehouse_name,
            sm.date_add
        FROM ' . _DB_PREFIX_ . 'prestatill_stock_mvt_warehouse smw
        INNER JOIN ' . _DB_PREFIX_ . 'warehouse w ON smw.id_warehouse = w.id_warehouse
        INNER JOIN ' . _DB_PREFIX_ . 'stock_mvt sm ON smw.id_stock_mvt = sm.id_stock_mvt
        WHERE DATE(sm.date_add) = CURDATE()
        ORDER BY sm.date_add DESC
        LIMIT 10
    ');
    
    if (empty($warehouseLinks)) {
        echo "   ❌ Привязок к складам НЕТ!\n";
        echo "   Проверьте логи на сообщения 'movement not found'\n";
    } else {
        echo "   ✅ Найдено привязок: " . count($warehouseLinks) . "\n\n";
        foreach ($warehouseLinks as $link) {
            echo "      [{$link['date_add']}] Movement ID: {$link['id_stock_mvt']}\n";
            echo "         Склад: {$link['warehouse_name']} (ID: {$link['id_warehouse']})\n\n";
        }
    }
} else {
    echo "\n❌ Движения НЕ создаются!\n";
    echo "Возможные причины:\n";
    echo "1. add_movement=true не работает\n";
    echo "2. StockAvailable::updateQuantity() не вызывается\n";
    echo "3. Проверьте логи AccuPOS\n";
}

// 4. Логи AccuPOS за последнюю синхронизацию
echo "\n4. Последние 20 записей логов AccuPOS:\n";
$logFile = '/var/www/dev.geek-shelter.com/var/logs/accupos/' . date('Y-m-d') . '.log';
if (file_exists($logFile)) {
    $lines = file($logFile);
    $lastLines = array_slice($lines, -20);
    foreach ($lastLines as $line) {
        echo "   " . trim($line) . "\n";
    }
} else {
    echo "   ❌ Лог-файл не найден: $logFile\n";
}

echo "\n=== ИТОГ ===\n";
echo "Движения создаются: " . ($mvtCount > 0 ? "✅ ДА ($mvtCount)" : "❌ НЕТ") . "\n";
echo "Привязка к складам: " . (isset($warehouseLinks) && !empty($warehouseLinks) ? "✅ ДА" : "❌ НЕТ") . "\n";

