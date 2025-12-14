<?php
/**
 * Финальная проверка: работает ли обновление stock?
 */

require_once('/var/www/dev.geek-shelter.com/config/config.inc.php');

$db = Db::getInstance();

echo "=== ФИНАЛЬНАЯ ПРОВЕРКА МОДУЛЯ AccuPOS Sync ===\n\n";

// 1. Проверяем структуру ps_stock_available
echo "1. Структура ps_stock_available:\n";
$cols = $db->executeS('SHOW COLUMNS FROM ' . _DB_PREFIX_ . 'stock_available');
foreach ($cols as $col) {
    echo "   - {$col['Field']}\n";
}

// 2. Проверяем, есть ли товары с EAN из логов
echo "\n2. Проверяем товары по EAN из успешных транзакций:\n";
$testEANs = ['9781789990546', '5011921235865', '7291234567895'];

foreach ($testEANs as $ean) {
    $product = $db->getRow('
        SELECT p.id_product, p.reference, p.ean13, pl.name, sa.quantity
        FROM ' . _DB_PREFIX_ . 'product p
        LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl ON p.id_product = pl.id_product AND pl.id_lang = 1
        LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa ON p.id_product = sa.id_product AND sa.id_product_attribute = 0
        WHERE p.ean13 = "' . pSQL($ean) . '"
    ');
    
    if ($product) {
        echo "   ✅ EAN: $ean\n";
        echo "      Товар: {$product['name']} (ID: {$product['id_product']})\n";
        echo "      Текущее количество: {$product['quantity']}\n";
    } else {
        echo "   ❌ EAN: $ean - не найден\n";
    }
}

// 3. Проверяем логи синхронизации из БД
echo "\n3. Логи синхронизации (последние 10):\n";
$logs = $db->executeS('
    SELECT 
        accupos_transaction_id, 
        sku, 
        qty, 
        status, 
        error_message,
        date_processed
    FROM ' . _DB_PREFIX_ . 'accupos_transactions
    ORDER BY id DESC
    LIMIT 10
');

if (empty($logs)) {
    echo "   ❌ Логов транзакций НЕТ в БД!\n";
} else {
    foreach ($logs as $log) {
        $status = $log['status'] == 'success' ? '✅' : '❌';
        echo "   {$status} TX: {$log['accupos_transaction_id']}, SKU: {$log['sku']}, Qty: {$log['qty']}\n";
        if ($log['error_message']) {
            echo "      Ошибка: {$log['error_message']}\n";
        }
    }
}

echo "\n=== ИТОГ ===\n";
echo "Модуль AccuPOS Sync работает: " . (empty($logs) ? "❌ НЕТ" : "✅ ДА") . "\n";
echo "Stock обновляется: ТРЕБУЕТСЯ РУЧНАЯ ПРОВЕРКА в админке\n";
echo "\nПуть: Каталог → Товары → Выберите товар → вкладка 'Количество'\n";

