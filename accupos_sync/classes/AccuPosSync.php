<?php
/**
 * AccuPOS Synchronization Engine
 * 
 * Основной алгоритм синхронизации продаж AccuPOS → PrestaShop
 * Обновление остатков товаров по складам
 * 
 * @author    Aleksei Nekrasov <info@impserver.ru>
 * @copyright 2025 ООО «Свобода» / impserver.ru
 * @license   Proprietary
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Класс синхронизации AccuPOS
 */
class AccuPosSync
{
    /**
     * @var AccuPosDB Подключение к AccuPOS
     */
    private $accuposDb;

    /**
     * @var AccuPosLogger Логгер
     */
    private $logger;

    /**
     * @var int ID текущего лога синхронизации
     */
    private $syncLogId;

    /**
     * @var int Лимит транзакций за один запуск
     */
    private $batchLimit = 1000;

    /**
     * @var string|null Кастомная дата начала синхронизации (для ручной синхронизации)
     */
    private $customSyncStartDate = null;

    /**
     * @var array Кэш товаров по SKU для ускорения поиска
     */
    private $productCache = array();

    /**
     * @var array Кэш id_stock по (product_id, warehouse_id)
     */
    private $stockCache = array();

    /**
     * @var array Кэш информации о товарах
     */
    private $productInfoCache = array();

    /**
     * @var bool Флаг для отключения подробного логирования (для скорости)
     */
    private $skipDetailedLogging = false;

    /**
     * Конструктор
     */
    public function __construct()
    {
        $this->accuposDb = new AccuPosDB();
        $this->logger = new AccuPosLogger();
    }

    /**
     * Установка даты начала синхронизации для РУЧНОЙ синхронизации (текущий день)
     * 
     * @return void
     */
    public function setManualSyncMode()
    {
        // Для ручной синхронизации - весь сегодняшний день от 00:00
        $this->customSyncStartDate = date('Y-m-d 00:00:00');
        // ОТКЛЮЧАЕМ подробное логирование для скорости
        $this->skipDetailedLogging = true;
    }

    /**
     * Установка даты начала синхронизации для CRON синхронизации (предыдущий день)
     * 
     * @return void
     */
    public function setCronSyncMode()
    {
        // Для CRON - весь вчерашний день от 00:00
        $this->customSyncStartDate = date('Y-m-d 00:00:00', strtotime('-1 day'));
        // Для CRON - логирование включено
        $this->skipDetailedLogging = false;
    }

    /**
     * Запуск синхронизации
     * 
     * @return array Результат синхронизации
     */
    public function sync()
    {
        $startTime = microtime(true);
        
        // Создание записи в логе синхронизации
        $this->syncLogId = $this->createSyncLog();

        $result = array(
            'success' => false,
            'processed' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'skipped_count' => 0,
            'message' => ''
        );

        try {
            // Шаг 1: Подключение к AccuPOS
            $this->accuposDb->connect();

            // Шаг 2: Определение последней обработанной транзакции
            $lastTransactionId = $this->getLastTransactionId();

            // Шаг 3: Получение окна синхронизации
            if ($this->customSyncStartDate) {
                // Если установлена кастомная дата - используем её
                $syncStartDate = $this->customSyncStartDate;
            } else {
                // Иначе используем окно из конфигурации (для обратной совместимости)
                $syncWindowDays = (int)Configuration::get('ACCUPOS_SYNC_WINDOW_DAYS', 7);
                $syncStartDate = date('Y-m-d H:i:s', strtotime('-' . $syncWindowDays . ' days'));
            }

            // Шаг 4: Получение новых транзакций из AccuPOS
            $transactions = $this->fetchAccuPosTransactions($lastTransactionId, $syncStartDate);

            if (empty($transactions)) {
                $result['success'] = true;
                $result['message'] = 'Нет новых транзакций для синхронизации';
                $this->updateSyncLog('completed', $result);
                return $result;
            }

            // Шаг 5: Обработка транзакций
            foreach ($transactions as $transaction) {
                $processResult = $this->processTransaction($transaction);
                
                $result['processed']++;
                
                if ($processResult['status'] === 'success') {
                    $result['success_count']++;
                } elseif ($processResult['status'] === 'error') {
                    $result['error_count']++;
                } elseif ($processResult['status'] === 'skipped') {
                    $result['skipped_count']++;
                }
            }

            // Шаг 6: Завершение синхронизации
            $result['success'] = true;
            $result['message'] = sprintf(
                'Синхронизация завершена за %.2f сек. Обработано: %d, Успешно: %d, Ошибок: %d, Пропущено: %d',
                microtime(true) - $startTime,
                $result['processed'],
                $result['success_count'],
                $result['error_count'],
                $result['skipped_count']
            );

            $this->updateSyncLog('completed', $result);

            // Шаг 7: Отправка отчётов (если включено)
            if (Configuration::get('ACCUPOS_ENABLE_REPORTS') && $result['error_count'] > 0) {
                $this->logger->sendDailyReport();
            }

        } catch (Exception $e) {
            $result['success'] = false;
            $result['message'] = 'Ошибка синхронизации: ' . $e->getMessage();
            
            PrestaShopLogger::addLog(
                'AccuPOS Sync Error: ' . $e->getMessage(),
                4,
                null,
                'AccuPosSync'
            );
            
            $this->updateSyncLog('failed', $result, $e->getMessage());
        }

        return $result;
    }

