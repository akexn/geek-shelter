<?php
/**
 * AccuPOS Sync Module
 * 
 * Синхронизация продаж из AccuPOS cloud с PrestaShop
 * Автоматическое обновление остатков товаров по складам
 * 
 * @author    Aleksei Nekrasov <info@impserver.ru>
 * @copyright 2025 ООО «Свобода» / impserver.ru
 * @license   Proprietary
 * @version   0.1.0
 * @link      https://impserver.ru
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Подключение классов модуля
require_once dirname(__FILE__) . '/classes/AccuPosDB.php';
require_once dirname(__FILE__) . '/classes/AccuPosSync.php';
require_once dirname(__FILE__) . '/classes/AccuPosLogger.php';
require_once dirname(__FILE__) . '/classes/AccuPosTerminalMapper.php';

/**
 * Главный класс модуля AccuPOS Sync
 */
class AccuPos_Sync extends Module
{
    /**
     * Конструктор модуля
     */
    public function __construct()
    {
        $this->name = 'accupos_sync';
        $this->tab = 'administration';
        $this->version = '0.1.0';
        $this->author = 'Aleksei Nekrasov (impserver.ru)';
        $this->need_instance = 1;
        $this->ps_versions_compliancy = array('min' => '1.7.8.0', 'max' => '9.99.99');
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('AccuPOS Sync');
        $this->description = $this->l('Синхронизация продаж AccuPOS с PrestaShop для автоматического обновления остатков товаров по складам');
        $this->confirmUninstall = $this->l('Вы уверены, что хотите удалить модуль AccuPOS Sync? Все данные синхронизации будут удалены.');
    }

    /**
     * Установка модуля
     * 
     * @return bool Успешность установки
     */
    public function install()
    {
        // Установка родительского модуля
        if (!parent::install()) {
            return false;
        }

        // Создание таблиц БД
        if (!$this->installDb()) {
            return false;
        }

        // Гарантируем корректные уникальные индексы для защиты от дублей
        if (!$this->ensureAccuposTransactionIndex()) {
            return false;
        }

        // Создание директорий для логов
        if (!$this->createLogDirectories()) {
            return false;
        }

        // Установка значений по умолчанию
        $this->setDefaultConfiguration();

        // Регистрация hooks (если потребуются в будущем)
        // $this->registerHook('actionOrderStatusUpdate');

        return true;
    }

