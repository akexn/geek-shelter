<?php
/**
 * AccuPOS Sync - AJAX обработчик синхронизации
 * Прямой запуск синхронизации БЕЗ асинхронного worker'а
 */

header('Content-Type: application/json');
set_time_limit(600); // 10 минут для синхронизации

try {
    // Подключаемся к PrestaShop
    // Файл находится в /modules/accupos_sync/ajax_sync.php
    // Нужно подняться на 3 уровня вверх до корня проекта (…/modules/accupos_sync -> …/)
    $rootPath = dirname(dirname(dirname(__FILE__)));
    
    require_once($rootPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.inc.php');
    require_once($rootPath . DIRECTORY_SEPARATOR . 'init.php');

    $action = Tools::getValue('action');

    if ($action === 'StartAsyncSync') {
        // Запускаем синхронизацию ПРЯМО ЗДЕСЬ (синхронно)
        // Классы находятся в той же папке модуля
        $modulePath = dirname(__FILE__);
        require_once($modulePath . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'AccuPosDB.php');
        require_once($modulePath . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'AccuPosLogger.php');
        require_once($modulePath . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'AccuPosTerminalMapper.php');
        require_once($modulePath . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'AccuPosSync.php');

        $syncModule = new AccuPosSync();
        $syncModule->setManualSyncMode();
        
        // Синхронизируем данные прямо в этом AJAX запросе
        $result = $syncModule->sync();
        
        die(json_encode([
            'success' => true,
            'status' => 'completed',
            'result' => $result,
            'message' => 'Синхронизация завершена'
        ]));

    } else {
        die(json_encode([
            'success' => false,
            'message' => 'Неизвестный action: ' . $action
        ]));
    }

} catch (Exception $e) {
    // Логируем ошибку
    if (class_exists('PrestaShopLogger')) {
        PrestaShopLogger::addLog(
            'AccuPOS AJAX Sync Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
            4,
            null,
            'AccuPos_Sync'
        );
    }
    
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'message' => 'Ошибка: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) ? $e->getTraceAsString() : null
    ]));
}
?>

