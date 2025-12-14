<?php
/**
 * Проверка движений товаров (Stock Movements) за сегодня
 */

require_once('/var/www/dev.geek-shelter.com/config/config.inc.php');

$db = Db::getInstance();

// Проверяем движения за сегодня
$movements = $db->executeS('
    SELECT 
        sm.id_stock_mvt,
        sm.date_add,
        sm.product_name,
        sm.physical_quantity,
        sm.sign,
        w.name as warehouse_name,
        e.firstname, e.lastname
    FROM ' . _DB_PREFIX_ . 'stock_mvt sm
    LEFT JOIN ' . _DB_PREFIX_ . 'stock s ON sm.id_stock = s.id_stock
    LEFT JOIN ' . _DB_PREFIX_ . 'warehouse w ON s.id_warehouse = w.id_warehouse
    LEFT JOIN ' . _DB_PREFIX_ . 'employee e ON sm.id_employee = e.id_employee
    WHERE DATE(sm.date_add) = CURDATE()
    ORDER BY sm.id_stock_mvt DESC
    LIMIT 30
');

echo "=== Движения товаров за сегодня ===\n\n";

if (empty($movements)) {
    echo "❌ Движений за сегодня НЕ НАЙДЕНО!\n";
    echo "Это значит, что StockAvailable::updateQuantity() не создаёт записи в ps_stock_mvt.\n";
    echo "Возможная причина: Advanced Stock Management (ASM) не включен для товаров.\n";
} else {
    echo "✅ Найдено движений: " . count($movements) . "\n\n";
    
    foreach ($movements as $mvt) {
        $sign = $mvt['sign'] == -1 ? '🔴 Списание' : '🟢 Приход';
        $qty = abs($mvt['physical_quantity']);
        $employee = $mvt['firstname'] ? "{$mvt['firstname']} {$mvt['lastname']}" : 'AccuPOS Sync';
        
        echo "[{$mvt['date_add']}] {$sign}\n";
        echo "   Товар: {$mvt['product_name']}\n";
        echo "   Склад: {$mvt['warehouse_name']}\n";
        echo "   Количество: {$qty}\n";
        echo "   Сотрудник: {$employee}\n\n";
    }
}

// Дополнительно проверяем ps_stock_available за сегодня
$stockUpdates = $db->executeS('
    SELECT COUNT(*) as cnt
    FROM ' . _DB_PREFIX_ . 'stock_available
    WHERE DATE(date_upd) = CURDATE()
');

echo "\n=== Stock Available обновлений за сегодня: {$stockUpdates[0]['cnt']} ===\n";

