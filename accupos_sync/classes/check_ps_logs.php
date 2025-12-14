<?php
/**
 * Проверка логов PrestaShop для AccuPosSync
 */

require_once('/var/www/dev.geek-shelter.com/config/config.inc.php');

$db = Db::getInstance();

// Проверяем последние логи AccuPosSync
$logs = $db->executeS('
    SELECT date_add, severity, message 
    FROM ' . _DB_PREFIX_ . 'log 
    WHERE object_type = "AccuPosSync" 
    ORDER BY id_log DESC 
    LIMIT 30
');

echo "=== PrestaShop Logs для AccuPosSync ===\n\n";

if (empty($logs)) {
    echo "❌ Логов не найдено!\n";
} else {
    foreach ($logs as $log) {
        $severity = $log['severity'] == 4 ? '🔴 ERROR' : '✅ INFO';
        echo "[{$log['date_add']}] {$severity}\n";
        echo "   Message: {$log['message']}\n\n";
    }
}

echo "\n=== Всего записей: " . count($logs) . " ===\n";