    /**
     * Обновление индексов таблицы accupos_transactions для защиты от дублей
     *
     * @return bool
     */
    private function ensureAccuposTransactionIndex()
    {
        $table = _DB_PREFIX_ . 'accupos_transactions';

        try {
            // Удаляем устаревший уникальный индекс по одному полю, если он есть
            $oldIndex = Db::getInstance()->executeS("SHOW INDEX FROM `" . bqSQL($table) . "` WHERE Key_name = 'accupos_transaction_id'");

            if (!empty($oldIndex)) {
                Db::getInstance()->execute('ALTER TABLE `' . bqSQL($table) . '` DROP INDEX `accupos_transaction_id`');
            }

            // Создаём уникальный индекс по (transaction_id, terminal, sku)
            $newIndex = Db::getInstance()->executeS("SHOW INDEX FROM `" . bqSQL($table) . "` WHERE Key_name = 'accupos_txn_loc_sku'");

            if (empty($newIndex)) {
                Db::getInstance()->execute(
                    'ALTER TABLE `' . bqSQL($table) . '` ADD UNIQUE KEY `accupos_txn_loc_sku` (`accupos_transaction_id`,`terminal_id`,`sku`)'
                );
            }

            return true;
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'AccuPOS Sync: Ошибка обновления индексов accupos_transactions - ' . $e->getMessage(),
                4,
                null,
                'AccuPos_Sync'
            );

            return false;
        }
    }

    /**
     * Удаление модуля
     * 
     * @return bool Успешность удаления
     */
    public function uninstall()
    {
        // Удаление таблиц БД
        if (!$this->uninstallDb()) {
            return false;
        }

        // Удаление конфигурации
        $this->deleteConfiguration();

        // Удаление родительского модуля
        if (!parent::uninstall()) {
            return false;
        }

        return true;
    }

    /**
     * Создание таблиц БД
     * 
     * @return bool Успешность создания
     */
    private function installDb()
    {
        $sql = array();

        // Таблица 1: Маппинг терминалов AccuPOS → склады PrestaShop
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'accupos_terminals` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `terminal_id` VARCHAR(64) NOT NULL,
            `warehouse_id` INT(11) UNSIGNED NOT NULL,
            `store_name` VARCHAR(128) DEFAULT NULL,
            `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `terminal_id` (`terminal_id`),
            KEY `warehouse_id` (`warehouse_id`),
            KEY `active` (`active`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // Таблица 2: Лог синхронизаций
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'accupos_sync_log` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `sync_timestamp` DATETIME NOT NULL,
            `last_transaction_id` VARCHAR(128) DEFAULT NULL,
            `transactions_processed` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `transactions_success` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `transactions_error` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `status` ENUM(\'running\',\'completed\',\'failed\') NOT NULL DEFAULT \'running\',
            `error_message` TEXT DEFAULT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `sync_timestamp` (`sync_timestamp`),
            KEY `status` (`status`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // Таблица 3: Уникальные транзакции AccuPOS (защита от дублей)
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'accupos_transactions` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `accupos_transaction_id` VARCHAR(128) NOT NULL,
            `terminal_id` VARCHAR(64) NOT NULL,
            `warehouse_id` INT(11) UNSIGNED DEFAULT NULL,
            `sku` VARCHAR(64) NOT NULL,
            `qty` DECIMAL(10,3) NOT NULL,
            `status` ENUM(\'success\',\'error\',\'skipped\') NOT NULL DEFAULT \'success\',
            `error_message` TEXT DEFAULT NULL,
            `date_sale` DATETIME NOT NULL,
            `date_processed` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `accupos_txn_loc_sku` (`accupos_transaction_id`, `terminal_id`, `sku`),
            KEY `accupos_transaction_id` (`accupos_transaction_id`),
            KEY `terminal_id` (`terminal_id`),
            KEY `sku` (`sku`),
            KEY `status` (`status`),
            KEY `date_sale` (`date_sale`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // Таблица 4: Конфигурация модуля (зашифрованная)
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'accupos_config` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `config_key` VARCHAR(128) NOT NULL,
            `config_value` TEXT DEFAULT NULL,
            `is_encrypted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `config_key` (`config_key`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // Выполнение SQL запросов
        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                PrestaShopLogger::addLog(
                    'AccuPOS Sync: Ошибка создания таблиц БД - ' . Db::getInstance()->getMsgError(),
                    4,
                    null,
                    'AccuPos_Sync'
                );
                return false;
            }
        }

        return true;
    }

    /**
     * Удаление таблиц БД
     * 
     * @return bool Успешность удаления
     */
    private function uninstallDb()
    {
        $sql = array(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'accupos_terminals`',
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'accupos_sync_log`',
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'accupos_transactions`',
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'accupos_config`'
        );

        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                PrestaShopLogger::addLog(
                    'AccuPOS Sync: Ошибка удаления таблиц БД - ' . Db::getInstance()->getMsgError(),
                    4,
                    null,
                    'AccuPos_Sync'
                );
                return false;
            }
        }

        return true;
    }

    /**
     * Создание директорий для логов
     * 
     * @return bool Успешность создания
     */
    private function createLogDirectories()
    {
        $logDir = _PS_ROOT_DIR_ . '/var/logs/accupos';
        
        if (!file_exists($logDir)) {
            if (!mkdir($logDir, 0755, true)) {
                PrestaShopLogger::addLog(
                    'AccuPOS Sync: Не удалось создать директорию логов: ' . $logDir,
                    3,
                    null,
                    'AccuPos_Sync'
                );
                return false;
            }
        }

        return true;
    }

    /**
     * Установка значений конфигурации по умолчанию
     */
    private function setDefaultConfiguration()
    {
        Configuration::updateValue('ACCUPOS_ENABLE_CRON', 1);
        Configuration::updateValue('ACCUPOS_CRON_TIME', '02:00');
        Configuration::updateValue('ACCUPOS_DEFAULT_WAREHOUSE', 1);
        Configuration::updateValue('ACCUPOS_SYNC_WINDOW_DAYS', 7);
        Configuration::updateValue('ACCUPOS_ENABLE_REPORTS', 1);
        Configuration::updateValue('ACCUPOS_ADMIN_EMAIL', Configuration::get('PS_SHOP_EMAIL'));
        Configuration::updateValue('ACCUPOS_REPORT_FORMAT', 'csv_html');
    }

    /**
     * Удаление конфигурации
     */
    private function deleteConfiguration()
    {
        Configuration::deleteByName('ACCUPOS_ENABLE_CRON');
        Configuration::deleteByName('ACCUPOS_CRON_TIME');
        Configuration::deleteByName('ACCUPOS_DEFAULT_WAREHOUSE');
        Configuration::deleteByName('ACCUPOS_SYNC_WINDOW_DAYS');
        Configuration::deleteByName('ACCUPOS_ENABLE_REPORTS');
        Configuration::deleteByName('ACCUPOS_ADMIN_EMAIL');
        Configuration::deleteByName('ACCUPOS_REPORT_FORMAT');
        Configuration::deleteByName('ACCUPOS_DB_HOST');
        Configuration::deleteByName('ACCUPOS_DB_PORT');
        Configuration::deleteByName('ACCUPOS_DB_NAME');
        Configuration::deleteByName('ACCUPOS_DB_USER');
        Configuration::deleteByName('ACCUPOS_DB_PASS');
    }

    /**
     * Получение содержимого страницы конфигурации
     * 
     * @return string HTML содержимое
     */
    public function getContent()
    {
        $output = '';

        // Обработка отправки формы
        if (Tools::isSubmit('submitAccuPosConfig')) {
            $output .= $this->processConfiguration();
        }

        // Обработка кнопки "Test Connection"
        if (Tools::isSubmit('testAccuPosConnection')) {
            $output .= $this->testConnection();
        }

        // Обработка кнопки "Manual Sync" 
        // ОТКЛЮЧЕНА синхронная обработка - используем асинхронный AJAX вместо этого!
        // See: ajax_sync.php и views/js/async_sync.js
        // if (Tools::isSubmit('runManualSync')) {
        //     $output .= $this->runManualSync();
        // }

        // Обработка добавления терминала
        if (Tools::isSubmit('submitAddTerminal')) {
            $output .= $this->processAddTerminal();
        }

        // Обработка удаления терминала
        if (Tools::isSubmit('deleteTerminal')) {
            $output .= $this->processDeleteTerminal();
        }

        // Обработка активации/деактивации терминала
        if (Tools::isSubmit('toggleTerminal')) {
            $output .= $this->processToggleTerminal();
        }

        // Загружаем JS для асинхронной синхронизации
        $this->context->controller->addJs($this->_path . 'views/js/async_sync.js');
        
        // Загружаем CSS для интерфейса мониторинга
        $this->context->controller->addCss($this->_path . 'views/css/accupos_admin.css');

        // Отображение формы конфигурации
        $output .= $this->displayConfigurationForm();

        // Отображение статуса синхронизации
        $output .= '<div id="accupos-sync-status"></div>';

        // Отображение мониторинга транзакций
        $output .= $this->displayTransactionMonitoring();

        // Отображение управления терминалами
        $output .= $this->displayTerminalManagement();

        return $output;
    }

    /**
     * Обработка сохранения конфигурации
     * 
     * @return string HTML сообщение
     */
    private function processConfiguration()
    {
        // Получение значений из формы
        $dbHost = Tools::getValue('ACCUPOS_DB_HOST');
        $dbPort = Tools::getValue('ACCUPOS_DB_PORT');
        $dbName = Tools::getValue('ACCUPOS_DB_NAME');
        $dbUser = Tools::getValue('ACCUPOS_DB_USER');
        $dbPass = Tools::getValue('ACCUPOS_DB_PASS');
        
        $enableCron = Tools::getValue('ACCUPOS_ENABLE_CRON');
        $cronTime = Tools::getValue('ACCUPOS_CRON_TIME');
        $defaultWarehouse = Tools::getValue('ACCUPOS_DEFAULT_WAREHOUSE');
        $syncWindowDays = Tools::getValue('ACCUPOS_SYNC_WINDOW_DAYS');
        $enableReports = Tools::getValue('ACCUPOS_ENABLE_REPORTS');
        $adminEmail = Tools::getValue('ACCUPOS_ADMIN_EMAIL');
        $reportFormat = Tools::getValue('ACCUPOS_REPORT_FORMAT');

        // Валидация
        if (empty($dbHost) || empty($dbName) || empty($dbUser)) {
            return $this->displayError($this->l('Заполните все обязательные поля подключения к AccuPOS'));
        }

        if (!Validate::isEmail($adminEmail)) {
            return $this->displayError($this->l('Некорректный email адрес администратора'));
        }

        // Кодирование и сохранение учётных данных (base64)
        Configuration::updateValue('ACCUPOS_DB_HOST', $dbHost);
        Configuration::updateValue('ACCUPOS_DB_PORT', $dbPort);
        Configuration::updateValue('ACCUPOS_DB_NAME', $dbName);
        Configuration::updateValue('ACCUPOS_DB_USER', base64_encode($dbUser));
        
        if (!empty($dbPass)) {
            Configuration::updateValue('ACCUPOS_DB_PASS', base64_encode($dbPass));
        }

        // Сохранение остальных настроек
        Configuration::updateValue('ACCUPOS_ENABLE_CRON', $enableCron);
        Configuration::updateValue('ACCUPOS_CRON_TIME', $cronTime);
        Configuration::updateValue('ACCUPOS_DEFAULT_WAREHOUSE', $defaultWarehouse);
        Configuration::updateValue('ACCUPOS_SYNC_WINDOW_DAYS', $syncWindowDays);
        Configuration::updateValue('ACCUPOS_ENABLE_REPORTS', $enableReports);
        Configuration::updateValue('ACCUPOS_ADMIN_EMAIL', $adminEmail);
        Configuration::updateValue('ACCUPOS_REPORT_FORMAT', $reportFormat);

        return $this->displayConfirmation($this->l('Настройки успешно сохранены'));
    }

    /**
     * Тестирование подключения к AccuPOS
     * 
     * @return string HTML сообщение
     */
    private function testConnection()
    {
        try {
            $db = new AccuPosDB();
            $connection = $db->connect();
            
            if ($connection) {
                return $this->displayConfirmation($this->l('Подключение к AccuPOS успешно установлено!'));
            } else {
                return $this->displayError($this->l('Не удалось подключиться к AccuPOS. Проверьте настройки.'));
            }
        } catch (Exception $e) {
            return $this->displayError($this->l('Ошибка подключения: ') . $e->getMessage());
        }
    }

    /**
     * Запуск ручной синхронизации
     * 
     * @return string HTML сообщение
     */
    private function runManualSync()
    {
        // Ручная синхронизация - только текущий день
        $output = '';
        
        try {
            // Загрузка класса синхронизации
            require_once(_PS_MODULE_DIR_ . $this->name . '/classes/AccuPosSync.php');
            
            $sync = new AccuPosSync();
            $sync->setManualSyncMode(); // Устанавливает дату на сегодня (00:00)
            
            // Запуск синхронизации
            $result = $sync->sync();
            
            // Отображение результата
            if ($result['success']) {
                $output = $this->displayConfirmation(sprintf(
                    '✅ Синхронизация завершена!<br>' .
                    'Обработано: %d<br>' .
                    'Успешно: %d<br>' .
                    'Ошибок: %d<br>' .
                    'Пропущено: %d<br>' .
                    'Сообщение: %s',
                    $result['processed'],
                    $result['success_count'],
                    $result['error_count'],
                    $result['skipped_count'],
                    $result['message']
                ));
            } else {
                $output = $this->displayError(
                    '❌ Ошибка синхронизации: ' . $result['message']
                );
            }
        } catch (Exception $e) {
            $output = $this->displayError(
                '❌ Исключение: ' . $e->getMessage()
            );
        }
        
        return $output;
    }

    /**
     * Отображение формы конфигурации
     * 
     * @return string HTML форма
     */
    private function displayConfigurationForm()
    {
        // Получение списка складов
        $warehouses = Warehouse::getWarehouses(true);
        $warehouseOptions = array();
        
        foreach ($warehouses as $warehouse) {
            $warehouseOptions[] = array(
                'id' => $warehouse['id_warehouse'],
                'name' => $warehouse['name']
            );
        }

        // Поля формы
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Настройки AccuPOS Sync'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    // Раздел: Подключение к AccuPOS
                    array(
                        'type' => 'html',
                        'name' => '',
                        'html_content' => '<h4>' . $this->l('Подключение к AccuPOS') . '</h4><hr>'
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Host'),
                        'name' => 'ACCUPOS_DB_HOST',
                        'required' => true,
                        'desc' => $this->l('Хост базы данных AccuPOS (например: cloud.accupos.com)')
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Port'),
                        'name' => 'ACCUPOS_DB_PORT',
                        'required' => true,
                        'desc' => $this->l('Порт подключения (обычно 3306)')
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Database'),
                        'name' => 'ACCUPOS_DB_NAME',
                        'required' => true,
                        'desc' => $this->l('Имя схемы БД AccuPOS')
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Username'),
                        'name' => 'ACCUPOS_DB_USER',
                        'required' => true,
                        'desc' => $this->l('Имя пользователя БД (хранится в зашифрованном виде)')
                    ),
                    array(
                        'type' => 'password',
                        'label' => $this->l('Password'),
                        'name' => 'ACCUPOS_DB_PASS',
                        'desc' => $this->l('Пароль БД (хранится в зашифрованном виде, оставьте пустым чтобы не менять)')
                    ),
                    
                    // Раздел: Настройки синхронизации
                    array(
                        'type' => 'html',
                        'name' => '',
                        'html_content' => '<h4 style="margin-top:30px;">' . $this->l('Настройки синхронизации') . '</h4><hr>'
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Включить CRON'),
                        'name' => 'ACCUPOS_ENABLE_CRON',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'active_on', 'value' => 1, 'label' => $this->l('Да')),
                            array('id' => 'active_off', 'value' => 0, 'label' => $this->l('Нет'))
                        )
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Время CRON'),
                        'name' => 'ACCUPOS_CRON_TIME',
                        'desc' => $this->l('Время запуска синхронизации (формат HH:MM, например 02:00)')
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Склад по умолчанию'),
                        'name' => 'ACCUPOS_DEFAULT_WAREHOUSE',
                        'options' => array(
                            'query' => $warehouseOptions,
                            'id' => 'id',
                            'name' => 'name'
                        ),
                        'desc' => $this->l('Склад для терминалов без явного маппинга')
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Окно синхронизации (дни)'),
                        'name' => 'ACCUPOS_SYNC_WINDOW_DAYS',
                        'desc' => $this->l('Сколько дней назад синхронизировать транзакции')
                    ),
                    
                    // Раздел: Отчёты
                    array(
                        'type' => 'html',
                        'name' => '',
                        'html_content' => '<h4 style="margin-top:30px;">' . $this->l('Отчёты') . '</h4><hr>'
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Включить ежедневные отчёты'),
                        'name' => 'ACCUPOS_ENABLE_REPORTS',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'reports_on', 'value' => 1, 'label' => $this->l('Да')),
                            array('id' => 'reports_off', 'value' => 0, 'label' => $this->l('Нет'))
                        )
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Email администратора'),
                        'name' => 'ACCUPOS_ADMIN_EMAIL',
                        'desc' => $this->l('Email для получения отчётов об ошибках')
                    ),
                    array(
                        'type' => 'radio',
                        'label' => $this->l('Формат отчёта'),
                        'name' => 'ACCUPOS_REPORT_FORMAT',
                        'values' => array(
                            array('id' => 'csv_html', 'value' => 'csv_html', 'label' => $this->l('CSV + HTML email')),
                            array('id' => 'json', 'value' => 'json', 'label' => $this->l('JSON только'))
                        )
                    )
                ),
                'submit' => array(
                    'title' => $this->l('Сохранить'),
                    'class' => 'btn btn-default pull-right'
                ),
                'buttons' => array(
                    array(
                        'title' => $this->l('Тест подключения'),
                        'name' => 'testAccuPosConnection',
                        'type' => 'submit',
                        'class' => 'btn btn-info',
                        'icon' => 'process-icon-refresh'
                    ),
                    array(
                        'title' => $this->l('Принудительная синхронизация'),
                        'id' => 'accupos-manual-sync-btn',
                        'name' => 'runManualSync',
                        'type' => 'button',
                        'class' => 'btn btn-success',
                        'icon' => 'process-icon-refresh',
                        'onclick' => 'startAsyncSync(); return false;'
                    )
                )
            )
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitAccuPosConfig';
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');

        // Значения полей
        $helper->fields_value['ACCUPOS_DB_HOST'] = Configuration::get('ACCUPOS_DB_HOST');
        $helper->fields_value['ACCUPOS_DB_PORT'] = Configuration::get('ACCUPOS_DB_PORT');
        $helper->fields_value['ACCUPOS_DB_NAME'] = Configuration::get('ACCUPOS_DB_NAME');
        
        // Декодирование username (только для отображения)
        $encryptedUser = Configuration::get('ACCUPOS_DB_USER');
        if (!empty($encryptedUser)) {
            try {
                $helper->fields_value['ACCUPOS_DB_USER'] = base64_decode($encryptedUser);
            } catch (Exception $e) {
                $helper->fields_value['ACCUPOS_DB_USER'] = '';
            }
        } else {
            $helper->fields_value['ACCUPOS_DB_USER'] = '';
        }
        
        $helper->fields_value['ACCUPOS_DB_PASS'] = ''; // Никогда не показываем пароль
        $helper->fields_value['ACCUPOS_ENABLE_CRON'] = Configuration::get('ACCUPOS_ENABLE_CRON');
        $helper->fields_value['ACCUPOS_CRON_TIME'] = Configuration::get('ACCUPOS_CRON_TIME');
        $helper->fields_value['ACCUPOS_DEFAULT_WAREHOUSE'] = Configuration::get('ACCUPOS_DEFAULT_WAREHOUSE');
        $helper->fields_value['ACCUPOS_SYNC_WINDOW_DAYS'] = Configuration::get('ACCUPOS_SYNC_WINDOW_DAYS');
        $helper->fields_value['ACCUPOS_ENABLE_REPORTS'] = Configuration::get('ACCUPOS_ENABLE_REPORTS');
        $helper->fields_value['ACCUPOS_ADMIN_EMAIL'] = Configuration::get('ACCUPOS_ADMIN_EMAIL');
        $helper->fields_value['ACCUPOS_REPORT_FORMAT'] = Configuration::get('ACCUPOS_REPORT_FORMAT');

        return $helper->generateForm(array($fields_form));
    }

    /**
     * Публичный метод для запуска синхронизации из CRON
     * 
     * @return array Результат синхронизации
     */
    public function runSync()
    {
        try {
            $sync = new AccuPosSync();
            
            // 🕐 CRON СИНХРОНИЗАЦИЯ: только вчерашний день (00:00 - 23:59)
            $sync->setCronSyncMode();
            
            return $sync->sync();
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'AccuPOS Sync CRON: ' . $e->getMessage(),
                4,
                null,
                'AccuPos_Sync'
            );
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }

    /**
     * Обработка добавления терминала
     * 
     * @return string HTML сообщение
     */
    private function processAddTerminal()
    {
        $terminalId = Tools::getValue('terminal_id');
        $warehouseId = Tools::getValue('warehouse_id');
        $storeName = Tools::getValue('store_name');
        $active = Tools::getValue('active') ? 1 : 0;

        // Валидация
        if (empty($terminalId)) {
            return $this->displayError($this->l('Введите идентификатор терминала (LocationCode)'));
        }

        if (empty($warehouseId)) {
            return $this->displayError($this->l('Выберите склад'));
        }

        // Проверка существования склада
        if (!AccuPosTerminalMapper::warehouseExists($warehouseId)) {
            return $this->displayError($this->l('Выбранный склад не существует'));
        }

        // Добавление терминала
        if (AccuPosTerminalMapper::addTerminal($terminalId, $warehouseId, $storeName, $active)) {
            return $this->displayConfirmation(
                sprintf(
                    $this->l('Терминал "%s" успешно добавлен/обновлён и привязан к складу %d'),
                    $terminalId,
                    $warehouseId
                )
            );
        } else {
            return $this->displayError($this->l('Ошибка при добавлении терминала'));
        }
    }

    /**
     * Обработка удаления терминала
     * 
     * @return string HTML сообщение
     */
    private function processDeleteTerminal()
    {
        $terminalId = Tools::getValue('terminal_id');

        if (empty($terminalId)) {
            return $this->displayError($this->l('ID терминала не указан'));
        }

        if (AccuPosTerminalMapper::removeTerminal($terminalId)) {
            return $this->displayConfirmation(
                sprintf($this->l('Терминал "%s" успешно удалён'), $terminalId)
            );
        } else {
            return $this->displayError($this->l('Ошибка при удалении терминала'));
        }
    }

    /**
     * Обработка активации/деактивации терминала
     * 
     * @return string HTML сообщение
     */
    private function processToggleTerminal()
    {
        $terminalId = Tools::getValue('terminal_id');
        $action = Tools::getValue('action');

        if (empty($terminalId)) {
            return $this->displayError($this->l('ID терминала не указан'));
        }

        $result = false;
        $message = '';

        if ($action === 'activate') {
            $result = AccuPosTerminalMapper::activateTerminal($terminalId);
            $message = sprintf($this->l('Терминал "%s" активирован'), $terminalId);
        } elseif ($action === 'deactivate') {
            $result = AccuPosTerminalMapper::deactivateTerminal($terminalId);
            $message = sprintf($this->l('Терминал "%s" деактивирован'), $terminalId);
        }

        if ($result) {
            return $this->displayConfirmation($message);
        } else {
            return $this->displayError($this->l('Ошибка при изменении статуса терминала'));
        }
    }

    /**
     * Отображение интерфейса управления терминалами
     * 
     * @return string HTML контент
     */
    private function displayTerminalManagement()
    {
        $output = '';

        // Заголовок секции
        $output .= '<div class="panel" style="margin-top: 20px;">';
        $output .= '<div class="panel-heading">';
        $output .= '<i class="icon-cogs"></i> ' . $this->l('Управление терминалами AccuPOS');
        $output .= '</div>';

        // Форма добавления терминала
        $output .= '<div class="panel-body">';
        $output .= '<h4>' . $this->l('Добавить новый терминал') . '</h4>';
        $output .= '<form method="post" class="form-horizontal">';
        
        $output .= '<div class="form-group">';
        $output .= '<label class="control-label col-lg-3">' . $this->l('LocationCode (Terminal ID)') . '</label>';
        $output .= '<div class="col-lg-9">';
        $output .= '<input type="text" name="terminal_id" class="form-control" placeholder="dizengof, HAIFA, Jerusalem..." required />';
        $output .= '<p class="help-block">' . $this->l('Введите точное название локации из AccuPOS (регистр важен!)') . '</p>';
        $output .= '</div>';
        $output .= '</div>';

        // Выбор склада
        $warehouses = Warehouse::getWarehouses(true);
        $output .= '<div class="form-group">';
        $output .= '<label class="control-label col-lg-3">' . $this->l('Склад PrestaShop') . '</label>';
        $output .= '<div class="col-lg-9">';
        $output .= '<select name="warehouse_id" class="form-control" required>';
        $output .= '<option value="">' . $this->l('-- Выберите склад --') . '</option>';
        
        foreach ($warehouses as $warehouse) {
            $output .= sprintf(
                '<option value="%d">%s (%s)</option>',
                $warehouse['id_warehouse'],
                htmlspecialchars($warehouse['name']),
                htmlspecialchars($warehouse['reference'])
            );
        }
        
        $output .= '</select>';
        $output .= '</div>';
        $output .= '</div>';

        // Название магазина
        $output .= '<div class="form-group">';
        $output .= '<label class="control-label col-lg-3">' . $this->l('Название магазина') . '</label>';
        $output .= '<div class="col-lg-9">';
        $output .= '<input type="text" name="store_name" class="form-control" placeholder="Тель-Авив, Хайфа, Иерусалим..." />';
        $output .= '<p class="help-block">' . $this->l('Опционально: для удобства отображения') . '</p>';
        $output .= '</div>';
        $output .= '</div>';

        // Активен
        $output .= '<div class="form-group">';
        $output .= '<label class="control-label col-lg-3">' . $this->l('Активен') . '</label>';
        $output .= '<div class="col-lg-9">';
        $output .= '<span class="switch prestashop-switch fixed-width-lg">';
        $output .= '<input type="radio" name="active" id="active_on" value="1" checked="checked" />';
        $output .= '<label for="active_on">' . $this->l('Да') . '</label>';
        $output .= '<input type="radio" name="active" id="active_off" value="0" />';
        $output .= '<label for="active_off">' . $this->l('Нет') . '</label>';
        $output .= '<a class="slide-button btn"></a>';
        $output .= '</span>';
        $output .= '</div>';
        $output .= '</div>';

        // Кнопка добавления
        $output .= '<div class="form-group">';
        $output .= '<div class="col-lg-9 col-lg-offset-3">';
        $output .= '<button type="submit" name="submitAddTerminal" class="btn btn-success">';
        $output .= '<i class="icon-plus"></i> ' . $this->l('Добавить терминал');
        $output .= '</button>';
        $output .= '</div>';
        $output .= '</div>';

        $output .= '</form>';
        $output .= '<hr />';

        // Список существующих терминалов
        $output .= '<h4>' . $this->l('Настроенные терминалы') . '</h4>';
        
        $terminals = AccuPosTerminalMapper::getAllTerminals(false);
        
        if (empty($terminals)) {
            $output .= '<div class="alert alert-warning">';
            $output .= '<i class="icon-warning"></i> ' . $this->l('Нет настроенных терминалов. Добавьте первый терминал выше.');
            $output .= '</div>';
        } else {
            $output .= '<table class="table table-bordered">';
            $output .= '<thead>';
            $output .= '<tr>';
            $output .= '<th>' . $this->l('LocationCode') . '</th>';
            $output .= '<th>' . $this->l('Название магазина') . '</th>';
            $output .= '<th>' . $this->l('Склад PrestaShop') . '</th>';
            $output .= '<th>' . $this->l('Статус') . '</th>';
            $output .= '<th>' . $this->l('Дата добавления') . '</th>';
            $output .= '<th>' . $this->l('Действия') . '</th>';
            $output .= '</tr>';
            $output .= '</thead>';
            $output .= '<tbody>';

            foreach ($terminals as $terminal) {
                $output .= '<tr>';
                $output .= '<td><strong>' . htmlspecialchars($terminal['terminal_id']) . '</strong></td>';
                $output .= '<td>' . htmlspecialchars($terminal['store_name']) . '</td>';
                $output .= '<td>';
                $output .= sprintf(
                    'ID %d: %s',
                    $terminal['warehouse_id'],
                    htmlspecialchars($terminal['warehouse_name'])
                );
                $output .= '</td>';
                
                // Статус
                $output .= '<td>';
                if ($terminal['active']) {
                    $output .= '<span class="badge badge-success">' . $this->l('Активен') . '</span>';
                } else {
                    $output .= '<span class="badge badge-danger">' . $this->l('Неактивен') . '</span>';
                }
                $output .= '</td>';
                
                $output .= '<td>' . date('d.m.Y H:i', strtotime($terminal['date_add'])) . '</td>';
                
                // Действия
                $output .= '<td>';
                $output .= '<div class="btn-group">';
                
                // Кнопка активации/деактивации
                if ($terminal['active']) {
                    $output .= sprintf(
                        '<a href="%s&toggleTerminal=1&terminal_id=%s&action=deactivate" class="btn btn-default btn-xs" title="%s">',
                        AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                        urlencode($terminal['terminal_id']),
                        $this->l('Деактивировать')
                    );
                    $output .= '<i class="icon-remove"></i>';
                    $output .= '</a>';
                } else {
                    $output .= sprintf(
                        '<a href="%s&toggleTerminal=1&terminal_id=%s&action=activate" class="btn btn-default btn-xs" title="%s">',
                        AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                        urlencode($terminal['terminal_id']),
                        $this->l('Активировать')
                    );
                    $output .= '<i class="icon-check"></i>';
                    $output .= '</a>';
                }
                
                // Кнопка удаления
                $output .= sprintf(
                    '<a href="%s&deleteTerminal=1&terminal_id=%s" class="btn btn-danger btn-xs" onclick="return confirm(\'%s\');" title="%s">',
                    AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                    urlencode($terminal['terminal_id']),
                    $this->l('Вы уверены, что хотите удалить этот терминал?'),
                    $this->l('Удалить')
                );
                $output .= '<i class="icon-trash"></i>';
                $output .= '</a>';
                
                $output .= '</div>';
                $output .= '</td>';
                $output .= '</tr>';
            }

            $output .= '</tbody>';
            $output .= '</table>';
        }

        $output .= '</div>'; // panel-body
        $output .= '</div>'; // panel

        return $output;
    }

    /**
     * Получение статистики транзакций за конкретный день
     * 
     * @param string $date Дата для анализа ('today', 'yesterday', или конкретная дата Y-m-d)
     * @return array Статистика транзакций
     */
    private function getTransactionStatistics($date = 'yesterday')
    {
        // Определение даты для фильтрации
        if ($date === 'today') {
            $dateFrom = date('Y-m-d 00:00:00');
            $dateTo = date('Y-m-d 23:59:59');
            $periodLabel = 'Сегодня';
        } elseif ($date === 'yesterday') {
            $dateFrom = date('Y-m-d 00:00:00', strtotime('-1 day'));
            $dateTo = date('Y-m-d 23:59:59', strtotime('-1 day'));
            $periodLabel = 'Вчера';
        } else {
            // Конкретная дата в формате Y-m-d
            $dateFrom = $date . ' 00:00:00';
            $dateTo = $date . ' 23:59:59';
            $periodLabel = date('d.m.Y', strtotime($date));
        }
        
        $sql = 'SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = \'success\' THEN 1 ELSE 0 END) as success_count,
                    SUM(CASE WHEN status = \'error\' THEN 1 ELSE 0 END) as error_count,
                    SUM(CASE WHEN status = \'skipped\' THEN 1 ELSE 0 END) as skipped_count,
                    MIN(date_processed) as first_sync,
                    MAX(date_processed) as last_sync
                FROM ' . _DB_PREFIX_ . 'accupos_transactions
                WHERE DATE(date_sale) >= DATE(\'' . pSQL($dateFrom) . '\')
                AND DATE(date_sale) <= DATE(\'' . pSQL($dateTo) . '\')';
        
        $result = Db::getInstance()->getRow($sql);
        
        if (!$result) {
            return array(
                'total' => 0,
                'success_count' => 0,
                'error_count' => 0,
                'skipped_count' => 0,
                'first_sync' => null,
                'last_sync' => null,
                'success_rate' => 0,
                'period_label' => $periodLabel,
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            );
        }
        
        // Расчёт процента успешности
        $successRate = 0;
        if ($result['total'] > 0) {
            $successRate = round(($result['success_count'] / $result['total']) * 100, 2);
        }
        
        $result['success_rate'] = $successRate;
        $result['period_label'] = $periodLabel;
        $result['date_from'] = $dateFrom;
        $result['date_to'] = $dateTo;
        
        return $result;
    }

    /**
     * Получение списка проблемных транзакций
     * 
     * @param int $limit Лимит записей (по умолчанию 50)
     * @param int $offset Смещение для пагинации (по умолчанию 0)
     * @param string $statusFilter Фильтр по статусу ('all', 'error', 'skipped')
     * @param string $dateFrom Дата начала периода (Y-m-d H:i:s)
     * @param string $dateTo Дата окончания периода (Y-m-d H:i:s)
     * @return array Список транзакций
     */
    private function getFailedTransactions($limit = 50, $offset = 0, $statusFilter = 'all', $dateFrom = null, $dateTo = null)
    {
        $whereClauses = array();
        
        // Фильтр по статусу
        if ($statusFilter === 'error') {
            $whereClauses[] = 't.status = \'error\'';
        } elseif ($statusFilter === 'skipped') {
            $whereClauses[] = 't.status = \'skipped\'';
        } elseif ($statusFilter === 'failed') {
            $whereClauses[] = 't.status IN (\'error\', \'skipped\')';
        }
        
        // Фильтр по дате (используем date_sale - дату продажи, а не date_processed)
        if ($dateFrom && $dateTo) {
            $whereClauses[] = 'DATE(t.date_sale) >= DATE(\'' . pSQL($dateFrom) . '\')';
            $whereClauses[] = 'DATE(t.date_sale) <= DATE(\'' . pSQL($dateTo) . '\')';
        }
        
        $whereClause = '';
        if (!empty($whereClauses)) {
            $whereClause = 'WHERE ' . implode(' AND ', $whereClauses);
        }
        
        $sql = 'SELECT 
                    t.id,
                    t.accupos_transaction_id,
                    t.terminal_id,
                    t.warehouse_id,
                    t.sku,
                    t.qty,
                    t.status,
                    t.error_message,
                    t.date_sale,
                    t.date_processed,
                    w.name as warehouse_name
                FROM ' . _DB_PREFIX_ . 'accupos_transactions t
                LEFT JOIN ' . _DB_PREFIX_ . 'warehouse w ON t.warehouse_id = w.id_warehouse
                ' . $whereClause . '
                ORDER BY t.date_processed DESC
                LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        
        $transactions = Db::getInstance()->executeS($sql);
        
        if (!$transactions) {
            return array();
        }
        
        return $transactions;
    }

    /**
     * Получение количества проблемных транзакций для пагинации
     * 
     * @param string $statusFilter Фильтр по статусу
     * @param string $dateFrom Дата начала периода (Y-m-d H:i:s)
     * @param string $dateTo Дата окончания периода (Y-m-d H:i:s)
     * @return int Количество записей
     */
    private function getFailedTransactionsCount($statusFilter = 'all', $dateFrom = null, $dateTo = null)
    {
        $whereClauses = array();
        
        // Фильтр по статусу
        if ($statusFilter === 'error') {
            $whereClauses[] = 'status = \'error\'';
        } elseif ($statusFilter === 'skipped') {
            $whereClauses[] = 'status = \'skipped\'';
        } elseif ($statusFilter === 'failed') {
            $whereClauses[] = 'status IN (\'error\', \'skipped\')';
        }
        
        // Фильтр по дате (используем date_sale - дату продажи, а не date_processed)
        if ($dateFrom && $dateTo) {
            $whereClauses[] = 'DATE(date_sale) >= DATE(\'' . pSQL($dateFrom) . '\')';
            $whereClauses[] = 'DATE(date_sale) <= DATE(\'' . pSQL($dateTo) . '\')';
        }
        
        $whereClause = '';
        if (!empty($whereClauses)) {
            $whereClause = 'WHERE ' . implode(' AND ', $whereClauses);
        }
        
        $sql = 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'accupos_transactions ' . $whereClause;
        
        return (int)Db::getInstance()->getValue($sql);
    }

    /**
     * Отображение панели мониторинга транзакций
     * 
     * @return string HTML контент
     */
    private function displayTransactionMonitoring()
    {
        $output = '';
        
        // Получение параметров из GET
        $showPeriod = Tools::getValue('show_period', 'yesterday'); // 'today' или 'yesterday'
        $statusFilter = Tools::getValue('status_filter', 'failed');
        $page = (int)Tools::getValue('page', 1);
        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        
        // Получение статистики за выбранный период
        // По умолчанию: вчерашний день (как CRON)
        // Можно переключить на сегодняшний день
        $stats = $this->getTransactionStatistics($showPeriod);
        
        // Получение проблемных транзакций за выбранный период
        $transactions = $this->getFailedTransactions(
            $perPage, 
            $offset, 
            $statusFilter,
            $stats['date_from'],
            $stats['date_to']
        );
        
        $totalCount = $this->getFailedTransactionsCount(
            $statusFilter,
            $stats['date_from'],
            $stats['date_to']
        );
        
        $totalPages = ceil($totalCount / $perPage);
        
        // Начало панели
        $output .= '<div class="panel" style="margin-top: 20px;">';
        $output .= '<div class="panel-heading">';
        $output .= '<i class="icon-bar-chart"></i> ' . $this->l('Мониторинг транзакций AccuPOS');
        $output .= '</div>';
        $output .= '<div class="panel-body">';
        
        // Переключатель периода
        $baseUrl = AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules');
        
        $output .= '<div class="alert alert-info" style="margin-bottom: 15px;">';
        $output .= '<div class="row">';
        $output .= '<div class="col-md-8">';
        $output .= '<strong><i class="icon-info-circle"></i> ' . $this->l('Период статистики:') . '</strong> ';
        $output .= '<span style="font-size: 16px;">' . $stats['period_label'] . '</span> ';
        $output .= '<small>(' . date('d.m.Y', strtotime($stats['date_from'])) . ')</small>';
        $output .= '</div>';
        $output .= '<div class="col-md-4 text-right">';
        $output .= '<div class="btn-group">';
        
        // Кнопка "Вчера"
        $yesterdayClass = ($showPeriod === 'yesterday') ? 'btn-primary' : 'btn-default';
        $output .= sprintf(
            '<a href="%s&show_period=yesterday" class="btn btn-sm %s">%s</a>',
            $baseUrl,
            $yesterdayClass,
            $this->l('Вчера')
        );
        
        // Кнопка "Сегодня"
        $todayClass = ($showPeriod === 'today') ? 'btn-primary' : 'btn-default';
        $output .= sprintf(
            '<a href="%s&show_period=today" class="btn btn-sm %s">%s</a>',
            $baseUrl,
            $todayClass,
            $this->l('Сегодня')
        );
        
        $output .= '</div>';
        $output .= '</div>';
        $output .= '</div>';
        $output .= '</div>';
        
        // Статистика (карточки)
        $output .= '<div class="row" style="margin-bottom: 20px;">';
        
        // Карточка: Всего транзакций
        $output .= '<div class="col-md-3">';
        $output .= '<div class="panel panel-default">';
        $output .= '<div class="panel-body text-center">';
        $output .= '<h3 class="text-primary">' . (int)$stats['total'] . '</h3>';
        $output .= '<p>' . $this->l('Всего транзакций') . '<br><small>' . $stats['period_label'] . '</small></p>';
        $output .= '</div>';
        $output .= '</div>';
        $output .= '</div>';
        
        // Карточка: Успешные
        $output .= '<div class="col-md-3">';
        $output .= '<div class="panel panel-success">';
        $output .= '<div class="panel-body text-center">';
        $output .= '<h3 class="text-success">' . (int)$stats['success_count'] . '</h3>';
        $output .= '<p>' . $this->l('Успешно') . '<br><small>' . number_format($stats['success_rate'], 1) . '% ' . $this->l('успешности') . '</small></p>';
        $output .= '</div>';
        $output .= '</div>';
        $output .= '</div>';
        
        // Карточка: Ошибки
        $output .= '<div class="col-md-3">';
        $output .= '<div class="panel panel-danger">';
        $output .= '<div class="panel-body text-center">';
        $output .= '<h3 class="text-danger">' . (int)$stats['error_count'] . '</h3>';
        $output .= '<p>' . $this->l('Ошибки') . '<br><small>' . $this->l('Требуют внимания') . '</small></p>';
        $output .= '</div>';
        $output .= '</div>';
        $output .= '</div>';
        
        // Карточка: Пропущенные
        $output .= '<div class="col-md-3">';
        $output .= '<div class="panel panel-warning">';
        $output .= '<div class="panel-body text-center">';
        $output .= '<h3 class="text-warning">' . (int)$stats['skipped_count'] . '</h3>';
        $output .= '<p>' . $this->l('Пропущено') . '<br><small>' . $this->l('Дубликаты') . '</small></p>';
        $output .= '</div>';
        $output .= '</div>';
        $output .= '</div>';
        
        $output .= '</div>'; // row
        
        // Последняя синхронизация
        if ($stats['last_sync']) {
            $output .= '<div class="alert alert-info">';
            $output .= '<strong><i class="icon-clock-o"></i> ' . $this->l('Последняя синхронизация:') . '</strong> ';
            $output .= date('d.m.Y H:i:s', strtotime($stats['last_sync']));
            $output .= '</div>';
        }
        
        $output .= '<hr />';
        
        // Фильтры
        $output .= '<h4>' . $this->l('Детали транзакций') . '</h4>';
        $output .= '<div class="row" style="margin-bottom: 15px;">';
        $output .= '<div class="col-md-6">';
        $output .= '<div class="btn-group">';
        
        $baseUrl = AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules');
        
        // Кнопки фильтрации
        $filters = array(
            'all' => $this->l('Все'),
            'failed' => $this->l('Проблемные'),
            'error' => $this->l('Ошибки'),
            'skipped' => $this->l('Пропущенные')
        );
        
        foreach ($filters as $filter => $label) {
            $activeClass = ($statusFilter === $filter) ? 'btn-primary' : 'btn-default';
            $output .= sprintf(
                '<a href="%s&status_filter=%s&show_period=%s&page=1" class="btn %s">%s</a>',
                $baseUrl,
                $filter,
                $showPeriod,
                $activeClass,
                $label
            );
        }
        
        $output .= '</div>';
        $output .= '</div>';
        
        // Счётчик найденных
        $output .= '<div class="col-md-6 text-right">';
        $output .= '<p style="margin-top: 7px;"><strong>' . $this->l('Найдено:') . '</strong> ' . $totalCount . ' ' . $this->l('записей') . '</p>';
        $output .= '</div>';
        $output .= '</div>'; // row
        
        // Таблица транзакций
        if (empty($transactions)) {
            $output .= '<div class="alert alert-success">';
            $output .= '<i class="icon-check"></i> ' . $this->l('Отлично! Нет проблемных транзакций.');
            $output .= '</div>';
        } else {
            $output .= '<div class="table-responsive">';
            $output .= '<table class="table table-bordered table-hover">';
            $output .= '<thead>';
            $output .= '<tr>';
            $output .= '<th style="width: 60px;">' . $this->l('ID') . '</th>';
            $output .= '<th style="width: 80px;">' . $this->l('Дата продажи') . '</th>';
            $output .= '<th>' . $this->l('Терминал') . '</th>';
            $output .= '<th>' . $this->l('Склад') . '</th>';
            $output .= '<th style="width: 120px;">' . $this->l('SKU') . '</th>';
            $output .= '<th style="width: 60px;">' . $this->l('Кол-во') . '</th>';
            $output .= '<th style="width: 80px;">' . $this->l('Статус') . '</th>';
            $output .= '<th>' . $this->l('Причина') . '</th>';
            $output .= '</tr>';
            $output .= '</thead>';
            $output .= '<tbody>';
            
            foreach ($transactions as $transaction) {
                $output .= '<tr>';
                
                // ID
                $output .= '<td>' . (int)$transaction['id'] . '</td>';
                
                // Дата продажи
                $output .= '<td>' . date('d.m.Y<br>H:i', strtotime($transaction['date_sale'])) . '</td>';
                
                // Терминал
                $output .= '<td><strong>' . htmlspecialchars($transaction['terminal_id']) . '</strong></td>';
                
                // Склад
                $warehouseName = $transaction['warehouse_name'] ? htmlspecialchars($transaction['warehouse_name']) : $this->l('Не определен');
                $output .= '<td>' . $warehouseName . '</td>';
                
                // SKU
                $output .= '<td><code>' . htmlspecialchars($transaction['sku']) . '</code></td>';
                
                // Количество
                $qtyClass = $transaction['qty'] < 0 ? 'text-success' : 'text-danger';
                $output .= '<td class="' . $qtyClass . '"><strong>' . $transaction['qty'] . '</strong></td>';
                
                // Статус
                $output .= '<td>';
                if ($transaction['status'] === 'success') {
                    $output .= '<span class="badge badge-success" style="background-color: #5cb85c;">' . $this->l('Успех') . '</span>';
                } elseif ($transaction['status'] === 'error') {
                    $output .= '<span class="badge badge-danger" style="background-color: #d9534f;">' . $this->l('Ошибка') . '</span>';
                } else {
                    $output .= '<span class="badge badge-warning" style="background-color: #f0ad4e;">' . $this->l('Пропущено') . '</span>';
                }
                $output .= '</td>';
                
                // Причина
                $errorMessage = $transaction['error_message'] ? htmlspecialchars($transaction['error_message']) : '-';
                
                // Перевод типичных ошибок
                $errorMessage = str_replace('Product not found by SKU:', $this->l('Товар не найден по SKU:'), $errorMessage);
                $errorMessage = str_replace('Failed to update stock', $this->l('Не удалось обновить остатки'), $errorMessage);
                $errorMessage = str_replace('Duplicate transaction', $this->l('Дубликат транзакции'), $errorMessage);
                $errorMessage = str_replace('Exception:', $this->l('Исключение:'), $errorMessage);
                
                $output .= '<td><small>' . $errorMessage . '</small></td>';
                
                $output .= '</tr>';
            }
            
            $output .= '</tbody>';
            $output .= '</table>';
            $output .= '</div>'; // table-responsive
            
            // Пагинация
            if ($totalPages > 1) {
                $output .= '<div class="row">';
                $output .= '<div class="col-md-12 text-center">';
                $output .= '<ul class="pagination">';
                
                // Предыдущая страница
                if ($page > 1) {
                    $output .= sprintf(
                        '<li><a href="%s&status_filter=%s&show_period=%s&page=%d">&laquo; ' . $this->l('Назад') . '</a></li>',
                        $baseUrl,
                        $statusFilter,
                        $showPeriod,
                        $page - 1
                    );
                }
                
                // Страницы
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                if ($startPage > 1) {
                    $output .= sprintf(
                        '<li><a href="%s&status_filter=%s&show_period=%s&page=1">1</a></li>',
                        $baseUrl,
                        $statusFilter,
                        $showPeriod
                    );
                    if ($startPage > 2) {
                        $output .= '<li class="disabled"><span>...</span></li>';
                    }
                }
                
                for ($i = $startPage; $i <= $endPage; $i++) {
                    $activeClass = ($i === $page) ? 'active' : '';
                    $output .= sprintf(
                        '<li class="%s"><a href="%s&status_filter=%s&show_period=%s&page=%d">%d</a></li>',
                        $activeClass,
                        $baseUrl,
                        $statusFilter,
                        $showPeriod,
                        $i,
                        $i
                    );
                }
                
                if ($endPage < $totalPages) {
                    if ($endPage < $totalPages - 1) {
                        $output .= '<li class="disabled"><span>...</span></li>';
                    }
                    $output .= sprintf(
                        '<li><a href="%s&status_filter=%s&show_period=%s&page=%d">%d</a></li>',
                        $baseUrl,
                        $statusFilter,
                        $showPeriod,
                        $totalPages,
                        $totalPages
                    );
                }
                
                // Следующая страница
                if ($page < $totalPages) {
                    $output .= sprintf(
                        '<li><a href="%s&status_filter=%s&show_period=%s&page=%d">' . $this->l('Вперёд') . ' &raquo;</a></li>',
                        $baseUrl,
                        $statusFilter,
                        $showPeriod,
                        $page + 1
                    );
                }
                
                $output .= '</ul>';
                $output .= '</div>';
                $output .= '</div>';
            }
        }
        
        $output .= '</div>'; // panel-body
        $output .= '</div>'; // panel
        
        return $output;
    }
}

