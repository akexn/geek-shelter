<?php
/**
 * AccuPOS CRON - исправленная версия
 * Добавлено полное подключение Configuration класса
 */

error_log("[CRON] Started at " . date('Y-m-d H:i:s'));

// Подключение конфига
require_once '/var/www/dev.geek-shelter.com/config/config.inc.php';
error_log("[CRON] Config loaded");

// Подключение критических классов
require_once '/var/www/dev.geek-shelter.com/classes/db/Db.php';
require_once '/var/www/dev.geek-shelter.com/classes/Tools.php';
require_once '/var/www/dev.geek-shelter.com/classes/Configuration.php';
require_once '/var/www/dev.geek-shelter.com/classes/ObjectModel.php';
error_log("[CRON] Core classes loaded");

// Инициализация БД
$db = Db::getInstance();
error_log("[CRON] Database connected");

// Проверка конфига - теперь Configuration работает!
$cronEnabled = Configuration::get('ACCUPOS_ENABLE_CRON');
error_log("[CRON] CRON enabled: " . ($cronEnabled ? 'YES' : 'NO'));

if (!$cronEnabled) {
    error_log("[CRON] CRON disabled, exiting");
    exit(0);
}

// Подключение модуля
require_once '/var/www/dev.geek-shelter.com/modules/accupos_sync/accupos_sync.php';
error_log("[CRON] AccuPOS module loaded");

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

