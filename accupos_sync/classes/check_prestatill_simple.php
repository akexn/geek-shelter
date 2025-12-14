<?php
require_once('/var/www/dev.geek-shelter.com/config/config.inc.php');

$db = Db::getInstance();

echo "=== PRESTATILLSTOCKPERSTORE ANALYSIS ===\n\n";

// 1. Модуль установлен?
$moduleInstalled = Module::isInstalled('prestatillstockperstore');
echo "1. Модуль установлен: " . ($moduleInstalled ? "✅" : "❌") . "\n\n";

// 2. Таблицы модуля
echo "2. Таблицы модуля:\n";
$tables = $db->executeS('SHOW TABLES LIKE "%prestatill%"');
foreach ($tables as $table) {
    $tableName = array_values($table)[0];
    echo "   - $tableName\n";
}

// 3. Проверка ps_stock_mvt (стандартная таблица PrestaShop)
echo "\n3. Движения в ps_stock_mvt за сегодня:\n";
$mvtCount = $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'stock_mvt WHERE DATE(date_add) = CURDATE()');
echo "   Всего: $mvtCount\n";

// 4. Проверка ps_prestatill_stock_mvt_warehouse
$smwExists = $db->executeS('SHOW TABLES LIKE "' . _DB_PREFIX_ . 'prestatill_stock_mvt_warehouse"');
if (!empty($smwExists)) {
    echo "\n4. Таблица ps_prestatill_stock_mvt_warehouse существует: ✅\n";
    $smwCount = $db->getValue('
        SELECT COUNT(*) 
        FROM ' . _DB_PREFIX_ . 'prestatill_stock_mvt_warehouse
    ');
    echo "   Всего записей: $smwCount\n";
} else {
    echo "\n4. Таблица ps_prestatill_stock_mvt_warehouse: ❌ НЕ НАЙДЕНА\n";
}

echo "\n=== КАК РАБОТАЕТ PRESTATILLSTOCKPERSTORE ===\n\n";
echo "📋 Основные функции:\n";
echo "1. Создаёт движения товаров (ps_stock_mvt) при заказах\n";
echo "2. Привязывает движения к складам (ps_prestatill_stock_mvt_warehouse)\n";
echo "3. Управляет stock по складам (multi-warehouse)\n";
echo "4. Маршрутизирует заказы на нужные склады\n\n";

echo "⚠️ ВАЖНО ДЛЯ ACCUPOS SYNC:\n";
echo "- StockAvailable::updateQuantity() БЕЗ add_movement=true НЕ создаёт движения\n";
echo "- Для интеграции с prestatillstockperstore нужно:\n";
echo "  a) Использовать PrestatillStockMvt::addIdWarehouseToMvt()\n";
echo "  b) ИЛИ вызывать StockAvailable::updateQuantity() с add_movement=true\n";
echo "  c) И затем связывать движение со складом через PrestatillStockMvt\n\n";

echo "💡 ТЕКУЩЕЕ СОСТОЯНИЕ ACCUPOS SYNC:\n";
echo "✅ Stock обновляется (quantity в ps_stock_available)\n";
echo "❌ Движения НЕ создаются (нет записей в ps_stock_mvt)\n";
echo "❌ Склады НЕ привязываются (нет записей в ps_prestatill_stock_mvt_warehouse)\n\n";

echo "🔧 РЕШЕНИЕ:\n";
echo "Нужно доработать AccuPosSync::updateProductStock() для:\n";
echo "1. Создания движений через StockAvailable::updateQuantity(..., true)\n";
echo "2. Привязки движения к складу через PrestatillStockMvt::addIdWarehouseToMvt()\n";

