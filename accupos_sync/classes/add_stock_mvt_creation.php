#!/usr/bin/env php
<?php
/**
 * Патч: добавить создание stock_mvt при обновлении остатков
 */

$file = '/var/www/dev.geek-shelter.com/modules/accupos_sync/classes/AccuPosSync.php';
$content = file_get_contents($file);

// Находим место для вставки - после StockAvailable::updateQuantity()
$searchString = "StockAvailable::updateQuantity(
                    \$productId,
                    0, // id_product_attribute (0 для простого товара)
                    \$quantityDelta,
                    \$idShop
                );
                
                // Дополнительное логирование";

$insertCode = "StockAvailable::updateQuantity(
                    \$productId,
                    0, // id_product_attribute (0 для простого товара)
                    \$quantityDelta,
                    \$idShop
                );
                
                // ===== НОВОЕ: Создание записи о движении товара =====
                \$this->createStockMovement(\$productId, \$warehouseId, \$qty);
                
                // Дополнительное логирование";

if (strpos($content, $searchString) !== false) {
    $content = str_replace($searchString, $insertCode, $content);
    
    // Теперь добавляем функцию createStockMovement перед закрытием класса
    $functionCode = <<<'PHP'
    
    /**
     * Создание записи о движении товара (stock_mvt)
     * 
     * @param int $productId ID товара
     * @param int $warehouseId ID склада
     * @param float $qty Количество
     */
    private function createStockMovement($productId, $warehouseId, $qty)
    {
        try {
            // Получить id_stock (связь product + warehouse)
            $idStock = Db::getInstance()->getValue(
                'SELECT id_stock FROM ' . _DB_PREFIX_ . 'stock 
                 WHERE id_product = ' . (int)$productId . ' 
                 AND id_warehouse = ' . (int)$warehouseId . ' 
                 AND id_product_attribute = 0 
                 LIMIT 1'
            );
            
            if (!$idStock) {
                return false;
            }
            
            // Получить информацию о товаре
            $product = new Product($productId);
            $priceTe = (float)$product->wholesale_price ?: 0;
            
            // Получить информацию о сотруднике (если доступно)
            $employee = null;
            try {
                $employee = Context::getContext()->employee;
            } catch (Exception $e) {
                // В CRON нет employee context
            }
            
            $employeeId = $employee ? (int)$employee->id : 0;
            $employeeLastname = $employee ? pSQL($employee->lastname) : 'System';
            $employeeFirstname = $employee ? pSQL($employee->firstname) : 'AccuPOS';
            
            // Создание записи в stock_mvt
            $mvtData = array(
                'id_stock' => (int)$idStock,
                'id_order' => null,
                'id_supply_order' => null,
                'id_stock_mvt_reason' => 2, // Reason ID 2 = "Decrease"
                'id_employee' => $employeeId,
                'employee_lastname' => $employeeLastname,
                'employee_firstname' => $employeeFirstname,
                'physical_quantity' => (int)abs($qty),
                'date_add' => date('Y-m-d H:i:s'),
                'sign' => -1, // Отрицательное = списание
                'price_te' => $priceTe,
                'last_wa' => 0,
                'current_wa' => 0,
                'referer' => null
            );
            
            // Вставка в таблицу
            Db::getInstance()->insert(_DB_PREFIX_ . 'stock_mvt', $mvtData);
            
            PrestaShopLogger::addLog(
                sprintf('AccuPOS: Stock movement created - Product: %d, Warehouse: %d, Qty: %d',
                    $productId, $warehouseId, $qty),
                1, null, 'AccuPosSync'
            );
            
            return true;
            
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'AccuPOS: Failed to create stock movement - ' . $e->getMessage(),
                2, null, 'AccuPosSync'
            );
            return false;
        }
    }
PHP;
    
    // Находим закрытие класса и вставляем функцию перед ним
    $endOfClass = strrpos($content, '}');
    if ($endOfClass !== false) {
        $content = substr_replace($content, $functionCode . "\n", $endOfClass, 0);
    }
    
    file_put_contents($file, $content);
    echo "✅ Добавлена функция createStockMovement\n";
} else {
    echo "❌ Не найдено место для вставки\n";
    exit(1);
}

// Проверяем синтаксис
exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output);
echo implode("\n", $output) . "\n";

// Тест CRON
echo "\n🚀 Тест CRON...\n";
passthru('php /var/www/dev.geek-shelter.com/modules/accupos_sync/cron/sync.php 2>&1 | tail -20');
?>

