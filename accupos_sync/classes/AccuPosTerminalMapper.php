<?php
/**
 * AccuPOS Terminal to Warehouse Mapper
 * 
 * Управление маппингом терминалов AccuPOS → склады PrestaShop
 * Интеграция с модулем prestatillstockperstore
 * 
 * @author    Aleksei Nekrasov <info@impserver.ru>
 * @copyright 2025 ООО «Свобода» / impserver.ru
 * @license   Proprietary
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Класс для маппинга терминалов на склады
 */
class AccuPosTerminalMapper
{
    /**
     * Проверить существование терминала в таблице маппинга
     *
     * @param string $terminal_id
     * @return bool
     */
    private static function terminalExists($terminal_id)
    {
        $terminal_id = (string)$terminal_id;
        if ($terminal_id === '') {
            return false;
        }
        $id = (int)Db::getInstance()->getValue(
            'SELECT id FROM ' . _DB_PREFIX_ . 'accupos_terminals WHERE terminal_id = \'' . pSQL($terminal_id) . '\''
        );
        return $id > 0;
    }
    /**
     * Получить ID склада по ID терминала
     * 
     * @param string $terminal_id Идентификатор терминала AccuPOS
     * @return int ID склада PrestaShop
     */
    public static function getWarehouseByTerminal($terminal_id)
    {
        // Шаг 1: Проверить таблицу ps_accupos_terminals
        $warehouse = Db::getInstance()->getValue('SELECT warehouse_id FROM ' . _DB_PREFIX_ . 'accupos_terminals WHERE terminal_id = \'' . pSQL($terminal_id) . '\' AND active = 1');

        if ($warehouse) {
            return (int)$warehouse;
        }

        // Шаг 2: Fallback - попробовать найти в prestatillstockperstore
        // (требует изучения структуры таблиц этого модуля)
        $warehouse = self::findInPrestatillModule($terminal_id);
        
        if ($warehouse) {
            return (int)$warehouse;
        }

        // Шаг 3: Использовать склад по умолчанию
        $defaultWarehouse = Configuration::get('ACCUPOS_DEFAULT_WAREHOUSE');
        
        PrestaShopLogger::addLog(
            sprintf('AccuPOS: Terminal "%s" not mapped, using default warehouse %d', $terminal_id, $defaultWarehouse),
            2,
            null,
            'AccuPosTerminalMapper'
        );

        return (int)$defaultWarehouse;
    }

    /**
     * Поиск маппинга в модуле prestatillstockperstore
     * 
     * @param string $terminal_id Идентификатор терминала
     * @return int|false ID склада или false
     */
    private static function findInPrestatillModule($terminal_id)
    {
        // TODO: Требуется изучение структуры таблиц prestatillstockperstore
        // Предполагаемые таблицы:
        // - 4667750Dimaprestatill_stock_per_store
        // - 4667750Dimastore
        
        // Пример запроса (требует проверки):
        /*
        $warehouse = Db::getInstance()->getValue('
            SELECT warehouse_id 
            FROM `4667750Dimaprestatill_stock_per_store` 
            WHERE store_id = "'.pSQL($terminal_id).'"
        ');
        */

        return false; // Временно возвращаем false до изучения структуры
    }

    /**
     * Добавить маппинг терминала
     * 
     * @param string $terminal_id Идентификатор терминала
     * @param int $warehouse_id ID склада
     * @param string $store_name Название магазина
     * @param bool $active Активность
     * @return bool Успешность добавления
     */
    public static function addTerminal($terminal_id, $warehouse_id, $store_name = '', $active = true)
    {
        // Проверка существования
        $exists = Db::getInstance()->getValue('SELECT id FROM ' . _DB_PREFIX_ . 'accupos_terminals WHERE terminal_id = \'' . pSQL($terminal_id) . '\'');

        $data = array(
            'terminal_id' => pSQL($terminal_id),
            'warehouse_id' => (int)$warehouse_id,
            'store_name' => pSQL($store_name),
            'active' => $active ? 1 : 0,
            'date_upd' => date('Y-m-d H:i:s')
        );

        if ($exists) {
            // Обновление существующего
            return Db::getInstance()->update(
                'accupos_terminals',
                $data,
                'terminal_id = \'' . pSQL($terminal_id) . '\''
            );
        } else {
            // Добавление нового
            $data['date_add'] = date('Y-m-d H:i:s');
            
            return Db::getInstance()->insert('accupos_terminals', $data);
        }
    }

    /**
     * Удалить маппинг терминала
     * 
     * @param string $terminal_id Идентификатор терминала
     * @return bool Успешность удаления
     */
    public static function removeTerminal($terminal_id)
    {
        if (!self::terminalExists($terminal_id)) {
            return false;
        }
        return Db::getInstance()->delete(
            'accupos_terminals',
            'terminal_id = \'' . pSQL($terminal_id) . '\''
        );
    }

    /**
     * Получить список всех терминалов
     * 
     * @param bool $activeOnly Только активные
     * @return array Список терминалов
     */
    public static function getAllTerminals($activeOnly = false)
    {
        $sql = 'SELECT t.*, w.name as warehouse_name
                FROM ' . _DB_PREFIX_ . 'accupos_terminals t
                LEFT JOIN ' . _DB_PREFIX_ . 'warehouse w ON t.warehouse_id = w.id_warehouse';
        
        if ($activeOnly) {
            $sql .= ' WHERE t.active = 1';
        }
        
        $sql .= ' ORDER BY t.terminal_id';

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Деактивировать терминал
     * 
     * @param string $terminal_id Идентификатор терминала
     * @return bool Успешность деактивации
     */
    public static function deactivateTerminal($terminal_id)
    {
        if (!self::terminalExists($terminal_id)) {
            return false;
        }
        return Db::getInstance()->update(
            'accupos_terminals',
            array(
                'active' => 0,
                'date_upd' => date('Y-m-d H:i:s')
            ),
            'terminal_id = \'' . pSQL($terminal_id) . '\''
        );
    }

    /**
     * Активировать терминал
     * 
     * @param string $terminal_id Идентификатор терминала
     * @return bool Успешность активации
     */
    public static function activateTerminal($terminal_id)
    {
        if (!self::terminalExists($terminal_id)) {
            return false;
        }
        return Db::getInstance()->update(
            'accupos_terminals',
            array(
                'active' => 1,
                'date_upd' => date('Y-m-d H:i:s')
            ),
            'terminal_id = \'' . pSQL($terminal_id) . '\''
        );
    }

    /**
     * Проверить существование склада в PrestaShop
     * 
     * @param int $warehouse_id ID склада
     * @return bool Существует ли склад
     */
    public static function warehouseExists($warehouse_id)
    {
        $warehouse = Db::getInstance()->getValue('
            SELECT id_warehouse 
            FROM ' . _DB_PREFIX_ . 'warehouse 
            WHERE id_warehouse = ' . (int)$warehouse_id
        );

        return (bool)$warehouse;
    }
}

