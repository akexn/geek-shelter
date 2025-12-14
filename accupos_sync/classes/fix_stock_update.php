#!/usr/bin/env php
<?php
/**
 * Исправление: добавить логирование и исправить updateProductStock
 */

$file = '/var/www/dev.geek-shelter.com/modules/accupos_sync/classes/AccuPosSync.php';
$content = file_get_contents($file);

// Заменяем функцию updateProductStock полностью
$oldFunction = <<<'PHP'
    private function updateProductStock($productId, $warehouseId, $qty)
    {
        try {
            // Использование StockAvailable API PrestaShop
            // Параметры: id_product, id_product_attribute, id_shop_group, quantity_delta
            // Для мульти-складской системы может потребоваться StockManager
            
            // Проверка версии PS для выбора правильного метода
            if (class_exists('StockAvailable')) {
                // Получение id_shop (если мультимагазин)
                $idShop = (int)Context::getContext()->shop->id;
                
                // Списание товара (отрицательное значение)
                $quantityDelta = -(int)$qty;
                
                // Обновление через StockAvailable
                // id_product, id_product_attribute, id_warehouse, quantity_delta, id_shop
                StockAvailable::updateQuantity(
                    $productId,
                    0, // id_product_attribute (0 для простого товара)
                    $quantityDelta,
                    $idShop
                );
                
                // ===== НОВОЕ: Создание записи о движении товара =====
                $this->createStockMovement($productId, $warehouseId, $qty);
                
                // Дополнительное логирование
                PrestaShopLogger::addLog(
                    sprintf(
                        'AccuPOS: Stock updated - Product: %d, Warehouse: %d, Delta: %d',
                        $productId,
                        $warehouseId,
                        $quantityDelta
                    ),
                    1,
                    null,
                    'AccuPosSync'
                );
                
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'AccuPOS Stock Update Error: ' . $e->getMessage(),
                4,
                null,
                'AccuPosSync'
            );
            return false;
        }
    }
PHP;

$newFunction = <<<'PHP'
    private function updateProductStock($productId, $warehouseId, $qty)
    {
        try {
            // Логирование начала
            PrestaShopLogger::addLog(
                sprintf('AccuPOS: Updating stock - Product: %d, Warehouse: %d, Qty: %d', 
                    $productId, $warehouseId, $qty),
                1, null, 'AccuPosSync'
            );
            
            // Проверка товара
            if (!$productId || !$warehouseId || !$qty) {
                PrestaShopLogger::addLog(
                    sprintf('AccuPOS: Invalid params - Product: %s, Warehouse: %s, Qty: %s', 
                        $productId, $warehouseId, $qty),
                    2, null, 'AccuPosSync'
                );
                return false;
            }
            
            // Списание товара (отрицательное значение)
            $quantityDelta = -(int)$qty;
            
            // Получение id_shop - с fallback на 1
            $idShop = 1;
            try {
                $context = Context::getContext();
                if ($context && $context->shop && $context->shop->id) {
                    $idShop = (int)$context->shop->id;
                }
            } catch (Exception $e) {
                // В CRON нет полного context, используем default shop
                PrestaShopLogger::addLog(
                    'AccuPOS: No context, using default shop ID = 1',
                    1, null, 'AccuPosSync'
                );
            }
            
            // Обновление через StockAvailable
            if (class_exists('StockAvailable')) {
                StockAvailable::updateQuantity(
                    $productId,
                    0, // id_product_attribute (0 для простого товара)
                    $quantityDelta,
                    $idShop
                );
                
                PrestaShopLogger::addLog(
                    sprintf('AccuPOS: StockAvailable updated - Product: %d, Shop: %d, Delta: %d',
                        $productId, $idShop, $quantityDelta),
                    1, null, 'AccuPosSync'
                );
            } else {
                PrestaShopLogger::addLog(
                    'AccuPOS: StockAvailable class not found!',
                    2, null, 'AccuPosSync'
                );
                return false;
            }
            
            // Создание записи о движении товара
            $this->createStockMovement($productId, $warehouseId, $qty);
            
            return true;
            
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'AccuPOS Stock Update Error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString(),
                4, null, 'AccuPosSync'
            );
            return false;
        }
    }
PHP;

if (strpos($content, $oldFunction) !== false) {
    $content = str_replace($oldFunction, $newFunction, $content);
    file_put_contents($file, $content);
    echo "✅ Функция updateProductStock обновлена с логированием\n";
} else {
    echo "❌ Не найдена старая функция\n";
    exit(1);
}

// Проверяем синтаксис
exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output);
echo implode("\n", $output) . "\n";

// Тест CRON
echo "\n🚀 Тест CRON...\n";
passthru('php /var/www/dev.geek-shelter.com/modules/accupos_sync/cron/sync.php 2>&1 | tail -20');
?>

