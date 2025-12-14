<?php
require_once('/var/www/dev.geek-shelter.com/config/config.inc.php');

$db = Db::getInstance();
$cols = $db->executeS('SHOW COLUMNS FROM ' . _DB_PREFIX_ . 'stock_mvt');

echo "=== Структура таблицы ps_stock_mvt ===\n\n";
foreach ($cols as $col) {
    echo "{$col['Field']} ({$col['Type']})\n";
}

// Проверим, есть ли вообще записи за сегодня
$count = $db->getValue('
    SELECT COUNT(*) 
    FROM ' . _DB_PREFIX_ . 'stock_mvt 
    WHERE DATE(date_add) = CURDATE()
');

echo "\n=== Движений за сегодня: $count ===\n";

if ($count > 0) {
    $movements = $db->executeS('
        SELECT * 
        FROM ' . _DB_PREFIX_ . 'stock_mvt 
        WHERE DATE(date_add) = CURDATE()
        ORDER BY id_stock_mvt DESC
        LIMIT 5
    ');
    
    echo "\n=== Последние 5 движений ===\n";
    print_r($movements);
}

