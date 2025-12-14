<?php
/**
 * AccuPOS Sync - Фоновый worker для асинхронной синхронизации
 * Запускается из CRON каждую минуту и проверяет есть ли ожидающие задачи
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Простое логирование
function log_async($message) {
    $logFile = '/var/www/dev.geek-shelter.com/var/logs/accupos_async_worker.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[WORKER] $timestamp - $message\n", FILE_APPEND);
    error_log("[WORKER] $timestamp - $message");
}

try {
    log_async('Worker started');

    // Подключаемся МИНИМАЛЬНО к PrestaShop (без init.php для скорости)
    require_once('/var/www/dev.geek-shelter.com/config/config.inc.php');
    
    // Подключаем только нужные классы
    require_once('/var/www/dev.geek-shelter.com/classes/db/Db.php');
    require_once('/var/www/dev.geek-shelter.com/classes/Tools.php');
    require_once('/var/www/dev.geek-shelter.com/classes/Configuration.php');
    require_once('/var/www/dev.geek-shelter.com/classes/ObjectModel.php');

    log_async('Core classes loaded');

    // Подключаем классы синхронизации
    require_once('/var/www/dev.geek-shelter.com/modules/accupos_sync/classes/AccuPosDB.php');
    require_once('/var/www/dev.geek-shelter.com/modules/accupos_sync/classes/AccuPosLogger.php');
    require_once('/var/www/dev.geek-shelter.com/modules/accupos_sync/classes/AccuPosTerminalMapper.php');
    require_once('/var/www/dev.geek-shelter.com/modules/accupos_sync/classes/AccuPosSync.php');
    log_async('AccuPosSync class loaded');

    // Получаем конфигурацию без init.php
    $db = Db::getInstance();
    
    // Проверяем флаг синхронизации через прямой SQL запрос
    $isPending = (bool)$db->getValue('SELECT value FROM ' . _DB_PREFIX_ . 'configuration WHERE name = \'ACCUPOS_ASYNC_SYNC_PENDING\'');

    if (!$isPending) {
        log_async('No pending sync task');
        exit(0);
    }

    log_async('Found pending sync task, starting...');

    // Запускаем синхронизацию напрямую через AccuPosSync (без загрузки Module)
    try {
        $syncClass = new AccuPosSync();
        $syncClass->setManualSyncMode(); // Устанавливаем режим ручной синхронизации
        
        log_async('AccuPosSync initialized');
        
        $result = $syncClass->sync();
        
        log_async('Sync completed: ' . json_encode($result));

        // Сохраняем результат для чтения из админки (прямой SQL)
        $resultJson = json_encode($result);
        $db->update('configuration', array('value' => $resultJson), 'name = \'ACCUPOS_ASYNC_SYNC_RESULT\'');
        $db->update('configuration', array('value' => '0'), 'name = \'ACCUPOS_ASYNC_SYNC_PENDING\'');
        
        log_async('Configuration updated');
    } catch (Exception $e) {
        log_async('SYNC EXCEPTION: ' . $e->getMessage());
        $db->update('configuration', array('value' => '0'), 'name = \'ACCUPOS_ASYNC_SYNC_PENDING\'');
        throw $e;
    }

    log_async('Result saved, worker finished');
    exit(0);

} catch (Exception $e) {
    log_async('FATAL EXCEPTION: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    exit(1);
}
?>

