<?php
require_once('/var/www/dev.geek-shelter.com/config/config.inc.php');

$db = Db::getInstance();

// Проверяем обновления ps_stock_available за сегодня
$updates = $db->executeS('
    SELECT 
        sa.id_stock_available,
        sa.id_product,
        p.reference,
        p.ean13,
        sa.quantity,
        sa.date_upd,
        pl.name as product_name
    FROM ' . _DB_PREFIX_ . 'stock_available sa
    LEFT JOIN ' . _DB_PREFIX_ . 'product p ON sa.id_product = p.id_product
    LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl ON p.id_product = pl.id_product AND pl.id_lang = 1
    WHERE DATE(sa.date_upd) = CURDATE()
    ORDER BY sa.date_upd DESC
    LIMIT 30
');

echo "=== Stock Available обновлений за сегодня ===\n\n";

if (empty($updates)) {
    echo "❌ Обновлений за сегодня НЕ НАЙДЕНО!\n";
    echo "Это значит, что StockAvailable::updateQuantity() вообще НЕ работает.\n";
} else {
    echo "✅ Найдено обновлений: " . count($updates) . "\n\n";
    
    foreach ($updates as $upd) {
        echo "[{$upd['date_upd']}]\n";
        echo "   Товар: {$upd['product_name']} (ID: {$upd['id_product']})\n";
        echo "   Reference: {$upd['reference']}\n";
        echo "   EAN13: {$upd['ean13']}\n";
        echo "   Текущее количество: {$upd['quantity']}\n\n";
    }
}

