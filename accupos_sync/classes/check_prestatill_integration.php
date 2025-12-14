<?php
/**
 * Проверка интеграции с модулем prestatillstockperstore
 */

require_once('/var/www/dev.geek-shelter.com/config/config.inc.php');

$db = Db::getInstance();

echo "=== ИНТЕГРАЦИЯ С PRESTATILLSTOCKPERSTORE ===\n\n";

// 1. Проверяем, установлен ли модуль
$moduleInstalled = Module::isInstalled('prestatillstockperstore');
echo "1. Модуль prestatillstockperstore установлен: " . ($moduleInstalled ? "✅ ДА" : "❌ НЕТ") . "\n";

// 2. Проверяем таблицу маппинга движений и складов
$tableExists = $db->getValue('
    SHOW TABLES LIKE "' . _DB_PREFIX_ . 'prestatill_stock_mvt_warehouse"
');

echo "2. Таблица ps_prestatill_stock_mvt_warehouse существует: " . ($tableExists ? "✅ ДА" : "❌ НЕТ") . "\n";

if ($tableExists) {
    // Проверяем структуру
    echo "\n3. Структура таблицы ps_prestatill_stock_mvt_warehouse:\n";
    $cols = $db->executeS('SHOW COLUMNS FROM ' . _DB_PREFIX_ . 'prestatill_stock_mvt_warehouse');
    foreach ($cols as $col) {
        echo "   - {$col['Field']} ({$col['Type']})\n";
    }
    
    // Проверяем записи за сегодня
    $count = $db->getValue('
        SELECT COUNT(*) 
        FROM ' . _DB_PREFIX_ . 'prestatill_stock_mvt_warehouse smw
        INNER JOIN ' . _DB_PREFIX_ . 'stock_mvt sm ON smw.id_stock_mvt = sm.id_stock_mvt
        WHERE DATE(sm.date_add) = CURDATE()
    ');
    
    echo "\n4. Записей маппинга движений за сегодня: $count\n";
    
    if ($count > 0) {
        $movements = $db->executeS('
            SELECT 
                smw.id_stock_mvt,
                smw.id_warehouse,
                w.name as warehouse_name,
                sm.physical_quantity,
                sm.sign,
                sm.date_add
            FROM ' . _DB_PREFIX_ . 'prestatill_stock_mvt_warehouse smw
            INNER JOIN ' . _DB_PREFIX_ . 'stock_mvt sm ON smw.id_stock_mvt = sm.id_stock_mvt
            INNER JOIN ' . _DB_PREFIX_ . 'warehouse w ON smw.id_warehouse = w.id_warehouse
            WHERE DATE(sm.date_add) = CURDATE()
            ORDER BY sm.date_add DESC
            LIMIT 10
        ');
        
        echo "\n5. Последние 10 движений:\n";
        foreach ($movements as $mvt) {
            $sign = $mvt['sign'] == -1 ? '🔴 Списание' : '🟢 Приход';
            echo "   [{$mvt['date_add']}] {$sign}\n";
            echo "      Склад: {$mvt['warehouse_name']} (ID: {$mvt['id_warehouse']})\n";
            echo "      Количество: " . abs($mvt['physical_quantity']) . "\n\n";
        }
    }
}

echo "\n=== ВЫВОДЫ ===\n";
echo "- Модуль prestatillstockperstore: " . ($moduleInstalled ? "✅ Активен" : "❌ Не установлен") . "\n";
echo "- Движения товаров создаются: " . ($tableExists && $count > 0 ? "✅ ДА" : "❌ НЕТ") . "\n";
echo "- AccuPOS Sync должен интегрироваться: " . ($moduleInstalled ? "✅ ДА (через PrestatillStockMvt)" : "⚠️ Не обязательно") . "\n";

echo "\n💡 РЕКОМЕНДАЦИЯ:\n";
echo "AccuPOS Sync должен использовать методы prestatillstockperstore для создания движений,\n";
echo "чтобы они корректно отображались в админке с привязкой к складам.\n";

