<?php
/**
 * AccuPOS Sync - AJAX обработчик синхронизации
 * Прямой запуск синхронизации БЕЗ асинхронного worker'а
 */

header('Content-Type: application/json');
set_time_limit(600); // 10 минут для синхронизации

try {
    // Подключаемся к PrestaShop
    require_once(dirname(__FILE__) . '/../../config/config.inc.php');
    require_once(dirname(__FILE__) . '/../../init.php');

    $action = Tools::getValue('action');

    if ($action === 'StartAsyncSync') {
        // Запускаем синхронизацию ПРЯМО ЗДЕСЬ (синхронно)
        require_once(dirname(__FILE__) . '/classes/AccuPosDB.php');
        require_once(dirname(__FILE__) . '/classes/AccuPosLogger.php');
        require_once(dirname(__FILE__) . '/classes/AccuPosTerminalMapper.php');
        require_once(dirname(__FILE__) . '/classes/AccuPosSync.php');

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
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'message' => 'Ошибка: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]));
}
?>

