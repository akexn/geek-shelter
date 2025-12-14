<?php
/**
 * Комплексная диагностика модуля AccuPOS Sync
 * Дата проверки: 3 декабря 2025
 * 
 * Проверяет:
 * 1. Работу всех классов и методов
 * 2. Соответствие переменных и их значений
 * 3. Взаимодействие баз данных
 * 4. Сравнение данных между AccuPOS Cloud и PrestaShop
 * 
 * @author    Aleksei Nekrasov <info@impserver.ru>
 * @copyright 2025 ООО «Свобода» / impserver.ru
 */

// Подключение PrestaShop
// Определяем корень проекта (поднимаемся на 2 уровня вверх от accupos_sync/accupos_sync/)
$rootDir = dirname(dirname(dirname(__FILE__)));

// Проверяем наличие config.inc.php
$configFile = $rootDir . '/config/config.inc.php';
if (!file_exists($configFile)) {
    // Альтернативный путь (если структура другая)
    $configFile = dirname(dirname(__FILE__)) . '/config/config.inc.php';
    if (!file_exists($configFile)) {
        die('ОШИБКА: Не найден файл config.inc.php. Проверьте структуру проекта PrestaShop.');
    }
}

require_once $configFile;

// Проверяем наличие init.php
$initFile = $rootDir . '/init.php';
if (!file_exists($initFile)) {
    $initFile = dirname(dirname(__FILE__)) . '/init.php';
    if (!file_exists($initFile)) {
        die('ОШИБКА: Не найден файл init.php. Проверьте структуру проекта PrestaShop.');
    }
}

require_once $initFile;

// Базовая защита доступа (можно улучшить через токен)
// Разрешаем доступ только из админ-панели или с локального сервера
$allowed = false;

// Проверка: доступ из админ-панели
if (Context::getContext()->employee && Context::getContext()->employee->id) {
    $allowed = true;
}

// Проверка: доступ с локального сервера (для CRON/SSH)
if (!$allowed && isset($_SERVER['REMOTE_ADDR']) && in_array($_SERVER['REMOTE_ADDR'], array('127.0.0.1', '::1', 'localhost'))) {
    $allowed = true;
}

// Проверка: CLI режим (командная строка)
if (!$allowed && php_sapi_name() === 'cli') {
    $allowed = true;
}

// Проверка: токен в URL (опционально)
$token = Tools::getValue('token');
if (!$allowed && $token === '8a5edab282632443219e051e4ade2b1d') {
    $allowed = true;
}

if (!$allowed) {
    die('Access denied. Please access this script from admin panel, CLI, or with valid token.');
}

// Подключение классов модуля
require_once dirname(__FILE__) . '/classes/AccuPosDB.php';
require_once dirname(__FILE__) . '/classes/AccuPosSync.php';
require_once dirname(__FILE__) . '/classes/AccuPosLogger.php';
require_once dirname(__FILE__) . '/classes/AccuPosTerminalMapper.php';

// Дата для проверки (из GET параметра, QUERY_STRING или по умолчанию)
$checkDate = '2025-12-03'; // По умолчанию

// Проверка GET параметра (веб-запрос)
if (isset($_GET['date']) && !empty($_GET['date'])) {
    $checkDate = $_GET['date'];
}
// Проверка QUERY_STRING (CLI через bat-файл)
elseif (isset($_SERVER['QUERY_STRING']) && preg_match('/date=(\d{4}-\d{2}-\d{2})/', $_SERVER['QUERY_STRING'], $matches)) {
    $checkDate = $matches[1];
}
// Проверка аргументов командной строки (CLI напрямую)
elseif (php_sapi_name() === 'cli' && isset($argv)) {
    foreach ($argv as $arg) {
        if (preg_match('/date=(\d{4}-\d{2}-\d{2})/', $arg, $matches)) {
            $checkDate = $matches[1];
            break;
        }
    }
}

// Валидация даты
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkDate)) {
    $checkDate = '2025-12-03'; // Fallback на дефолтную дату
}
$dateFrom = $checkDate . ' 00:00:00';
$dateTo = $checkDate . ' 23:59:59';

// Результаты диагностики
$results = array(
    'timestamp' => date('Y-m-d H:i:s'),
    'check_date' => $checkDate,
    'tests' => array()
);

/**
 * Добавление результата теста
 */