    /**
     * Получение транзакций из AccuPOS
     * 
     * @param string|null $lastTransactionId Последний обработанный ID
     * @param string $syncStartDate Начальная дата синхронизации
     * @return array Транзакции
     * @throws Exception
     */
    private function fetchAccuPosTransactions($lastTransactionId, $syncStartDate)
    {
        // Реальный запрос к AccuPOS на основе изученной схемы БД
        // Таблицы: apcshead (заголовки заказов), apcsitem (позиции заказов)
        
        $query = "
            SELECT 
                i.Id AS transaction_id,
                i.ItemID AS sku,
                i.Quantity AS qty,
                h.LocationCode AS location,
                h.DateInvoiced AS sale_date,
                h.Key AS order_key,
                h.InvNum AS invoice_num
            FROM apcsitem i
            INNER JOIN apcshead h ON i.HeadKey = h.Key
            WHERE h.DateInvoiced >= :sync_start_date
                AND h.DateInvoiced IS NOT NULL
                AND (h.Status IS NULL OR h.Status != 'Void')
                AND (i.Status IS NULL OR i.Status != 'V')
                AND i.Hidden = 0
                AND i.Quantity > 0
                AND i.isChoice = 0
        ";

        $params = array();
        $params[':sync_start_date'] = $syncStartDate;

        // Фильтр по ID транзакции (для пагинации)
        if ($lastTransactionId) {
            $query .= " AND i.Id > :last_id";
            $params[':last_id'] = (int)$lastTransactionId;
        }

        $query .= " ORDER BY i.Id ASC LIMIT " . (int)$this->batchLimit;

        try {
            $transactions = $this->accuposDb->query($query, $params);
            
            PrestaShopLogger::addLog(
                sprintf(
                    'AccuPOS Sync: Fetched %d transactions from AccuPOS (date >= %s, last_id: %s)',
                    count($transactions),
                    $syncStartDate,
                    $lastTransactionId ? $lastTransactionId : 'none'
                ),
                1,
                null,
                'AccuPosSync'
            );
            
            // Логирование в файл
            $this->logger->logToFile(
                'INFO',
                sprintf('Fetched %d transactions from AccuPOS', count($transactions))
            );
            
            return $transactions;
            
        } catch (Exception $e) {
            $errorMsg = 'Failed to fetch AccuPOS transactions: ' . $e->getMessage();
            
            PrestaShopLogger::addLog(
                'AccuPOS Sync Error: ' . $errorMsg,
                4,
                null,
                'AccuPosSync'
            );
            
            $this->logger->logToFile('ERROR', $errorMsg);
            
            throw new Exception($errorMsg);
        }
    }

