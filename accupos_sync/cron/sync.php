<?php
/**
 * AccuPOS CRON - исправленная версия
 * Добавлено полное подключение Configuration класса
 */

error_log("[CRON] Started at " . date('Y-m-d H:i:s'));

// Определяем корень PrestaShop (…/modules/accupos_sync/cron -> …/)
$psRoot = dirname(__DIR__, 3);
require_once $psRoot . '/config/config.inc.php';
require_once $psRoot . '/init.php';
error_log("[CRON] PrestaShop bootstrap loaded");

// Проверка конфига - теперь Configuration работает!
$cronEnabled = Configuration::get('ACCUPOS_ENABLE_CRON');
error_log("[CRON] CRON enabled: " . ($cronEnabled ? 'YES' : 'NO'));

if (!$cronEnabled) {
    error_log("[CRON] CRON disabled, exiting");
    exit(0);
}

// Подключение модуля
$moduleFile = dirname(__DIR__) . '/accupos_sync.php';
require_once $moduleFile;
error_log("[CRON] AccuPOS module loaded: " . $moduleFile);

try {
    $startTime = microtime(true);
    
    // Инициализация модуля
    $module = new AccuPos_Sync();
    error_log("[CRON] Module instantiated");
    
    $result = $module->runSync(); // Запуск синхронизации
    error_log("[CRON] Sync completed: " . json_encode($result));
    
    $duration = number_format(microtime(true) - $startTime, 2);
    error_log("[CRON] Duration: {$duration} sec");
    
} catch (Exception $e) {
    error_log("[CRON ERROR] " . $e->getMessage());
    error_log("[CRON ERROR] " . $e->getTraceAsString());
    exit(1);
}

error_log("[CRON] Finished successfully");
exit(0);
?>

