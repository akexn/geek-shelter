<?php
/**
 * AccuPOS Sync - Фоновый worker для асинхронной синхронизации
 * Запускается из CRON каждую минуту и проверяет есть ли ожидающие задачи.
 *
 * ВАЖНО: не подключаем init.php (см. dispatcher.php), иначе возможны redirect/exit в CLI.
 */

// В CLI подавляем NOTICE и не выводим ошибки в stdout, чтобы не раздувать лог.
error_reporting(E_ALL & ~E_NOTICE);
@ini_set('display_errors', '0');

// Определяем корень PrestaShop (…/modules/accupos_sync/cron -> …/)
$psRoot = dirname(__DIR__, 3);
$logFile = $psRoot . '/var/logs/accupos_async_worker.log';

// Простое логирование
function log_async($message)
{
    global $logFile;

    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[WORKER] $timestamp - $message\n", FILE_APPEND);
}

try {
    // Подключаемся к PrestaShop
    require_once $psRoot . '/config/config.inc.php';

    // Проверяем флаг синхронизации (без шума в логах)
    $isPending = (bool)Configuration::get('ACCUPOS_ASYNC_SYNC_PENDING');

    if (!$isPending) {
        exit(0);
    }

    // Простой lock, чтобы не запускать 2 worker параллельно (TTL 30 минут)
    $lockTtlSec = 30 * 60;
    $now = time();
    $lockTs = (int)Configuration::get('ACCUPOS_ASYNC_SYNC_LOCK_TS');
    if ($lockTs > 0 && ($now - $lockTs) < $lockTtlSec) {
        log_async('locked, skip');
        exit(0);
    }
    Configuration::updateValue('ACCUPOS_ASYNC_SYNC_LOCK_TS', (int)$now);

    log_async('pending task found, starting sync');

    // Запускаем синхронизацию напрямую через AccuPosSync (без загрузки Module)
    try {
        // Подключаем классы синхронизации (модуль)
        $moduleRoot = dirname(__DIR__);
        require_once $moduleRoot . '/classes/AccuPosDB.php';
        require_once $moduleRoot . '/classes/AccuPosLogger.php';
        require_once $moduleRoot . '/classes/AccuPosTerminalMapper.php';
        require_once $moduleRoot . '/classes/AccuPosSync.php';

        $syncClass = new AccuPosSync();
        $syncClass->setManualSyncMode(); // Устанавливаем режим ручной синхронизации
        
        $result = $syncClass->sync();
        
        log_async('sync completed: ' . json_encode($result));

        // Сохраняем результат для чтения из админки
        Configuration::updateValue('ACCUPOS_ASYNC_SYNC_RESULT', json_encode($result));
        Configuration::updateValue('ACCUPOS_ASYNC_SYNC_PENDING', 0);
    } catch (Exception $e) {
        log_async('SYNC EXCEPTION: ' . $e->getMessage());
        Configuration::updateValue('ACCUPOS_ASYNC_SYNC_PENDING', 0);
        throw $e;
    } finally {
        Configuration::updateValue('ACCUPOS_ASYNC_SYNC_LOCK_TS', 0);
    }

    log_async('worker finished');
    exit(0);

} catch (Exception $e) {
    log_async('FATAL EXCEPTION: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    exit(1);
}