    /**
     * Обработка одной транзакции
     * 
     * @param array $transaction Данные транзакции
     * @return array Результат обработки
     */
    private function processTransaction($transaction)
    {
        $result = array(
            'status' => 'error',
            'message' => ''
        );

        try {
            $transactionId = $transaction['transaction_id'];
            $location = $transaction['location']; // LocationCode из заголовка транзакции (apcshead.LocationCode)
            $sku = $transaction['sku'];
            $qty = (float)$transaction['qty'];
            $dateSale = $transaction['sale_date'];

            // ЛОГИРОВАНИЕ (только если не пропущено для оптимизации)
            if (!$this->skipDetailedLogging) {
                $this->logger->logToFile('INFO', sprintf(
                    'Processing transaction #%s: SKU=%s, Qty=%s, Location=%s',
                    $transactionId,
                    $sku,
                    $qty,
                    $location
                ));
            }

            // Шаг 1: Проверка на дубликат
            if ($this->isDuplicateTransaction($transactionId)) {
                $result['status'] = 'skipped';
                $result['message'] = 'Duplicate transaction';
                return $result;
            }

            // Шаг 2: Получение склада по location (терминалу)
            $warehouseId = AccuPosTerminalMapper::getWarehouseByTerminal($location);

            // Шаг 3: Поиск товара по SKU
            $productId = $this->findProductBySku($sku);
            
            if (!$this->skipDetailedLogging) {
                PrestaShopLogger::addLog(
                    sprintf('AccuPOS DEBUG: processTransaction - SKU=%s, ProductID=%s, Warehouse=%s, Qty=%s',
                        $sku, $productId ?: 'NULL', $warehouseId ?: 'NULL', $qty),
                    1, null, 'AccuPosSync'
                );
            }

            if (!$productId) {
                $result['status'] = 'error';
                $result['message'] = 'Product not found by SKU: ' . $sku;
                
                $this->logger->logTransaction(
                    $transactionId,
                    $location,
                    $warehouseId,
                    $sku,
                    $qty,
                    'error',
                    $result['message'],
                    $dateSale
                );

                // Уведомление об unmapped SKU
                $this->logger->logUnmappedSku($sku, $transactionId, $location);
                
                return $result;
            }

            // Шаг 4: Обновление остатков товара (передаём дату продажи для правильной даты движения)
            $updateResult = $this->updateProductStock($productId, $warehouseId, $qty, $dateSale);
            
            // ЛОГИРОВАНИЕ В БД (только если не пропущено для оптимизации)
            if (!$this->skipDetailedLogging) {
                $logData = array(
                    'transaction_id' => (int)$transactionId,
                    'product_id' => (int)$productId,
                    'warehouse_id' => (int)$warehouseId,
                    'sku' => pSQL($sku),
                    'qty' => (float)$qty,
                    'update_result' => $updateResult ? 1 : 0,
                    'status' => $updateResult ? 'processed' : 'failed',
                    'date_add' => date('Y-m-d H:i:s')
                );
                
                try {
                    Db::getInstance()->insert('accupos_debug_log', $logData);
                } catch (Exception $e) {
                    // таблица не существует, игнорируем
                }
            }

            if ($updateResult) {
                $result['status'] = 'success';
                $result['message'] = sprintf(
                    'Stock updated: Product %d, Warehouse %d, Qty -%s',
                    $productId,
                    $warehouseId,
                    $qty
                );
            } else {
                $result['status'] = 'error';
                $result['message'] = 'Failed to update stock';
            }

            // Шаг 5: Логирование транзакции
            $this->logger->logTransaction(
                $transactionId,
                $location,
                $warehouseId,
                $sku,
                $qty,
                $result['status'],
                $result['message'],
                $dateSale
            );

        } catch (Exception $e) {
            $result['status'] = 'error';
            $result['message'] = 'Exception: ' . $e->getMessage();
            
            if (!$this->skipDetailedLogging) {
                $this->logger->logToFile('ERROR', sprintf(
                    'Exception in transaction #%s: %s',
                    $transactionId,
                    $e->getMessage()
                ));
            }
        }

        return $result;
    }

