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
        $this->version = '0.1.1';
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

        // Создание сотрудника AccuPOS Sync
        if (!$this->createAccuPosEmployee()) {
            return false;
        }

        // Создание причины движения "AccuPOS Sync"
        if (!$this->createStockMovementReason()) {
            return false;
        }

        // Установка значений по умолчанию
        $this->setDefaultConfiguration();

        // Создание вкладки в меню администратора
        if (!$this->installTab()) {
            return false;
        }

        // Регистрация hooks (если потребуются в будущем)
        // $this->registerHook('actionOrderStatusUpdate');

        return true;
    }

    /**
     * Создание вкладки в меню администратора
     * 
     * @return bool
     */
    private function installTab()
    {
        try {
            // Проверяем, существует ли уже вкладка
            $existingTab = Tab::getIdFromClassName('AdminAccuPosSync');
            if ($existingTab) {
                // Если вкладка уже существует — убедимся, что она находится на нужном уровне (на 1 уровень выше Modules)
                $desiredParentId = (int)Tab::getIdFromClassName('IMPROVE');
                if (!$desiredParentId) {
                    $modulesTabId = (int)Tab::getIdFromClassName('AdminParentModulesSf');
                    if ($modulesTabId) {
                        $modulesTab = new Tab($modulesTabId);
                        $desiredParentId = (int)$modulesTab->id_parent;
                    }
                }

                if ($desiredParentId) {
                    $tab = new Tab((int)$existingTab);
                    if ((int)$tab->id_parent !== (int)$desiredParentId) {
                        $tab->id_parent = (int)$desiredParentId;
                        $tab->save();
                    }
                }

                return true; // Вкладка уже существует
            }

            // Создаём новую вкладку
            $tab = new Tab();
            $tab->class_name = 'AdminAccuPosSync';
            $tab->module = $this->name;
            $tab->active = 1;
            
            // Родительская вкладка: IMPROVE (на уровень выше Modules)
            $parentTabId = (int)Tab::getIdFromClassName('IMPROVE');
            if (!$parentTabId) {
                // Fallback: берём родителя AdminParentModulesSf
                $modulesTabId = (int)Tab::getIdFromClassName('AdminParentModulesSf');
                if ($modulesTabId) {
                    $modulesTab = new Tab($modulesTabId);
                    $parentTabId = (int)$modulesTab->id_parent; // IMPROVE
                }
            }
            
            if (!$parentTabId) {
                // Fallback на Stock Management
                $parentTabId = (int)Tab::getIdFromClassName('AdminStock');
            }
            if (!$parentTabId) {
                // Fallback на Catalog
                $parentTabId = (int)Tab::getIdFromClassName('AdminCatalog');
            }
            
            $tab->id_parent = $parentTabId;
            $tab->icon = 'sync'; // Material Design icon
            
            // Переводы названия вкладки
            $languages = Language::getLanguages(false);
            foreach ($languages as $lang) {
                $tab->name[(int)$lang['id_lang']] = 'AccuPOS Sync';
            }
            
            if ($tab->add()) {
                PrestaShopLogger::addLog(
                    'AccuPOS Sync: Вкладка меню создана',
                    1,
                    null,
                    'AccuPos_Sync'
                );
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'AccuPOS Sync: Ошибка создания вкладки меню - ' . $e->getMessage(),
                3,
                null,
                'AccuPos_Sync'
            );
            return false;
        }
    }

    /**
     * Удаление вкладки из меню администратора
     * 
     * @return bool
     */
    private function uninstallTab()
    {
        try {
            $tabId = (int)Tab::getIdFromClassName('AdminAccuPosSync');
            if ($tabId) {
                $tab = new Tab($tabId);
                return $tab->delete();
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
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
        // Удаление вкладки из меню
        $this->uninstallTab();

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
     * Создание сотрудника "AccuPOS Sync" для движений товара
     * 
     * @return bool
     */
    private function createAccuPosEmployee()
    {
        try {
            // Проверяем, существует ли уже сотрудник
            $existingEmployee = Db::getInstance()->getValue('
                SELECT id_employee 
                FROM ' . _DB_PREFIX_ . 'employee 
                WHERE email = \'accupos@geek-shelter.com\'
            ');

            if ($existingEmployee) {
                // Сотрудник уже существует - сохраняем его ID
                Configuration::updateValue('ACCUPOS_EMPLOYEE_ID', (int)$existingEmployee);
                
                PrestaShopLogger::addLog(
                    'AccuPOS Sync: Сотрудник AccuPOS Sync уже существует (ID=' . $existingEmployee . ')',
                    1,
                    null,
                    'AccuPos_Sync'
                );
                
                return true;
            }

            // Создаём нового сотрудника
            $employee = new Employee();
            $employee->firstname = 'AccuPOS';
            $employee->lastname = 'Sync';
            $employee->email = 'accupos@geek-shelter.com';
            $employee->passwd = Tools::hash(_COOKIE_KEY_ . 'accupos_sync_' . time());
            $employee->id_profile = 1; // SuperAdmin (нужен для создания движений)
            $employee->active = 1;
            $employee->id_lang = (int)Configuration::get('PS_LANG_DEFAULT');

            if ($employee->add()) {
                // Сохраняем ID в конфигурацию
                Configuration::updateValue('ACCUPOS_EMPLOYEE_ID', (int)$employee->id);
                
                PrestaShopLogger::addLog(
                    'AccuPOS Sync: Создан сотрудник AccuPOS Sync (ID=' . $employee->id . ')',
                    1,
                    null,
                    'AccuPos_Sync'
                );
                
                return true;
            }

            PrestaShopLogger::addLog(
                'AccuPOS Sync: Не удалось создать сотрудника AccuPOS Sync',
                3,
                null,
                'AccuPos_Sync'
            );

            return false;

        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'AccuPOS Sync: Ошибка создания сотрудника - ' . $e->getMessage(),
                4,
                null,
                'AccuPos_Sync'
            );

            return false;
        }
    }

    /**
     * Создание причины движения "AccuPOS Sync" (ID=13)
     * 
     * @return bool
     */
    private function createStockMovementReason()
    {
        try {
            $reasonId = 13;

            // Проверяем, существует ли уже причина
            $existingReason = Db::getInstance()->getValue('
                SELECT id_stock_mvt_reason 
                FROM ' . _DB_PREFIX_ . 'stock_mvt_reason 
                WHERE id_stock_mvt_reason = ' . (int)$reasonId
            );

            if ($existingReason) {
                // На всякий случай фиксируем в конфигурации корректный reason_id для AccuPOS движений
                Configuration::updateValue('ACCUPOS_REASON_ID', (int)$reasonId);

                PrestaShopLogger::addLog(
                    'AccuPOS Sync: Причина движения "AccuPOS Sync" уже существует (ID=' . $reasonId . ')',
                    1,
                    null,
                    'AccuPos_Sync'
                );
                
                return true;
            }

            // Создаём причину движения
            $result = Db::getInstance()->insert('stock_mvt_reason', [
                'id_stock_mvt_reason' => (int)$reasonId,
                'sign' => -1, // По умолчанию списание (для продаж)
                'date_add' => date('Y-m-d H:i:s'),
                'date_upd' => date('Y-m-d H:i:s'),
                'deleted' => 0
            ]);

            if (!$result) {
                throw new Exception('Не удалось вставить запись в stock_mvt_reason');
            }

            // Добавляем переводы для всех языков
            $languages = Language::getLanguages(false);
            $translations = [
                1 => 'Синхронизация AccuPOS',     // Русский
                2 => 'סנכרון AccuPOS',              // Иврит  
                3 => 'AccuPOS Sync'                 // English
            ];

            foreach ($languages as $lang) {
                $langId = (int)$lang['id_lang'];
                $name = isset($translations[$langId]) ? $translations[$langId] : 'AccuPOS Sync';
                
                Db::getInstance()->insert('stock_mvt_reason_lang', [
                    'id_stock_mvt_reason' => (int)$reasonId,
                    'id_lang' => $langId,
                    'name' => pSQL($name)
                ]);
            }

            // Запоминаем reason_id в конфигурации, чтобы синхронизация всегда ставила правильный тип движения
            Configuration::updateValue('ACCUPOS_REASON_ID', (int)$reasonId);

            PrestaShopLogger::addLog(
                'AccuPOS Sync: Создана причина движения "AccuPOS Sync" (ID=' . $reasonId . ')',
                1,
                null,
                'AccuPos_Sync'
            );

            return true;

        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'AccuPOS Sync: Ошибка создания причины движения - ' . $e->getMessage(),
                4,
                null,
                'AccuPos_Sync'
            );

            return false;
        }
    }

    /**
     * Установка значений конфигурации по умолчанию
     */
    private function setDefaultConfiguration()
    {
        Configuration::updateValue('ACCUPOS_ENABLE_CRON', 1);
        Configuration::updateValue('ACCUPOS_CRON_TIME', '02:00');
        // Новый формат расписания: диспетчер cron запускается часто, периодичность задаётся тут
        Configuration::updateValue('ACCUPOS_CRON_INTERVAL_MINUTES', 10);
        Configuration::updateValue('ACCUPOS_CRON_SYNC_MODE', 'today'); // today|yesterday
        Configuration::updateValue('ACCUPOS_CRON_LAST_RUN_TS', 0);
        Configuration::updateValue('ACCUPOS_CRON_LOCK_TS', 0);
        Configuration::updateValue('ACCUPOS_DEFAULT_WAREHOUSE', 1);
        Configuration::updateValue('ACCUPOS_SYNC_WINDOW_DAYS', 7);
        Configuration::updateValue('ACCUPOS_SKU_EXCLUSIONS', '');
        Configuration::updateValue('ACCUPOS_INVENTORY_EXCLUDE_EAN13', '');
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
        Configuration::deleteByName('ACCUPOS_CRON_INTERVAL_MINUTES');
        Configuration::deleteByName('ACCUPOS_CRON_SYNC_MODE');
        Configuration::deleteByName('ACCUPOS_CRON_LAST_RUN_TS');
        Configuration::deleteByName('ACCUPOS_CRON_LOCK_TS');
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
        if (Tools::isSubmit('submitAccuPosCron')) {
            $output .= $this->processCronConfiguration();
        }
        if (Tools::isSubmit('submitAccuPosSyncSettings')) {
            $output .= $this->processSyncSettings();
        }

        // Обработка кнопки "Test Connection"
        if (Tools::isSubmit('testAccuPosConnection')) {
            $output .= $this->testConnection();
        }

        // Тестовая отправка email отчёта
        if (Tools::isSubmit('sendAccuPosTestReport')) {
            try {
                // Явно подключаем класс из модуля (модульные классы не всегда автолоадятся в AdminModules)
                require_once dirname(__FILE__) . '/classes/AccuPosLogger.php';
                $logger = new AccuPosLogger();
                $ok = $logger->sendTestEmail();
                if ($ok) {
                    $output .= $this->displayConfirmation($this->l('Тестовое письмо отправлено. Проверьте inbox/spam и логи.'));
                } else {
                    $output .= $this->displayError($this->l('Не удалось отправить тестовое письмо. Проверьте настройки почты PrestaShop и логи модуля.'));
                }
            } catch (Exception $e) {
                $output .= $this->displayError($this->l('Ошибка отправки тестового письма: ') . $e->getMessage());
            }
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
        // JS для отчёта инвентаризации (поиск/сортировка/печать)
        $this->context->controller->addJs($this->_path . 'views/js/inventory_report.js');
        
        // Загружаем CSS для интерфейса мониторинга
        $this->context->controller->addCss($this->_path . 'views/css/accupos_admin.css');

        // Табы интерфейса
        $activeTab = (string)Tools::getValue('accupos_tab', 'dashboard'); // dashboard|sync|settings
        if (!in_array($activeTab, array('dashboard', 'sync', 'settings'), true)) {
            $activeTab = 'dashboard';
        }

        $output .= $this->renderTabsNavigation($activeTab);
        $output .= '<div class="accupos-tab-content">';

        if ($activeTab === 'dashboard') {
            // 1) Dashboard: мониторинг + ежедневный отчёт для инвентаризации
            $output .= $this->displayTransactionMonitoring();
            $output .= $this->displayDailyInventoryReport();
        } elseif ($activeTab === 'sync') {
            // 2) Расписание/принудительная синхронизация/исключения
            $output .= $this->displayCronConfigurationForm();
            $output .= $this->displayManualSyncPanel();
            $output .= $this->displaySyncSettingsForm();
            $output .= $this->displayUnmappedSkusPanel();
        } else {
            // 3) Подключение/терминалы/логи/отчёты
            $output .= $this->displayConfigurationForm();
            $output .= $this->displayTerminalManagement();
        }

        $output .= '</div>';

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
        Configuration::updateValue('ACCUPOS_ENABLE_REPORTS', $enableReports);
        Configuration::updateValue('ACCUPOS_ADMIN_EMAIL', $adminEmail);
        Configuration::updateValue('ACCUPOS_REPORT_FORMAT', $reportFormat);

        return $this->displayConfirmation($this->l('Настройки успешно сохранены'));
    }

    /**
     * Обработка сохранения настроек синхронизации/исключений (вкладка Sync)
     *
     * @return string
     */
    private function processSyncSettings()
    {
        $defaultWarehouse = (int)Tools::getValue('ACCUPOS_DEFAULT_WAREHOUSE');
        $syncWindowDays = (int)Tools::getValue('ACCUPOS_SYNC_WINDOW_DAYS');
        $skuExclusions = (string)Tools::getValue('ACCUPOS_SKU_EXCLUSIONS');
        $inventoryExclude = (string)Tools::getValue('ACCUPOS_INVENTORY_EXCLUDE_EAN13');

        if ($syncWindowDays < 0 || $syncWindowDays > 365) {
            return $this->displayError($this->l('Окно синхронизации должно быть от 0 до 365 дней'));
        }

        Configuration::updateValue('ACCUPOS_DEFAULT_WAREHOUSE', (int)$defaultWarehouse);
        Configuration::updateValue('ACCUPOS_SYNC_WINDOW_DAYS', (int)$syncWindowDays);
        Configuration::updateValue('ACCUPOS_SKU_EXCLUSIONS', $skuExclusions);
        Configuration::updateValue('ACCUPOS_INVENTORY_EXCLUDE_EAN13', $inventoryExclude);

        return $this->displayConfirmation($this->l('Настройки синхронизации и исключения сохранены'));
    }

    /**
     * Обработка сохранения CRON-расписания (отдельная форма)
     *
     * @return string
     */
    private function processCronConfiguration()
    {
        $enableCron = (int)Tools::getValue('ACCUPOS_ENABLE_CRON');
        $intervalMin = (int)Tools::getValue('ACCUPOS_CRON_INTERVAL_MINUTES');
        $syncMode = (string)Tools::getValue('ACCUPOS_CRON_SYNC_MODE');
        $cronTime = (string)Tools::getValue('ACCUPOS_CRON_TIME', '02:00');

        if ($intervalMin < 1 || $intervalMin > 1440) {
            return $this->displayError($this->l('Интервал должен быть от 1 до 1440 минут'));
        }
        if ($syncMode !== 'today' && $syncMode !== 'yesterday') {
            $syncMode = 'today';
        }

        // Время для legacy-режима (HH:MM). Используется dispatcher.php, когда выбран "yesterday".
        $cronTime = trim($cronTime);
        if (!preg_match('/^\\d{2}:\\d{2}$/', $cronTime)) {
            $cronTime = '02:00';
        } else {
            $hh = (int)substr($cronTime, 0, 2);
            $mm = (int)substr($cronTime, 3, 2);
            if ($hh < 0 || $hh > 23 || $mm < 0 || $mm > 59) {
                $cronTime = '02:00';
            }
        }

        Configuration::updateValue('ACCUPOS_ENABLE_CRON', $enableCron ? 1 : 0);
        Configuration::updateValue('ACCUPOS_CRON_INTERVAL_MINUTES', (int)$intervalMin);
        Configuration::updateValue('ACCUPOS_CRON_SYNC_MODE', pSQL($syncMode));
        Configuration::updateValue('ACCUPOS_CRON_TIME', pSQL($cronTime));

        return $this->displayConfirmation($this->l('CRON расписание сохранено'));
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
                    
                    // Раздел: Отчёты
                    array(
                        'type' => 'html',
                        'name' => '',
                        'html_content' =>
                            '<h4 style="margin-top:30px;">' . $this->l('Отчёты') . '</h4><hr>' .
                            '<div class="alert alert-info" style="margin-bottom:15px;">' .
                            '<b>' . $this->l('Важно:') . '</b> ' .
                            $this->l('ежедневный email-отчёт отправляется только если за день есть ошибки синхронизации (status=error).') .
                            '<br>' .
                            $this->l('Для проверки работы почты используйте кнопку') . ' <b>' . $this->l('Тест email') . '</b>.' .
                            '</div>'
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
                        'title' => $this->l('Тест email'),
                        'name' => 'sendAccuPosTestReport',
                        'type' => 'submit',
                        'class' => 'btn btn-warning',
                        'icon' => 'process-icon-mail'
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
        $helper->fields_value['ACCUPOS_ENABLE_REPORTS'] = Configuration::get('ACCUPOS_ENABLE_REPORTS');
        $helper->fields_value['ACCUPOS_ADMIN_EMAIL'] = Configuration::get('ACCUPOS_ADMIN_EMAIL');
        $helper->fields_value['ACCUPOS_REPORT_FORMAT'] = Configuration::get('ACCUPOS_REPORT_FORMAT');

        return $helper->generateForm(array($fields_form));
    }

    /**
     * Навигация по вкладкам (3 вкладки)
     *
     * @param string $activeTab
     * @return string
     */
    private function renderTabsNavigation($activeTab)
    {
        $baseUrl = AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules');

        $tabs = array(
            'dashboard' => $this->l('Дашборд'),
            'sync' => $this->l('Синхронизация / Исключения'),
            'settings' => $this->l('Настройки / Терминалы'),
        );

        $html = '<div class="panel accupos-no-print" style="margin-top:15px;">';
        $html .= '<div class="panel-body">';
        $html .= '<ul class="nav nav-tabs" role="tablist">';

        foreach ($tabs as $key => $label) {
            $activeClass = ($activeTab === $key) ? ' class="active"' : '';
            $html .= '<li role="presentation"' . $activeClass . '>';
            $html .= '<a href="' . $baseUrl . '&accupos_tab=' . urlencode($key) . '">' . Tools::safeOutput($label) . '</a>';
            $html .= '</li>';
        }

        $html .= '</ul>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Панель ручного запуска синхронизации (кнопка + статус)
     *
     * @return string
     */
    private function displayManualSyncPanel()
    {
        $html = '<div class="panel" style="margin-top: 20px;">';
        $html .= '<div class="panel-heading"><i class="icon-refresh"></i> ' . $this->l('Принудительная синхронизация') . '</div>';
        $html .= '<div class="panel-body">';
        $html .= '<p>' . $this->l('Запускает синхронизацию транзакций напрямую (как ручная синхронизация).') . '</p>';
        $html .= '<button type="button" id="accupos-manual-sync-btn" class="btn btn-success">';
        $html .= '<i class="icon-refresh"></i> ' . $this->l('Принудительная синхронизация');
        $html .= '</button>';
        $html .= '<div id="accupos-sync-status" style="margin-top: 15px;"></div>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Настройки синхронизации и исключений (вкладка Sync)
     *
     * @return string
     */
    private function displaySyncSettingsForm()
    {
        $warehouses = Warehouse::getWarehouses(true);
        $warehouseOptions = array();
        foreach ($warehouses as $warehouse) {
            $warehouseOptions[] = array(
                'id' => $warehouse['id_warehouse'],
                'name' => $warehouse['name']
            );
        }

        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Настройки синхронизации и исключения'),
                    'icon' => 'icon-filter'
                ),
                'input' => array(
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
                        'desc' => $this->l('Сколько дней назад синхронизировать транзакции (0 = только текущая дата старта)')
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->l('Технические EAN13 / SKU (исключить из синхронизации)'),
                        'name' => 'ACCUPOS_SKU_EXCLUSIONS',
                        'rows' => 6,
                        'desc' => $this->l('По одному SKU/EAN13 на строку. Эти позиции будут помечаться как skipped, без ошибок.')
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->l('Исключить из выкладки (подсветка в отчёте)'),
                        'name' => 'ACCUPOS_INVENTORY_EXCLUDE_EAN13',
                        'rows' => 6,
                        'desc' => $this->l('По одному EAN13 на строку. Эти товары будут подсвечены в отчёте ежедневной инвентаризации.')
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Сохранить'),
                    'class' => 'btn btn-default pull-right'
                ),
            )
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name . '&accupos_tab=sync';
        $helper->submit_action = 'submitAccuPosSyncSettings';
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');

        $helper->fields_value['ACCUPOS_DEFAULT_WAREHOUSE'] = (int)Configuration::get('ACCUPOS_DEFAULT_WAREHOUSE');
        $helper->fields_value['ACCUPOS_SYNC_WINDOW_DAYS'] = (int)Configuration::get('ACCUPOS_SYNC_WINDOW_DAYS', 7);
        $helper->fields_value['ACCUPOS_SKU_EXCLUSIONS'] = (string)Configuration::get('ACCUPOS_SKU_EXCLUSIONS');
        $helper->fields_value['ACCUPOS_INVENTORY_EXCLUDE_EAN13'] = (string)Configuration::get('ACCUPOS_INVENTORY_EXCLUDE_EAN13');

        return $helper->generateForm(array($fields_form));
    }

    /**
     * Панель "не найдено по EAN13" (ошибки Product not found)
     *
     * @return string
     */
    private function displayUnmappedSkusPanel()
    {
        $sql = 'SELECT sku, COUNT(*) AS cnt, MAX(date_sale) AS last_sale
                FROM ' . _DB_PREFIX_ . 'accupos_transactions
                WHERE status = \'error\'
                  AND error_message LIKE \'Product not found%\'
                  AND DATE(date_sale) = CURDATE()
                GROUP BY sku
                ORDER BY cnt DESC
                LIMIT 100';

        $rows = Db::getInstance()->executeS($sql);

        $html = '<div class="panel" style="margin-top: 20px;">';
        $html .= '<div class="panel-heading"><i class="icon-warning"></i> ' . $this->l('Товары, не найденные по EAN13/SKU (сегодня)') . '</div>';
        $html .= '<div class="panel-body">';

        if (empty($rows)) {
            $html .= '<div class="alert alert-success"><i class="icon-check"></i> ' . $this->l('Сегодня нет ошибок "Product not found".') . '</div>';
        } else {
            $html .= '<p class="help-block">' . $this->l('Если это "технические" EAN13 — добавьте их в исключения выше, чтобы они помечались как skipped.') . '</p>';
            $html .= '<div class="table-responsive">';
            $html .= '<table class="table table-bordered table-hover">';
            $html .= '<thead><tr>';
            $html .= '<th>' . $this->l('SKU/EAN13') . '</th>';
            $html .= '<th style="width:120px;">' . $this->l('Кол-во ошибок') . '</th>';
            $html .= '<th style="width:180px;">' . $this->l('Последняя продажа') . '</th>';
            $html .= '</tr></thead><tbody>';
            foreach ($rows as $r) {
                $html .= '<tr>';
                $html .= '<td><code>' . Tools::safeOutput($r['sku']) . '</code></td>';
                $html .= '<td>' . (int)$r['cnt'] . '</td>';
                $html .= '<td>' . Tools::safeOutput($r['last_sale']) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
        }

        $html .= '</div></div>';
        return $html;
    }

    /**
     * Ежедневный отчёт по товарам с транзакциями (для инвентаризации)
     *
     * @return string
     */
    private function displayDailyInventoryReport()
    {
        $idLang = (int)$this->context->language->id;
        $idShop = (int)$this->context->shop->id;

        // --- Фильтры отчёта (дата + склады) ---
        $selectedDate = (string)Tools::getValue('accupos_inventory_date', date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
            $selectedDate = date('Y-m-d');
        }

        // Выбранные склады: поддерживаем 2 формата:
        // - массив из формы (name="accupos_inventory_warehouses[]")
        // - строка (совместимость), например "2,3,4"
        $selectedWarehouseIds = array();
        $selectedWhValue = Tools::getValue('accupos_inventory_warehouses', array());
        if (is_array($selectedWhValue)) {
            foreach ($selectedWhValue as $v) {
                $id = (int)$v;
                if ($id > 0) {
                    $selectedWarehouseIds[$id] = true;
                }
            }
        } else {
            $selectedWhRaw = (string)$selectedWhValue;
            if ($selectedWhRaw !== '') {
                foreach (preg_split('/[,\s]+/', $selectedWhRaw) as $part) {
                    $id = (int)trim((string)$part);
                    if ($id > 0) {
                        $selectedWarehouseIds[$id] = true;
                    }
                }
            }
        }
        $selectedWarehouseIds = array_keys($selectedWarehouseIds);

        // Список всех складов (для селектора)
        // ВАЖНО: скрываем склад STK_1 Haifa — он не должен участвовать в выборе/отчёте.
        $allWarehousesRaw = Db::getInstance()->executeS(
            'SELECT id_warehouse, reference, name FROM ' . _DB_PREFIX_ . 'warehouse ORDER BY name ASC'
        );
        $allWarehouses = array();
        $hiddenWarehouseIds = array();
        foreach ($allWarehousesRaw as $w) {
            $wid = (int)$w['id_warehouse'];
            $wname = isset($w['name']) ? (string)$w['name'] : '';
            $wref = isset($w['reference']) ? (string)$w['reference'] : '';

            // Самый надёжный критерий: reference склада (обычно STK_1 / STK_2 / STK_3 ...)
            $isStk1Ref = ($wref !== '') && (bool)preg_match('/\bstk[_\s-]*1\b/i', $wref);

            // Fallback (если reference пустой/нетипичный): пробуем по имени
            $isStk1 = (bool)preg_match('/\bstk[_\s-]*1\b/i', $wname);
            $isHaifa = (stripos($wname, 'haifa') !== false) || (stripos($wname, 'хайф') !== false);

            if ($wid > 0 && ($isStk1Ref || ($isStk1 && $isHaifa))) {
                $hiddenWarehouseIds[] = $wid;
                continue;
            }
            $allWarehouses[] = $w;
        }
        if (!empty($hiddenWarehouseIds) && !empty($selectedWarehouseIds)) {
            $selectedWarehouseIds = array_values(array_diff($selectedWarehouseIds, $hiddenWarehouseIds));
        }

        // В отчёте инвентаризации показываем только операции, которые УМЕНЬШАЮТ остаток.
        // В нашей модели AccuPOS: qty > 0 = продажа (списание), qty < 0 = возврат/пополнение.
        $txOnlyDecreaseWhere = ' AND qty > 0 ';

        // Склады, по которым были транзакции за выбранную дату (с учётом фильтра по складам)
        $txWhWhere = '';
        if (!empty($selectedWarehouseIds)) {
            $txWhWhere = ' AND warehouse_id IN (' . implode(',', array_map('intval', $selectedWarehouseIds)) . ')';
        }
        if (!empty($hiddenWarehouseIds)) {
            $txWhWhere .= ' AND warehouse_id NOT IN (' . implode(',', array_map('intval', $hiddenWarehouseIds)) . ')';
        }

        $whSql =
            'SELECT DISTINCT warehouse_id
             FROM ' . _DB_PREFIX_ . 'accupos_transactions
             WHERE status = \'success\'
               AND warehouse_id IS NOT NULL
               ' . $txOnlyDecreaseWhere . '
               AND DATE(date_sale) = \'' . pSQL($selectedDate) . '\'
               ' . $txWhWhere . '
             ORDER BY warehouse_id ASC';

        $whRows = Db::getInstance()->executeS($whSql);

        // Какие колонки показывать:
        // - если пользователь выбрал склады → показываем их (даже если за дату нет транзакций)
        // - иначе → показываем склады, по которым есть транзакции за дату
        $warehouseIds = array();
        if (!empty($selectedWarehouseIds)) {
            $warehouseIds = $selectedWarehouseIds;
        } else {
            foreach ($whRows as $w) {
                $warehouseIds[] = (int)$w['warehouse_id'];
            }
        }
        if (!empty($hiddenWarehouseIds) && !empty($warehouseIds)) {
            $warehouseIds = array_values(array_diff($warehouseIds, $hiddenWarehouseIds));
        }

        if (empty($warehouseIds)) {
            $warehouseIds = array();
        }

        $warehouseMap = array();
        if (!empty($warehouseIds)) {
            $whListSql = implode(',', array_map('intval', $warehouseIds));
            $wh = Db::getInstance()->executeS('SELECT id_warehouse, name FROM ' . _DB_PREFIX_ . 'warehouse WHERE id_warehouse IN (' . $whListSql . ')');
            foreach ($wh as $w) {
                $warehouseMap[(int)$w['id_warehouse']] = (string)$w['name'];
            }
        }

        // Товары, у которых были транзакции за выбранную дату + текущие остатки по выбранным складам
        $txFilterWhere = '';
        if (!empty($warehouseIds)) {
            // фильтруем транзакции по выбранным складам (важно, если пользователь выбрал склады)
            $txFilterWhere .= ' AND t.warehouse_id IN (' . implode(',', array_map('intval', $warehouseIds)) . ')';
        }
        if (!empty($hiddenWarehouseIds)) {
            $txFilterWhere .= ' AND t.warehouse_id NOT IN (' . implode(',', array_map('intval', $hiddenWarehouseIds)) . ')';
        }

        $stockJoin = 'LEFT JOIN ' . _DB_PREFIX_ . 'stock s
                    ON (s.id_product = p.id_product AND s.id_product_attribute = 0)';
        if (!empty($warehouseIds)) {
            $stockJoin = 'LEFT JOIN ' . _DB_PREFIX_ . 'stock s
                    ON (s.id_product = p.id_product AND s.id_product_attribute = 0 AND s.id_warehouse IN (' . implode(',', array_map('intval', $warehouseIds)) . '))';
        }

        $sql =
            'SELECT
                p.id_product,
                p.ean13,
                p.upc,
                p.reference,
                pl.name AS product_name,
                s.id_warehouse,
                s.physical_quantity
            FROM ' . _DB_PREFIX_ . 'accupos_transactions t
            INNER JOIN ' . _DB_PREFIX_ . 'product p
                ON (
                    CONVERT(p.ean13 USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(t.sku USING utf8mb4) COLLATE utf8mb4_unicode_ci
                    OR CONVERT(p.upc USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(t.sku USING utf8mb4) COLLATE utf8mb4_unicode_ci
                )
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl
                ON (pl.id_product = p.id_product AND pl.id_lang = ' . (int)$idLang . ' AND pl.id_shop = ' . (int)$idShop . ')
            ' . $stockJoin . '
            WHERE t.status = \'success\'
              AND t.qty > 0
              AND DATE(t.date_sale) = \'' . pSQL($selectedDate) . '\'
              ' . $txFilterWhere . '
            GROUP BY p.id_product, s.id_warehouse
            ORDER BY pl.name ASC';

        $rows = Db::getInstance()->executeS($sql);

        // Список значений для подсветки "исключить из выкладки"
        // Пользователь может вводить EAN13/UPC/SKU. Нормализуем: оставляем только цифры.
        $excludeRaw = (string)Configuration::get('ACCUPOS_INVENTORY_EXCLUDE_EAN13');
        $exclude = array();
        $excludeTokens = preg_split('/[\\s,;]+/u', (string)$excludeRaw, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($excludeTokens as $tok) {
            $tok = trim((string)$tok);
            if ($tok === '') {
                continue;
            }
            $digits = preg_replace('/\\D+/', '', $tok);
            if ($digits === '') {
                continue;
            }
            $exclude[$digits] = true;
        }

        // Собираем данные по продуктам
        $products = array();
        foreach ($rows as $r) {
            $pid = (int)$r['id_product'];
            if (!isset($products[$pid])) {
                $products[$pid] = array(
                    'name' => (string)$r['product_name'],
                    'ean13' => (string)$r['ean13'],
                    'upc' => (string)$r['upc'],
                    'reference' => (string)$r['reference'],
                    'qty' => array(),
                );
            }
            $whId = (int)$r['id_warehouse'];
            if ($whId > 0) {
                $products[$pid]['qty'][$whId] = (int)$r['physical_quantity'];
            }
        }

        /**
         * ВАЖНО: Для прошлых дат показываем "остаток на конец выбранного дня".
         *
         * Т.к. в `ps_stock` всегда хранится "текущий" остаток, для выбранной даты D считаем:
         *   qty_at_end_of_D = current_qty - SUM(movements_after_D)
         *
         * Где movements_after_D — все движения из `ps_stock_mvt` после 23:59:59 выбранной даты
         * (по тем же product + warehouse). Это позволяет корректно пересмотреть отчёт за прошлый день.
         */
        if (!empty($products) && !empty($warehouseIds)) {
            $productIds = array_map('intval', array_keys($products));
            $whIdsSql = implode(',', array_map('intval', $warehouseIds));
            $pIdsSql = implode(',', $productIds);

            $endOfDay = date('Y-m-d 23:59:59', strtotime($selectedDate));

            $deltaRows = Db::getInstance()->executeS(
                'SELECT
                    st.id_product,
                    st.id_warehouse,
                    SUM(CASE WHEN m.sign < 0 THEN -1 ELSE 1 END * m.physical_quantity) AS delta_after
                 FROM ' . _DB_PREFIX_ . 'stock_mvt m
                 INNER JOIN ' . _DB_PREFIX_ . 'stock st
                    ON (st.id_stock = m.id_stock)
                 WHERE m.date_add > \'' . pSQL($endOfDay) . '\'
                   AND st.id_product IN (' . $pIdsSql . ')
                   AND st.id_product_attribute = 0
                   AND st.id_warehouse IN (' . $whIdsSql . ')
                 GROUP BY st.id_product, st.id_warehouse'
            );

            $deltaMap = array();
            foreach ($deltaRows as $dr) {
                $pid = (int)$dr['id_product'];
                $wid = (int)$dr['id_warehouse'];
                $delta = (float)$dr['delta_after'];
                if ($pid > 0 && $wid > 0 && $delta != 0.0) {
                    if (!isset($deltaMap[$pid])) {
                        $deltaMap[$pid] = array();
                    }
                    $deltaMap[$pid][$wid] = $delta;
                }
            }

            // Корректируем qty по каждому складу
            foreach ($products as $pid => &$p) {
                foreach ($warehouseIds as $wid) {
                    $currentQty = isset($p['qty'][$wid]) ? (int)$p['qty'][$wid] : 0;
                    $deltaAfter = (isset($deltaMap[(int)$pid]) && isset($deltaMap[(int)$pid][(int)$wid]))
                        ? (float)$deltaMap[(int)$pid][(int)$wid]
                        : 0.0;
                    $p['qty'][$wid] = (int)round($currentQty - $deltaAfter);
                }
            }
            unset($p);
        }

        // Убираем товары, у которых остаток на конец выбранного дня = 0
        // (по всем выбранным складам; если выбран 1 склад — правило работает "в лоб" для него).
        if (!empty($products) && !empty($warehouseIds)) {
            foreach ($products as $pid => $p) {
                $hasPositiveQty = false;
                foreach ($warehouseIds as $wid) {
                    $qty = isset($p['qty'][(int)$wid]) ? (int)$p['qty'][(int)$wid] : 0;
                    if ($qty > 0) {
                        $hasPositiveQty = true;
                        break;
                    }
                }
                if (!$hasPositiveQty) {
                    unset($products[$pid]);
                }
            }
        }

        $html = '<div class="panel accupos-inventory-report" data-accupos-inventory-root style="margin-top: 20px;">';
        $html .= '<div class="panel-heading">';
        $html .= '<i class="icon-list-alt"></i> ' . $this->l('Отчёт инвентаризации (остаток на конец выбранного дня)') . ' <small class="text-muted">(' . Tools::safeOutput($selectedDate) . ')</small>';
        $html .= '<div class="pull-right accupos-no-print">';
        $html .= '<button type="button" class="btn btn-default btn-xs" data-accupos-print-inventory>';
        $html .= '<i class="icon-print"></i> ' . $this->l('Печать');
        $html .= '</button>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="panel-body">';

        // Печатаемый заголовок/дата (видно только в режиме печати)
        $html .= '<div class="accupos-only-print accupos-print-header">';
        $html .= '<div class="accupos-print-title">' . $this->l('Отчёт инвентаризации') . '</div>';
        $html .= '<div class="accupos-print-subtitle">' . $this->l('Дата отчёта:') . ' ' . Tools::safeOutput(date('d.m.Y', strtotime($selectedDate))) . '</div>';
        $html .= '<div class="accupos-print-subtitle">' . $this->l('Сформировано:') . ' ' . Tools::safeOutput(date('d.m.Y H:i')) . '</div>';
        // Сотрудник (админ), под учёткой которого распечатывается документ
        $employeeName = '';
        if (isset($this->context->employee) && $this->context->employee && (int)$this->context->employee->id > 0) {
            $employeeName = trim((string)$this->context->employee->firstname . ' ' . (string)$this->context->employee->lastname);
        }
        if ($employeeName !== '') {
            $html .= '<div class="accupos-print-subtitle">' . $this->l('Сотрудник:') . ' ' . Tools::safeOutput($employeeName) . '</div>';
        }
        if (!empty($warehouseIds)) {
            $names = array();
            foreach ($warehouseIds as $wid) {
                $names[] = isset($warehouseMap[(int)$wid]) ? $warehouseMap[(int)$wid] : ('WH #' . (int)$wid);
            }
            $html .= '<div class="accupos-print-subtitle">' . $this->l('Склады:') . ' ' . Tools::safeOutput(implode(', ', $names)) . '</div>';
        }
        $html .= '</div>';

        // Форма выбора даты/складов (GET)
        // Важно: PrestaShop может редиректить на главную админки при invalid token.
        // Поэтому делаем максимально "железный" вариант: action=index.php + скрытые поля (controller/token/etc).
        $adminToken = (string)Tools::getValue('token');
        if ($adminToken === '') {
            $adminToken = Tools::getAdminTokenLite('AdminModules');
        }
        $html .= '<form method="get" action="index.php" class="accupos-no-print" style="margin-bottom:10px;">';
        $html .= '<input type="hidden" name="controller" value="AdminModules" />';
        $html .= '<input type="hidden" name="configure" value="' . Tools::safeOutput($this->name) . '" />';
        $html .= '<input type="hidden" name="module_name" value="' . Tools::safeOutput($this->name) . '" />';
        $html .= '<input type="hidden" name="tab_module" value="administration" />';
        $html .= '<input type="hidden" name="accupos_tab" value="dashboard" />';
        $html .= '<input type="hidden" name="token" value="' . Tools::safeOutput($adminToken) . '" />';
        $html .= '<div class="row">';
        $html .= '<div class="col-md-3">';
        $html .= '<label class="control-label" style="font-weight:600;">' . $this->l('Дата') . '</label>';
        $html .= '<input type="date" class="form-control" name="accupos_inventory_date" value="' . Tools::safeOutput($selectedDate) . '"/>';
        $html .= '</div>';
        $html .= '<div class="col-md-6">';
        $html .= '<label class="control-label" style="font-weight:600;">' . $this->l('Склады') . '</label>';
        $html .= '<select class="form-control" name="accupos_inventory_warehouses[]" multiple size="3">';
        foreach ($allWarehouses as $w) {
            $wid = (int)$w['id_warehouse'];
            // Если пользователь явно не выбирал склады — подставляем "склады с транзакциями за дату"
            $defaultSelected = empty($selectedWarehouseIds) ? in_array($wid, $warehouseIds, true) : in_array($wid, $selectedWarehouseIds, true);
            $selected = $defaultSelected ? ' selected="selected"' : '';
            $html .= '<option value="' . (int)$wid . '"' . $selected . '>' . Tools::safeOutput($w['name']) . '</option>';
        }
        $html .= '</select>';
        $html .= '<p class="help-block" style="margin-top:5px;">' . $this->l('Если не выбирать склады — отчёт покажет склады, по которым были транзакции за выбранную дату. Остатки считаются на конец выбранного дня.') . '</p>';
        $html .= '</div>';
        $html .= '<div class="col-md-3" style="padding-top:25px;">';
        $html .= '<button type="submit" class="btn btn-primary">' . $this->l('Показать') . '</button> ';
        $resetUrl = 'index.php?controller=AdminModules'
            . '&configure=' . urlencode($this->name)
            . '&module_name=' . urlencode($this->name)
            . '&tab_module=administration'
            . '&accupos_tab=dashboard'
            . '&token=' . urlencode($adminToken);
        $html .= '<a class="btn btn-default" href="' . Tools::safeOutput($resetUrl) . '">' . $this->l('Сброс') . '</a>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</form>';

        if (empty($products)) {
            $html .= '<div class="alert alert-info">' . $this->l('Нет транзакций AccuPOS, которые уменьшают остаток (продажи/списания), для выбранных условий отчёта.') . '</div>';
        } else {
            // Панель поиска
            $html .= '<div class="row accupos-no-print" style="margin-bottom:10px;">';
            $html .= '<div class="col-md-6">';
            $html .= '<div class="input-group">';
            $html .= '<input type="text" class="form-control" data-accupos-inventory-search placeholder="' . Tools::safeOutput($this->l('Поиск по названию или EAN13...')) . '" />';
            $html .= '<span class="input-group-btn">';
            $html .= '<button type="button" class="btn btn-default" data-accupos-inventory-reset>';
            $html .= '<i class="icon-remove"></i> ' . $this->l('Сброс');
            $html .= '</button>';
            $html .= '</span>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '<div class="col-md-6 text-right" style="padding-top:7px;">';
            $html .= '<small class="text-muted">' . $this->l('Сортировка: клик по заголовку колонки') . '</small>';
            $html .= '</div>';
            $html .= '</div>';

            $html .= '<div class="table-responsive">';
            $html .= '<table class="table table-bordered table-hover" data-accupos-inventory-table>';
            $html .= '<thead><tr>';
            $html .= '<th data-sort-idx="0" data-sort-type="text">' . $this->l('Наименование') . '</th>';
            $html .= '<th style="width:140px;" data-sort-idx="1" data-sort-type="text">' . $this->l('EAN13') . '</th>';
            $wIndex = 0;
            foreach ($warehouseIds as $whId) {
                $label = isset($warehouseMap[$whId]) ? $warehouseMap[$whId] : ('WH #' . $whId);
                // idx: 2..n (фиксируем индекс без array_search для скорости/надёжности)
                $idx = 2 + $wIndex;
                $html .= '<th style="width:120px;" data-sort-idx="' . (int)$idx . '" data-sort-type="number">' . Tools::safeOutput($label) . '</th>';
                $wIndex++;
            }
            // Колонка для печати: менеджер ставит галочки при проверке
            $html .= '<th class="accupos-only-print" style="width:80px;">' . $this->l('Проверено') . '</th>';
            $html .= '</tr></thead><tbody>';

            foreach ($products as $p) {
                $ean = $p['ean13'] ?: $p['upc'];

                $cand = array();
                $cand[] = preg_replace('/\\D+/', '', (string)$p['ean13']);
                $cand[] = preg_replace('/\\D+/', '', (string)$p['upc']);
                $cand[] = preg_replace('/\\D+/', '', (string)$p['reference']);
                $isExcluded = false;
                foreach ($cand as $c) {
                    if ($c !== '' && isset($exclude[$c])) {
                        $isExcluded = true;
                        break;
                    }
                }

                $rowClass = $isExcluded ? ' class="accupos-row-exclude"' : '';
                $html .= '<tr' . $rowClass . '>';
                $html .= '<td>' . Tools::safeOutput($p['name']) . '</td>';
                $html .= '<td><code>' . Tools::safeOutput($ean) . '</code></td>';
                foreach ($warehouseIds as $whId) {
                    $qty = isset($p['qty'][$whId]) ? (int)$p['qty'][$whId] : 0;
                    $html .= '<td class="text-center"><strong>' . $qty . '</strong></td>';
                }
                // Квадратик для галочки (только печать)
                $html .= '<td class="accupos-only-print accupos-check-cell"><span class="accupos-check-square"></span></td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table></div>';
            $html .= '<p class="help-block accupos-no-print">' . $this->l('Подсветка: товары, отмеченные как "исключить из выкладки".') . '</p>';
        }

        $html .= '</div></div>';
        return $html;
    }

    /**
     * Отдельная форма: настройки CRON расписания
     *
     * @return string
     */
    private function displayCronConfigurationForm()
    {
        // Рекомендуемая схема: dispatcher+worker каждую минуту; частота/режим задаются в настройках.
        $cronDispatcherLine = '* * * * * /usr/bin/php7.4 ' . _PS_ROOT_DIR_ . '/modules/accupos_sync/cron/dispatcher.php >> ' . _PS_ROOT_DIR_ . '/var/logs/cron/accupos_cron.log 2>&1';
        $cronWorkerLine = '* * * * * /usr/bin/php7.4 ' . _PS_ROOT_DIR_ . '/modules/accupos_sync/cron/async_worker.php >> ' . _PS_ROOT_DIR_ . '/var/logs/accupos_async_worker.log 2>&1';

        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('CRON расписание'),
                    'icon' => 'icon-time'
                ),
                'description' => $this->l('Рекомендуемый подход: в crontab поставить dispatcher (каждую минуту), а периодичность задавать здесь.'),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Включить CRON'),
                        'name' => 'ACCUPOS_ENABLE_CRON',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'cron_on', 'value' => 1, 'label' => $this->l('Да')),
                            array('id' => 'cron_off', 'value' => 0, 'label' => $this->l('Нет'))
                        )
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Интервал синхронизации (минуты)'),
                        'name' => 'ACCUPOS_CRON_INTERVAL_MINUTES',
                        'desc' => $this->l('Например: 5 или 10. Используется в режиме "Текущий день". В режиме "Предыдущий день (legacy)" интервал игнорируется.'),
                        'required' => true
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Время запуска (HH:MM)'),
                        'name' => 'ACCUPOS_CRON_TIME',
                        'desc' => $this->l('Используется только в режиме "Предыдущий день (legacy)". Например: 02:30. Часовой пояс — как у cron на сервере.'),
                        'required' => false
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Режим синхронизации для CRON'),
                        'name' => 'ACCUPOS_CRON_SYNC_MODE',
                        'options' => array(
                            'query' => array(
                                array('id' => 'today', 'name' => $this->l('Текущий день (рекомендуется)')),
                                array('id' => 'yesterday', 'name' => $this->l('Предыдущий день (legacy)')),
                            ),
                            'id' => 'id',
                            'name' => 'name'
                        ),
                        'desc' => $this->l('Для предотвращения продаж "в минус" включайте текущий день.'),
                    ),
                    array(
                        'type' => 'html',
                        'name' => '',
                        'html_content' =>
                            '<p><b>' . $this->l('Строки для root crontab') . ':</b><br>' .
                            '<pre style="white-space:pre-wrap;word-break:break-word;"><code>' .
                            Tools::safeOutput($cronDispatcherLine) . "\n" .
                            Tools::safeOutput($cronWorkerLine) .
                            '</code></pre>' .
                            '<small>' . $this->l('Файл на DigitalOcean: /var/spool/cron/crontabs/root') . '</small></p>' .
                            '<div class="alert alert-info" style="margin-top:10px;">' .
                                '<b>' . $this->l('Важно:') . '</b> ' .
                                $this->l('в режиме "Предыдущий день (legacy)" синхронизация запускается 1 раз в сутки по времени "Время запуска (HH:MM)". В режиме "Текущий день" используется интервал (минуты).') .
                            '</div>' .
                            '<script>(function(){\n' .
                                'function qs(sel){return document.querySelector(sel);} \n' .
                                'function toggle(){\n' .
                                    'var modeSel=qs(\"select[name=ACCUPOS_CRON_SYNC_MODE]\");\n' .
                                    'if(!modeSel) return;\n' .
                                    'var mode=modeSel.value;\n' .
                                    'var interval=qs(\"input[name=ACCUPOS_CRON_INTERVAL_MINUTES]\");\n' .
                                    'var time=qs(\"input[name=ACCUPOS_CRON_TIME]\");\n' .
                                    'var intervalGroup=interval && interval.closest ? interval.closest(\".form-group\") : null;\n' .
                                    'var timeGroup=time && time.closest ? time.closest(\".form-group\") : null;\n' .
                                    'if (mode === \"yesterday\") {\n' .
                                        'if (timeGroup) timeGroup.style.display = \"\";\n' .
                                        'if (interval) interval.disabled = true;\n' .
                                        'if (intervalGroup) intervalGroup.classList.add(\"text-muted\");\n' .
                                    '} else {\n' .
                                        'if (timeGroup) timeGroup.style.display = \"none\";\n' .
                                        'if (interval) interval.disabled = false;\n' .
                                        'if (intervalGroup) intervalGroup.classList.remove(\"text-muted\");\n' .
                                    '}\n' .
                                '}\n' .
                                'document.addEventListener(\"DOMContentLoaded\", function(){\n' .
                                    'toggle();\n' .
                                    'var modeSel=qs(\"select[name=ACCUPOS_CRON_SYNC_MODE]\");\n' .
                                    'if(modeSel){modeSel.addEventListener(\"change\", toggle);} \n' .
                                '});\n' .
                            '})();</script>'
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Сохранить расписание'),
                    'class' => 'btn btn-default pull-right'
                ),
            )
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitAccuPosCron';
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');

        $helper->fields_value['ACCUPOS_ENABLE_CRON'] = (int)Configuration::get('ACCUPOS_ENABLE_CRON');
        $helper->fields_value['ACCUPOS_CRON_INTERVAL_MINUTES'] = (int)Configuration::get('ACCUPOS_CRON_INTERVAL_MINUTES', 10);
        $helper->fields_value['ACCUPOS_CRON_SYNC_MODE'] = (string)Configuration::get('ACCUPOS_CRON_SYNC_MODE', 'today');
        $helper->fields_value['ACCUPOS_CRON_TIME'] = (string)Configuration::get('ACCUPOS_CRON_TIME', '02:00');

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
            
            // 🕐 CRON СИНХРОНИЗАЦИЯ: режим настраивается в админке (по умолчанию текущий день)
            $mode = (string)Configuration::get('ACCUPOS_CRON_SYNC_MODE', 'today');
            $sync->setCronSyncMode($mode);
            
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
        $terminalId = trim((string)Tools::getValue('terminal_id'));
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

        // Важно: всегда используем "чистый" action для формы добавления, чтобы
        // остаточные GET-параметры (например deleteTerminal=1) не выполнялись повторно при POST.
        $cleanSettingsUrl = AdminController::$currentIndex
            . '&configure=' . $this->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules')
            . '&accupos_tab=settings';

        // Заголовок секции
        $output .= '<div class="panel" style="margin-top: 20px;">';
        $output .= '<div class="panel-heading">';
        $output .= '<i class="icon-cogs"></i> ' . $this->l('Управление терминалами AccuPOS');
        $output .= '</div>';

        // Форма добавления терминала
        $output .= '<div class="panel-body">';
        $output .= '<h4>' . $this->l('Добавить новый терминал') . '</h4>';
        $output .= '<form method="post" action="' . Tools::safeOutput($cleanSettingsUrl) . '" class="form-horizontal">';
        
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
                        AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules') . '&accupos_tab=settings',
                        urlencode($terminal['terminal_id']),
                        $this->l('Деактивировать')
                    );
                    $output .= '<i class="icon-remove"></i>';
                    $output .= '</a>';
                } else {
                    $output .= sprintf(
                        '<a href="%s&toggleTerminal=1&terminal_id=%s&action=activate" class="btn btn-default btn-xs" title="%s">',
                        AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules') . '&accupos_tab=settings',
                        urlencode($terminal['terminal_id']),
                        $this->l('Активировать')
                    );
                    $output .= '<i class="icon-check"></i>';
                    $output .= '</a>';
                }
                
                // Кнопка удаления
                $output .= sprintf(
                    '<a href="%s&deleteTerminal=1&terminal_id=%s" class="btn btn-danger btn-xs" onclick="return confirm(\'%s\');" title="%s">',
                    AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules') . '&accupos_tab=settings',
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
        // По умолчанию показываем текущий день (актуально для работы в течение дня)
        $showPeriod = Tools::getValue('show_period', 'today'); // 'today' или 'yesterday'
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

