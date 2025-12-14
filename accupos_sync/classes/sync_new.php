<?php
/**
 * AccuPOS Sync CRON Endpoint
 * 
 * Запуск синхронизации из crontab
 * Использование: 0 2 * * * /usr/bin/php /path/to/cron/sync.php
 * 
 * @author    Aleksei Nekrasov <info@impserver.ru>
 * @copyright 2025 ООО «Свобода» / impserver.ru
 * @license   Proprietary
 */


if (php_sapi_name() === 'cli') {
    ['REQUEST_METHOD'] = 'GET';
    ['HTTP_HOST'] = 'cli';
}

// Подключение конфигурации PrestaShop
require_once dirname(__FILE__) . '/../../../config/config.inc.php';
require_once dirname(__FILE__) . '/../../../init.php';

// Подключение модуля
require_once dirname(__FILE__) . '/../accupos_sync.php';

// Проверка запуска из CLI
if (php_sapi_name() !== 'cli') {
    // Если запущено не из CLI, проверяем секретный токен
    $cronToken = Tools::getValue('token');
    $expectedToken = md5(_COOKIE_KEY_ . 'accupos_sync_cron');
    
    if ($cronToken !== $expectedToken) {
        header('HTTP/1.1 403 Forbidden');
        die('Access denied');
    }
}

// Проверка, включён ли CRON в настройках
if (!Configuration::get('ACCUPOS_ENABLE_CRON')) {
    echo "CRON is disabled in module settings\n";
    exit(0);
}

// Время начала
$startTime = microtime(true);
$timestamp = date('Y-m-d H:i:s');

echo "========================================\n";
echo "AccuPOS Sync CRON Job\n";
echo "Started at: {$timestamp}\n";
echo "========================================\n\n";

try {
    // Инициализация модуля
    $module = new AccuPos_Sync();
    
    echo "Module initialized successfully\n";
    echo "Running synchronization...\n\n";
    
    // Запуск синхронизации
    $result = $module->runSync();
    
    // Вывод результата
    echo "========================================\n";
    echo "Synchronization Result:\n";
    echo "========================================\n";
    echo "Status: " . ($result['success'] ? 'SUCCESS' : 'FAILED') . "\n";
    echo "Message: " . $result['message'] . "\n";
    echo "Processed: " . $result['processed'] . "\n";
    echo "Success: " . $result['success_count'] . "\n";
    echo "Errors: " . $result['error_count'] . "\n";
    echo "Skipped: " . $result['skipped_count'] . "\n";
    echo "Duration: " . number_format(microtime(true) - $startTime, 2) . " seconds\n";
    echo "========================================\n";
    
    // Exit code
    exit($result['success'] ? 0 : 1);
    
} catch (Exception $e) {
    echo "\n========================================\n";
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "========================================\n";
    
    // Логирование в PrestaShop
    PrestaShopLogger::addLog(
        'AccuPOS CRON Error: ' . $e->getMessage(),
        4,
        null,
        'AccuPosCron'
    );
    
    exit(1);
}