function addTestResult($name, $status, $message = '', $data = array())
{
    global $results;
    $results['tests'][] = array(
        'name' => $name,
        'status' => $status, // 'success', 'warning', 'error'
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    );
}

/**
 * Вывод результата теста
 */
function outputTest($name, $status, $message = '', $data = array())
{
    $icon = '';
    $color = '';
    
    switch ($status) {
        case 'success':
            $icon = '✓';
            $color = 'green';
            break;
        case 'warning':
            $icon = '⚠';
            $color = 'orange';
            break;
        case 'error':
            $icon = '✗';
            $color = 'red';
            break;
    }
    
    echo "<div style='margin: 10px 0; padding: 10px; border-left: 4px solid {$color}; background: #f5f5f5;'>";
    echo "<strong>{$icon} {$name}</strong><br>";
    if ($message) {
        echo "<span style='color: {$color};'>{$message}</span><br>";
    }
    if (!empty($data)) {
        echo "<pre style='background: white; padding: 5px; margin-top: 5px; font-size: 11px;'>";
        echo htmlspecialchars(print_r($data, true));
        echo "</pre>";
    }
    echo "</div>";
    
    addTestResult($name, $status, $message, $data);
}

// Начало HTML вывода
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Диагностика AccuPOS Sync - <?php echo $checkDate; ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #fff; }
        h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        h2 { color: #555; margin-top: 30px; }
        .summary { background: #e7f3ff; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .error { color: red; }
        .success { color: green; }
        .warning { color: orange; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 3px; overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        table th, table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        table th { background: #007bff; color: white; }
        .section { margin: 30px 0; padding: 20px; background: #f9f9f9; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>🔍 Комплексная диагностика модуля AccuPOS Sync</h1>
    <div class="summary">
        <strong>Дата проверки:</strong> <?php echo $checkDate; ?><br>
        <strong>Время запуска:</strong> <?php echo date('Y-m-d H:i:s'); ?><br>
        <strong>Версия PrestaShop:</strong> <?php echo _PS_VERSION_; ?><br>
        <strong>Префикс БД:</strong> <?php echo _DB_PREFIX_; ?>
    </div>

<?php

// ============================================
// РАЗДЕЛ 1: ПРОВЕРКА КОНФИГУРАЦИИ
// ============================================
echo "<div class='section'>";
echo "<h2>1. Проверка конфигурации модуля</h2>";

// Проверка настроек подключения к AccuPOS
$dbHost = Configuration::get('ACCUPOS_DB_HOST');
$dbPort = Configuration::get('ACCUPOS_DB_PORT');
$dbName = Configuration::get('ACCUPOS_DB_NAME');
$dbUser = Configuration::get('ACCUPOS_DB_USER');
$dbPass = Configuration::get('ACCUPOS_DB_PASS');

if (empty($dbHost) || empty($dbName) || empty($dbUser) || empty($dbPass)) {
    outputTest('Конфигурация подключения к AccuPOS', 'error', 'Не все параметры подключения настроены', array(
        'host' => $dbHost ?: 'НЕ УСТАНОВЛЕНО',
        'port' => $dbPort ?: 'НЕ УСТАНОВЛЕНО',
        'database' => $dbName ?: 'НЕ УСТАНОВЛЕНО',
        'user' => $dbUser ? 'УСТАНОВЛЕНО (зашифровано)' : 'НЕ УСТАНОВЛЕНО',
        'pass' => $dbPass ? 'УСТАНОВЛЕНО (зашифровано)' : 'НЕ УСТАНОВЛЕНО'
    ));
} else {
    outputTest('Конфигурация подключения к AccuPOS', 'success', 'Все параметры подключения настроены', array(
        'host' => $dbHost,
        'port' => $dbPort ?: '3306 (по умолчанию)',
        'database' => $dbName,
        'user' => 'УСТАНОВЛЕНО (зашифровано)',
        'pass' => 'УСТАНОВЛЕНО (зашифровано)'
    ));
}

// Проверка других настроек
$defaultWarehouse = Configuration::get('ACCUPOS_DEFAULT_WAREHOUSE');
$syncWindowDays = Configuration::get('ACCUPOS_SYNC_WINDOW_DAYS');
$enableCron = Configuration::get('ACCUPOS_ENABLE_CRON');
$enableReports = Configuration::get('ACCUPOS_ENABLE_REPORTS');

outputTest('Дополнительные настройки', 'success', 'Настройки модуля', array(
    'default_warehouse' => $defaultWarehouse ?: 'НЕ УСТАНОВЛЕНО',
    'sync_window_days' => $syncWindowDays ?: '7 (по умолчанию)',
    'cron_enabled' => $enableCron ? 'Да' : 'Нет',
    'reports_enabled' => $enableReports ? 'Да' : 'Нет'
));

echo "</div>";

// ============================================
// РАЗДЕЛ 2: ПРОВЕРКА ПОДКЛЮЧЕНИЯ К ACCUPOS
// ============================================
echo "<div class='section'>";
echo "<h2>2. Проверка подключения к AccuPOS Cloud</h2>";

try {
    $accuposDb = new AccuPosDB();
    $connection = $accuposDb->getConnection();
    
    if ($connection) {
        outputTest('Подключение к AccuPOS Cloud', 'success', 'Подключение успешно установлено');
        
        // Проверка структуры таблиц
        try {
            $tables = $accuposDb->query("SHOW TABLES LIKE 'apcs%'");
            $tableNames = array();
            foreach ($tables as $table) {
                $tableNames[] = array_values($table)[0];
            }
            
            $requiredTables = array('apcshead', 'apcsitem');
            $missingTables = array();
            
            foreach ($requiredTables as $reqTable) {
                if (!in_array($reqTable, $tableNames)) {
                    $missingTables[] = $reqTable;
                }
            }
            
            if (empty($missingTables)) {
                outputTest('Структура таблиц AccuPOS', 'success', 'Все необходимые таблицы найдены', array(
                    'tables' => $tableNames
                ));
            } else {
                outputTest('Структура таблиц AccuPOS', 'error', 'Отсутствуют необходимые таблицы', array(
                    'found' => $tableNames,
                    'missing' => $missingTables
                ));
            }
        } catch (Exception $e) {
            outputTest('Структура таблиц AccuPOS', 'error', 'Ошибка при проверке таблиц: ' . $e->getMessage());
        }
        
    } else {
        outputTest('Подключение к AccuPOS Cloud', 'error', 'Не удалось установить подключение');
    }
} catch (Exception $e) {
    outputTest('Подключение к AccuPOS Cloud', 'error', 'Ошибка подключения: ' . $e->getMessage());
}

echo "</div>";

// ============================================
// РАЗДЕЛ 3: ПРОВЕРКА МАППИНГА ТЕРМИНАЛОВ
// ============================================
echo "<div class='section'>";
echo "<h2>3. Проверка маппинга терминалов → склады</h2>";

try {
    $terminals = AccuPosTerminalMapper::getAllTerminals(false);
    
    if (empty($terminals)) {
        outputTest('Маппинг терминалов', 'warning', 'Нет настроенных терминалов');
    } else {
        outputTest('Маппинг терминалов', 'success', 'Найдено терминалов: ' . count($terminals), array(
            'terminals' => $terminals
        ));
        
        // Проверка каждого терминала
        foreach ($terminals as $terminal) {
            $warehouseExists = AccuPosTerminalMapper::warehouseExists($terminal['warehouse_id']);
            
            if ($warehouseExists) {
                outputTest("Терминал: {$terminal['terminal_id']}", 'success', 
                    "Привязан к складу ID {$terminal['warehouse_id']} ({$terminal['warehouse_name']})", array(
                        'active' => $terminal['active'] ? 'Да' : 'Нет'
                    ));
            } else {
                outputTest("Терминал: {$terminal['terminal_id']}", 'error', 
                    "Склад ID {$terminal['warehouse_id']} не существует в PrestaShop!");
            }
        }
    }
} catch (Exception $e) {
    outputTest('Маппинг терминалов', 'error', 'Ошибка при проверке терминалов: ' . $e->getMessage());
}

echo "</div>";

// ============================================
// РАЗДЕЛ 4: ПРОВЕРКА ТРАНЗАКЦИЙ ЗА 3 ДЕКАБРЯ 2025
// ============================================
echo "<div class='section'>";
echo "<h2>4. Проверка транзакций за {$checkDate}</h2>";

// 4.1. Транзакции в AccuPOS Cloud
try {
    $accuposDb = new AccuPosDB();
    $accuposDb->getConnection(); // Устанавливаем соединение
    
    $query = "
        SELECT 
            i.Id AS transaction_id,
            i.ItemID AS sku,
            i.Quantity AS qty,
            i.LocationCode AS location,
            h.DateInvoiced AS sale_date,
            h.InvNum AS invoice_num
        FROM apcsitem i
        INNER JOIN apcshead h ON i.HeadKey = h.Key
        WHERE DATE(h.DateInvoiced) = :check_date
            AND h.DateInvoiced IS NOT NULL
            AND (h.Status IS NULL OR h.Status != 'Void')
            AND (i.Status IS NULL OR i.Status != 'V')
            AND i.Hidden = 0
            AND i.Quantity != 0
            AND i.isChoice = 0
        ORDER BY i.Id ASC
    ";
    
    $accuposTransactions = $accuposDb->query($query, array(':check_date' => $checkDate));
    
    outputTest('Транзакции в AccuPOS Cloud', 'success', 
        'Найдено транзакций: ' . count($accuposTransactions), array(
            'count' => count($accuposTransactions),
            'sample' => array_slice($accuposTransactions, 0, 5) // Первые 5 для примера
        ));
    
    // Группировка по терминалам
    $byTerminal = array();
    $bySku = array();
    foreach ($accuposTransactions as $txn) {
        $terminal = $txn['location'];
        $sku = $txn['sku'];
        
        if (!isset($byTerminal[$terminal])) {
            $byTerminal[$terminal] = 0;
        }
        $byTerminal[$terminal]++;
        
        if (!isset($bySku[$sku])) {
            $bySku[$sku] = 0;
        }
        $bySku[$sku]++;
    }
    
    outputTest('Распределение по терминалам (AccuPOS)', 'success', '', array(
        'by_terminal' => $byTerminal,
        'total_skus' => count($bySku),
        'top_10_skus' => array_slice($bySku, 0, 10, true)
    ));
    
} catch (Exception $e) {
    outputTest('Транзакции в AccuPOS Cloud', 'error', 'Ошибка при получении транзакций: ' . $e->getMessage());
    $accuposTransactions = array();
}

// 4.2. Транзакции в PrestaShop
try {
    $psTransactions = Db::getInstance()->executeS("
        SELECT 
            id,
            accupos_transaction_id,
            terminal_id,
            warehouse_id,
            sku,
            qty,
            status,
            error_message,
            date_sale,
            date_processed
        FROM " . _DB_PREFIX_ . "accupos_transactions
        WHERE DATE(date_sale) = '" . pSQL($checkDate) . "'
        ORDER BY accupos_transaction_id ASC
    ");
    
    outputTest('Транзакции в PrestaShop', 'success', 
        'Найдено транзакций: ' . count($psTransactions), array(
            'count' => count($psTransactions),
            'by_status' => array(
                'success' => count(array_filter($psTransactions, function($t) { return $t['status'] === 'success'; })),
                'error' => count(array_filter($psTransactions, function($t) { return $t['status'] === 'error'; })),
                'skipped' => count(array_filter($psTransactions, function($t) { return $t['status'] === 'skipped'; }))
            )
        ));
    
} catch (Exception $e) {
    outputTest('Транзакции в PrestaShop', 'error', 'Ошибка при получении транзакций: ' . $e->getMessage());
    $psTransactions = array();
}

// 4.3. Сравнение данных
if (!empty($accuposTransactions) && !empty($psTransactions)) {
    $accuposIds = array();
    foreach ($accuposTransactions as $txn) {
        $accuposIds[$txn['transaction_id']] = $txn;
    }
    
    $psIds = array();
    foreach ($psTransactions as $txn) {
        $psIds[$txn['accupos_transaction_id']] = $txn;
    }
    
    $missingInPS = array();
    $missingInAccuPOS = array();
    $mismatched = array();
    
    // Транзакции в AccuPOS, но не в PrestaShop
    foreach ($accuposIds as $id => $txn) {
        if (!isset($psIds[$id])) {
            $missingInPS[] = $id;
        } else {
            // Проверка соответствия данных
            $psTxn = $psIds[$id];
            if ($psTxn['sku'] !== $txn['sku'] || 
                abs($psTxn['qty'] - $txn['qty']) > 0.001 ||
                $psTxn['terminal_id'] !== $txn['location']) {
                $mismatched[] = array(
                    'id' => $id,
                    'accupos' => $txn,
                    'prestashop' => $psTxn
                );
            }
        }
    }
    
    // Транзакции в PrestaShop, но не в AccuPOS
    foreach ($psIds as $id => $txn) {
        if (!isset($accuposIds[$id])) {
            $missingInAccuPOS[] = $id;
        }
    }
    
    if (empty($missingInPS) && empty($missingInAccuPOS) && empty($mismatched)) {
        outputTest('Сравнение данных AccuPOS ↔ PrestaShop', 'success', 
            'Все транзакции синхронизированы и соответствуют', array(
                'accupos_count' => count($accuposTransactions),
                'prestashop_count' => count($psTransactions),
                'match' => '100%'
            ));
    } else {
        outputTest('Сравнение данных AccuPOS ↔ PrestaShop', 'warning', 
            'Обнаружены расхождения', array(
                'missing_in_prestashop' => count($missingInPS),
                'missing_in_accupos' => count($missingInAccuPOS),
                'mismatched' => count($mismatched),
                'sample_missing_ps' => array_slice($missingInPS, 0, 10),
                'sample_mismatched' => array_slice($mismatched, 0, 5)
            ));
    }
}

echo "</div>";

// ============================================
// РАЗДЕЛ 5: ПРОВЕРКА МЕТОДОВ КЛАССОВ
// ============================================
echo "<div class='section'>";
echo "<h2>5. Проверка работы методов классов</h2>";

// 5.1. AccuPosDB
try {
    $db = new AccuPosDB();
    $testConnection = $db->testConnection();
    
    if ($testConnection) {
        outputTest('AccuPosDB::testConnection()', 'success', 'Метод работает корректно');
    } else {
        outputTest('AccuPosDB::testConnection()', 'error', 'Метод вернул false');
    }
} catch (Exception $e) {
    outputTest('AccuPosDB::testConnection()', 'error', 'Ошибка: ' . $e->getMessage());
}

// 5.2. AccuPosTerminalMapper
try {
    $testTerminal = 'TEST_TERMINAL_' . time();
    $addResult = AccuPosTerminalMapper::addTerminal($testTerminal, 1, 'Test Store', true);
    
    if ($addResult) {
        $warehouse = AccuPosTerminalMapper::getWarehouseByTerminal($testTerminal);
        
        if ($warehouse == 1) {
            outputTest('AccuPosTerminalMapper::addTerminal() и getWarehouseByTerminal()', 'success', 'Методы работают корректно');
        } else {
            outputTest('AccuPosTerminalMapper::getWarehouseByTerminal()', 'warning', 
                "Вернул warehouse_id = {$warehouse}, ожидалось 1");
        }
        
        // Удаление тестового терминала
        AccuPosTerminalMapper::removeTerminal($testTerminal);
    } else {
        outputTest('AccuPosTerminalMapper::addTerminal()', 'error', 'Не удалось добавить тестовый терминал');
    }
} catch (Exception $e) {
    outputTest('AccuPosTerminalMapper методы', 'error', 'Ошибка: ' . $e->getMessage());
}

// 5.3. AccuPosLogger
try {
    $logger = new AccuPosLogger();
    $logResult = $logger->logToFile('INFO', 'Test log message from diagnostic');
    
    if ($logResult !== false) {
        outputTest('AccuPosLogger::logToFile()', 'success', 'Метод работает корректно');
    } else {
        outputTest('AccuPosLogger::logToFile()', 'warning', 'Метод вернул false, но возможно это нормально');
    }
} catch (Exception $e) {
    outputTest('AccuPosLogger::logToFile()', 'error', 'Ошибка: ' . $e->getMessage());
}

echo "</div>";

// ============================================
// РАЗДЕЛ 6: ПРОВЕРКА БАЗЫ ДАННЫХ PRESTASHOP
// ============================================
echo "<div class='section'>";
echo "<h2>6. Проверка таблиц PrestaShop</h2>";

$requiredTables = array(
    'accupos_terminals',
    'accupos_sync_log',
    'accupos_transactions',
    'accupos_config'
);

foreach ($requiredTables as $table) {
    $fullTableName = _DB_PREFIX_ . $table;
    $exists = Db::getInstance()->getValue("
        SELECT COUNT(*) 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
        AND table_name = '" . pSQL($fullTableName) . "'
    ");
    
    if ($exists) {
        $count = Db::getInstance()->getValue("SELECT COUNT(*) FROM `{$fullTableName}`");
        outputTest("Таблица: {$fullTableName}", 'success', "Существует, записей: {$count}");
    } else {
        outputTest("Таблица: {$fullTableName}", 'error', 'Таблица не существует!');
    }
}

// Проверка последних синхронизаций
try {
    $lastSyncs = Db::getInstance()->executeS("
        SELECT * 
        FROM " . _DB_PREFIX_ . "accupos_sync_log 
        ORDER BY date_add DESC 
        LIMIT 5
    ");
    
    outputTest('Последние синхронизации', 'success', '', array(
        'last_syncs' => $lastSyncs
    ));
} catch (Exception $e) {
    outputTest('Последние синхронизации', 'error', 'Ошибка: ' . $e->getMessage());
}

echo "</div>";

// ============================================
// РАЗДЕЛ 7: ПРОВЕРКА ОБНОВЛЕНИЯ ОСТАТКОВ
// ============================================
echo "<div class='section'>";
echo "<h2>7. Проверка обновления остатков товаров</h2>";

// Проверка движений товаров за 3 декабря
try {
    $stockMovements = Db::getInstance()->executeS("
        SELECT 
            sm.id_stock_mvt,
            sm.id_stock,
            sm.physical_quantity,
            sm.sign,
            sm.date_add,
            s.id_product,
            s.id_warehouse,
            p.reference,
            p.ean13
        FROM " . _DB_PREFIX_ . "stock_mvt sm
        INNER JOIN " . _DB_PREFIX_ . "stock s ON sm.id_stock = s.id_stock
        INNER JOIN " . _DB_PREFIX_ . "product p ON s.id_product = p.id_product
        WHERE DATE(sm.date_add) = '" . pSQL($checkDate) . "'
            AND sm.id_stock_mvt_reason = 11
            AND sm.employee_firstname = 'System'
            AND sm.employee_lastname = 'AccuPOS'
        ORDER BY sm.date_add DESC
        LIMIT 50
    ");
    
    if (!empty($stockMovements)) {
        outputTest('Движения товаров (stock_mvt)', 'success', 
            'Найдено движений: ' . count($stockMovements), array(
                'count' => count($stockMovements),
                'sample' => array_slice($stockMovements, 0, 5)
            ));
    } else {
        outputTest('Движения товаров (stock_mvt)', 'warning', 
            'Не найдено движений товаров за ' . $checkDate);
    }
} catch (Exception $e) {
    outputTest('Движения товаров (stock_mvt)', 'error', 'Ошибка: ' . $e->getMessage());
}

echo "</div>";

// ============================================
// ИТОГОВАЯ СТАТИСТИКА
// ============================================
$successCount = 0;
$warningCount = 0;
$errorCount = 0;

foreach ($results['tests'] as $test) {
    switch ($test['status']) {
        case 'success':
            $successCount++;
            break;
        case 'warning':
            $warningCount++;
            break;
        case 'error':
            $errorCount++;
            break;
    }
}

$totalTests = count($results['tests']);

echo "<div class='section'>";
echo "<h2>📊 Итоговая статистика</h2>";
echo "<table>";
echo "<tr><th>Статус</th><th>Количество</th><th>Процент</th></tr>";
echo "<tr><td class='success'>✓ Успешно</td><td>{$successCount}</td><td>" . round(($successCount / $totalTests) * 100, 1) . "%</td></tr>";
echo "<tr><td class='warning'>⚠ Предупреждения</td><td>{$warningCount}</td><td>" . round(($warningCount / $totalTests) * 100, 1) . "%</td></tr>";
echo "<tr><td class='error'>✗ Ошибки</td><td>{$errorCount}</td><td>" . round(($errorCount / $totalTests) * 100, 1) . "%</td></tr>";
echo "<tr><th>Всего тестов</th><th>{$totalTests}</th><th>100%</th></tr>";
echo "</table>";
echo "</div>";

// Сохранение результатов в JSON
$jsonFile = dirname(__FILE__) . '/diagnostic_results_' . $checkDate . '.json';
file_put_contents($jsonFile, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "<div class='summary'>";
echo "<strong>Результаты сохранены в:</strong> " . basename($jsonFile);
echo "</div>";

?>

</body>
</html>

