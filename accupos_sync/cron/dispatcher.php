<?php
/**
 * AccuPOS CRON Dispatcher
 *
 * Идея:
 * - В crontab стоит ОДНА фиксированная строка (например, каждую минуту).
 * - Периодичность синхронизации настраивается в админке модуля (Configuration).
 * - Диспетчер сам решает, пора ли запускать sync.
 *
 * Пример строки в /var/spool/cron/crontabs/root (DO prod: /var/www/html):
 *   * * * * * /usr/bin/php7.4 /var/www/html/modules/accupos_sync/cron/dispatcher.php >> /var/www/html/var/logs/cron/accupos_cron.log 2>&1
 */

// В CLI подавляем NOTICE (и не выводим ошибки в stdout), чтобы не засорять cron log.
error_reporting(E_ALL & ~E_NOTICE);
@ini_set('display_errors', '0');

// Определяем корень PrestaShop (…/modules/accupos_sync/cron -> …/)
$psRoot = dirname(__DIR__, 3);
require_once $psRoot . '/config/config.inc.php';

/**
 * Лог для cron: пишем в STDERR, чтобы редирект из crontab попадал в лог-файл.
 *
 * @param string $message
 * @return void
 */
function accupos_dispatcher_log($message)
{
    $ts = date('Y-m-d H:i:s');
    @fwrite(STDERR, '[ACCUPOS DISPATCHER] ' . $ts . ' ' . (string)$message . "\n");
}

// Логируем ранний bootstrap (до init.php), чтобы точно попадало в cron log
accupos_dispatcher_log('bootstrap');

// ВАЖНО: НЕ подключаем init.php в CLI.
// В PrestaShop 1.7 init.php создаёт FrontController и может сделать redirect/exit,
// из-за чего dispatcher завершится до выполнения логики CRON.
accupos_dispatcher_log('tick');

// Флаг включения CRON в модуле
$enabled = (bool) Configuration::get('ACCUPOS_ENABLE_CRON');
if (!$enabled) {
    accupos_dispatcher_log('disabled');
    exit(0);
}

// Интервал в минутах (из админки), по умолчанию 10
$intervalMin = (int) Configuration::get('ACCUPOS_CRON_INTERVAL_MINUTES');
if ($intervalMin <= 0) {
    $intervalMin = 10;
}

// Режим синхронизации (today|yesterday)
$mode = (string) Configuration::get('ACCUPOS_CRON_SYNC_MODE', 'today');

// Простой lock, чтобы избежать параллельного запуска (TTL 30 минут)
$lockTtlSec = 30 * 60;
$now = time();
$lockTs = (int) Configuration::get('ACCUPOS_CRON_LOCK_TS');
if ($lockTs > 0 && ($now - $lockTs) < $lockTtlSec) {
    accupos_dispatcher_log('locked, skip');
    exit(0);
}

$lastRun = (int) Configuration::get('ACCUPOS_CRON_LAST_RUN_TS');

// Логика "когда запускать":
// - today: каждые N минут (интервал задаётся в админке)
// - yesterday (legacy): 1 раз в день, в заданное время ACCUPOS_CRON_TIME (например 02:30)
$due = false;
if ($mode === 'yesterday') {
    $cronTime = (string) Configuration::get('ACCUPOS_CRON_TIME', '02:00'); // HH:MM
    if (!preg_match('/^\\d{2}:\\d{2}$/', $cronTime)) {
        $cronTime = '02:00';
    }

    $scheduledTs = strtotime(date('Y-m-d') . ' ' . $cronTime . ':00');
    if ($scheduledTs === false) {
        $scheduledTs = strtotime(date('Y-m-d') . ' 02:00:00');
    }

    // Запуск только после наступления времени и только один раз в сутки
    $due = ($now >= $scheduledTs) && ($lastRun < $scheduledTs);
    if (!$due) {
        accupos_dispatcher_log('not due (mode=yesterday time=' . $cronTime . ')');
        exit(0);
    }
} else {
    // today
    $due = ($lastRun <= 0) || (($now - $lastRun) >= ($intervalMin * 60));
    if (!$due) {
        accupos_dispatcher_log('not due (mode=today interval=' . $intervalMin . 'm)');
        exit(0);
    }
}

// Ставим lock
Configuration::updateValue('ACCUPOS_CRON_LOCK_TS', (int) $now);

try {
    $moduleFile = dirname(__DIR__) . '/accupos_sync.php';
    require_once $moduleFile;

    $module = new AccuPos_Sync();
    $result = $module->runSync();

    Configuration::updateValue('ACCUPOS_CRON_LAST_RUN_TS', (int) time());
    accupos_dispatcher_log('sync result: ' . json_encode($result));
} catch (Exception $e) {
    accupos_dispatcher_log('exception: ' . $e->getMessage());
} finally {
    // Снимаем lock
    Configuration::updateValue('ACCUPOS_CRON_LOCK_TS', 0);
}

exit(0);


