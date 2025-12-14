#!/usr/bin/env php
<?php
/**
 * Повторная обработка транзакций 12 ноября с новой версией кода
 */

$file = '/var/www/dev.geek-shelter.com/modules/accupos_sync/cron/sync.php';

if (!file_exists($file)) {
    die("❌ Файл не найден: $file\n");
}

// Инициализируем PrestaShop
require_once('/var/www/dev.geek-shelter.com/config/config.inc.php');

// Требуемые классы
require_once('/var/www/dev.geek-shelter.com/classes/db/Db.php');
require_once('/var/www/dev.geek-shelter.com/classes/Tools.php');
require_once('/var/www/dev.geek-shelter.com/classes/Configuration.php');
require_once('/var/www/dev.geek-shelter.com/classes/ObjectModel.php');

// Загружаем модуль
require_once('/var/www/dev.geek-shelter.com/modules/accupos_sync/accupos_sync.php');

echo "[REPROCESS] Started at " . date('Y-m-d H:i:s') . "\n";

try {
    $module = Module::getInstanceByName('accupos_sync');
    
    if (!$module) {
        die("[REPROCESS] ❌ Module not found\n");
    }
    
    // Режим ручной синхронизации
    $sync = new AccuPosSync();
    $sync->setManualSyncMode();
    
    echo "[REPROCESS] Manual sync mode set\n";
    echo "[REPROCESS] Running sync...\n";
    
    $result = $sync->sync();
    
    echo "[REPROCESS] Sync completed: " . json_encode($result) . "\n";
    
    // Проверяем новые движения
    $db = Db::getInstance();
    $mvtCount = $db->getValue(
        "SELECT COUNT(*) FROM 4667750Dimastock_mvt WHERE date_add LIKE '2025-11-12%'"
    );
    
    echo "[REPROCESS] Stock movements created today: " . $mvtCount . "\n";
    
} catch (Exception $e) {
    echo "[REPROCESS] ❌ Error: " . $e->getMessage() . "\n";
    echo "[REPROCESS] Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "[REPROCESS] Finished successfully\n";
?>