    /**
     * Проверка, является ли транзакция дубликатом
     * 
     * @param string $transactionId ID транзакции AccuPOS
     * @return bool true если дубликат
     */
    private function isDuplicateTransaction($transactionId)
    {
        // ЭКСПЕРТНОЕ РЕШЕНИЕ: проверяем ТОЛЬКО успешно обработанные транзакции
        // если транзакция имеет статус 'error', она НЕ является дубликатом!
        
        // Используем INFORMATION_SCHEMA для поиска правильной таблицы
        $tables = Db::getInstance()->executeS("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES 
                                               WHERE TABLE_SCHEMA = DATABASE() 
                                               AND TABLE_NAME LIKE '%accupos\_transactions%'");
        
        if (empty($tables)) {
            return false; // Таблицу не нашли, не дубликат
        }
        
        $transactionTable = $tables[0]['TABLE_NAME'];
        
        // Проверяем только УСПЕШНЫЕ транзакции (status = 'success')
        $exists = Db::getInstance()->getValue(
            'SELECT id FROM `' . $transactionTable . '` WHERE accupos_transaction_id = ' . (int)$transactionId . ' AND status = \'success\''
        );

        return (bool)$exists;
    }

    /**
     * Поиск товара по SKU (reference, EAN13 или UPC) с кэшем
     * 
     * @param string $sku Артикул товара или баркод
     * @return int|false ID товара или false
     */
    private function findProductBySku($sku)
    {
        // Проверяем кэш
        if (isset($this->productCache[$sku])) {
            return $this->productCache[$sku];
        }

        // МАКСИМАЛЬНО ПРОСТОЕ РЕШЕНИЕ: ищем в product по ean13, reference или upc
        $safeSku = pSQL($sku);
        $result = false;
        
        // 1. Ищем в ps_product по EAN13
        $query = 'SELECT id_product FROM `' . _DB_PREFIX_ . 'product` WHERE ean13 = \'' . $safeSku . '\' AND active = 1';
        $productId = Db::getInstance()->getValue($query);
        
        if ($productId) {
            $result = (int)$productId;
        } else {
            // 2. Если не найден по ean13, ищем по UPC
            $query = 'SELECT id_product FROM `' . _DB_PREFIX_ . 'product` WHERE upc = \'' . $safeSku . '\' AND active = 1';
            $productId = Db::getInstance()->getValue($query);
            
            if ($productId) {
                $result = (int)$productId;
            }
        }
        
        // Сохраняем в кэш
        $this->productCache[$sku] = $result;
        
        return $result;
    }

    /**
     * Обновление остатков товара
     * 
     * @param int $productId ID товара
     * @param int $warehouseId ID склада
     * @param float $qty Количество (отрицательное для списания)
     * @param string|null $dateSale Дата продажи (для правильной даты движения)
     * @return bool Успешность обновления
     */
    private function updateProductStock($productId, $warehouseId, $qty, $dateSale = null)
    {
        try {
            // Проверка товара (минимальная валидация)
            if (!$productId || !$warehouseId) {
                return false;
            }
            
            // Списание товара (отрицательное значение)
            $quantityDelta = -(int)$qty;
            
            // Кэш ключ для id_stock
            $stockCacheKey = $productId . '_' . $warehouseId;
            
            // 1. Получение id_stock с кэшем
            if (!isset($this->stockCache[$stockCacheKey])) {
                // ПРОСТОЕ И НАДЕЖНОЕ РЕШЕНИЕ: используем явное имя таблицы с префиксом
                // БЕЗ LIMIT 1 (getValue() уже возвращает одно значение)
                $query = 'SELECT id_stock FROM `' . _DB_PREFIX_ . 'stock` WHERE id_product = ' . (int)$productId . ' AND id_warehouse = ' . (int)$warehouseId . ' AND id_product_attribute = 0';
                
                $idStock = Db::getInstance()->getValue($query);
                
                $this->stockCache[$stockCacheKey] = $idStock;
                
            } else {
                $idStock = $this->stockCache[$stockCacheKey];
            }
            
            if (!$idStock) {
                return false;
            }
            
            // 2. Обновление в ps_stock за один запрос (используем прямой UPDATE с математикой)
            $updateQuery = 'UPDATE `' . _DB_PREFIX_ . 'stock` SET physical_quantity = GREATEST(0, physical_quantity + ' . (int)$quantityDelta . ') WHERE id_stock = ' . (int)$idStock;
            $updateResult = Db::getInstance()->execute($updateQuery);
            
            // Проверка результата UPDATE
            if (!$updateResult) {
                return false;
            }
            
            // 3. Создание записи о движении товара (передаём дату продажи)
            $mvtResult = $this->createStockMovement($productId, $warehouseId, $quantityDelta, $idStock, $dateSale);
            
            if (!$mvtResult) {
                $this->logger->logToFile('WARN', 'updateProductStock: movement creation failed for product=' . $productId . ', warehouse=' . $warehouseId);
            }

            return (bool)$updateResult;
            
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Получение ID последней обработанной транзакции
     * 
     * @return string|null
     */
    private function getLastTransactionId()
    {
        // Получить последний обработанный transaction_id из БД
        $lastId = Db::getInstance()->getValue(
            'SELECT MAX(accupos_transaction_id) FROM ' . _DB_PREFIX_ . 'accupos_transactions'
        );
        
        if ($lastId) {
            PrestaShopLogger::addLog(
                sprintf('AccuPOS: Last processed transaction ID: %d', $lastId),
                1, null, 'AccuPosSync'
            );
        } else {
            PrestaShopLogger::addLog(
                'AccuPOS: No transactions processed yet, starting from beginning',
                1, null, 'AccuPosSync'
            );
        }
        
        return $lastId ? (int)$lastId : null;
    }

    /**
     * Создание записи лога синхронизации
     * 
     * @return int ID созданной записи
     */
    private function createSyncLog()
    {
        $data = array(
            'sync_timestamp' => date('Y-m-d H:i:s'),
            'status' => 'running',
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s')
        );

        Db::getInstance()->insert('accupos_sync_log', $data);
        
        return (int)Db::getInstance()->Insert_ID();
    }

    /**
     * Обновление записи лога синхронизации
     * 
     * @param string $status Статус ('completed', 'failed')
     * @param array $result Результат синхронизации
     * @param string|null $errorMessage Сообщение об ошибке
     */
    private function updateSyncLog($status, $result, $errorMessage = null)
    {
        $data = array(
            'status' => pSQL($status),
            'transactions_processed' => (int)$result['processed'],
            'transactions_success' => (int)$result['success_count'],
            'transactions_error' => (int)$result['error_count'],
            'date_upd' => date('Y-m-d H:i:s')
        );

        if ($errorMessage) {
            $data['error_message'] = pSQL($errorMessage);
        }

        // Обновление last_transaction_id (если есть успешные транзакции)
        if ($result['success_count'] > 0) {
            $lastProcessedId = Db::getInstance()->getValue('SELECT accupos_transaction_id FROM ' . _DB_PREFIX_ . 'accupos_transactions WHERE status = \'success\' ORDER BY date_processed DESC');
            
            if ($lastProcessedId) {
                $data['last_transaction_id'] = pSQL($lastProcessedId);
            }
        }

        Db::getInstance()->update(
            'accupos_sync_log',
            $data,
            'id = ' . (int)$this->syncLogId
        );
    }
    
    /**
     * Создание записи о движении товара (stock_mvt) - ОПТИМИЗИРОВАННАЯ версия
     * 
     * @param int $productId ID товара
     * @param int $warehouseId ID склада
     * @param float $qty Количество (уже инвертированное, отрицательное)
     * @param int|null $idStock ID stock (может быть передана из updateProductStock)
     * @param string|null $dateSale Дата продажи (используется для date_add движения, чтобы Shop Manager видел движения по правильной дате)
     */
    private function createStockMovement($productId, $warehouseId, $qty, $idStock = null, $dateSale = null)
    {
        try {
            // Если id_stock не передана, получаем её
            if (!$idStock) {
                // Используем явное имя таблицы с префиксом
                $idStock = Db::getInstance()->getValue(
                    'SELECT id_stock FROM `' . _DB_PREFIX_ . 'stock` WHERE id_product = ' . (int)$productId . ' AND id_warehouse = ' . (int)$warehouseId . ' AND id_product_attribute = 0'
                );
            }
            
            if (!$idStock) {
                return false;
            }
            
            // Получить default employee ID (обычно 1 - админ)
            $employeeId = 1;  // По умолчанию 1 (администратор)
            
            // КРИТИЧНО: Используем дату продажи для date_add движения
            // Это нужно, чтобы Shop Manager видел движения по дате продажи, а не по дате обработки CRON
            $movementDate = $dateSale ? $dateSale : date('Y-m-d H:i:s');
            
            // Проверяем, какой reason_id используется на сервере (13 вместо 11)
            // Используем reason_id = 13 (AccuPOS Sync), как на сервере
            $reasonId = 13; // 13 = "AccuPOS Sync" (на сервере используется этот)
            
            // Прямой INSERT в таблицу движений
            // Используем явное имя таблицы БЕЗ префикса (Db::insert() добавляет его сам)
            $mvtData = array(
                'id_stock' => (int)$idStock,
                'id_stock_mvt_reason' => $reasonId, // 13 = "AccuPOS Sync"
                'id_employee' => $employeeId,
                'employee_lastname' => 'AccuPOS',
                'employee_firstname' => 'System',
                'physical_quantity' => (int)abs($qty),
                'date_add' => $movementDate, // Используем дату продажи!
                'sign' => -1 // Отрицательное = списание (продажа)
            );
            
            // Вставляем в ps_stock_mvt
            $insertResult = Db::getInstance()->insert('stock_mvt', $mvtData);
            
            if (!$insertResult) {
                return false;
            }
            
            // Получить ID созданного движения
            $idStockMvt = Db::getInstance()->Insert_ID();
            
            if ($idStockMvt && $warehouseId) {
                // Привязать движение к складу через таблицу prestatill_stock_mvt_warehouse
                // Эта таблица нужна для ShopManager/PrestaStill чтобы показывать с какого склада движение
                try {
                    $warehouseData = array(
                        'id_stock_mvt' => (int)$idStockMvt,
                        'id_warehouse' => (int)$warehouseId
                    );
                    
                    // Пытаемся вставить в таблицу PrestaStill (без проверки существования)
                    Db::getInstance()->insert('prestatill_stock_mvt_warehouse', $warehouseData);
                } catch (Exception $e) {
                    // Если ошибка - игнорируем, движение всё равно создано
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            return false;
        }
    }
}

