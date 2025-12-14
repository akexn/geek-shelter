<?php
/**
 * Обновление метода updateProductStock для создания движений
 * Интеграция с prestatillstockperstore
 */

$file = '/var/www/dev.geek-shelter.com/modules/accupos_sync/classes/AccuPosSync.php';
$content = file_get_contents($file);

// Старый метод
$oldMethod = <<<'OLD'
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
OLD;

// Новый метод с интеграцией prestatillstockperstore
$newMethod = <<<'NEW'
    private function updateProductStock($productId, $warehouseId, $qty)
    {
        try {
            // Получение id_shop (если мультимагазин)
            $idShop = (int)Context::getContext()->shop->id;
            
            // Списание товара (отрицательное значение)
            $quantityDelta = -(int)$qty;
            
            // ✅ ШАГ 1: Обновление stock с созданием движения
            // Параметр add_movement=true создаёт запись в ps_stock_mvt
            if (class_exists('StockAvailable')) {
                StockAvailable::updateQuantity(
                    $productId,
                    0, // id_product_attribute (0 для простого товара)
                    $quantityDelta,
                    $idShop,
                    true // ✅ ВАЖНО: создать движение в ps_stock_mvt
                );
                
                // ✅ ШАГ 2: Привязка движения к складу через prestatillstockperstore
                if (class_exists('PrestatillStockMvt') && $warehouseId > 0) {
                    // Получаем последнее созданное движение для этого товара
                    $lastMvt = PrestatillStockMvt::getLastNegativeStockMvt(
                        $productId,
                        0 // id_product_attribute
                    );
                    
                    if ($lastMvt && isset($lastMvt['id_stock_mvt'])) {
                        // Привязываем движение к складу
                        PrestatillStockMvt::addIdWarehouseToMvt(
                            (int)$lastMvt['id_stock_mvt'],
                            (int)$warehouseId
                        );
                        
                        PrestaShopLogger::addLog(
                            sprintf(
                                'AccuPOS: Stock movement created - Product: %d, Warehouse: %d, Movement ID: %d, Delta: %d',
                                $productId,
                                $warehouseId,
                                $lastMvt['id_stock_mvt'],
                                $quantityDelta
                            ),
                            1,
                            null,
                            'AccuPosSync'
                        );
                    } else {
                        // Движение не найдено (может быть, не успело создаться)
                        PrestaShopLogger::addLog(
                            sprintf(
                                'AccuPOS: Stock updated but movement not found - Product: %d, Warehouse: %d, Delta: %d',
                                $productId,
                                $warehouseId,
                                $quantityDelta
                            ),
                            2,
                            null,
                            'AccuPosSync'
                        );
                    }
                } else {
                    // prestatillstockperstore не установлен или warehouse = 0
                    PrestaShopLogger::addLog(
                        sprintf(
                            'AccuPOS: Stock updated (no prestatill integration) - Product: %d, Delta: %d',
                            $productId,
                            $quantityDelta
                        ),
                        1,
                        null,
                        'AccuPosSync'
                    );
                }
                
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
NEW;

// Замена
if (strpos($content, $oldMethod) !== false) {
    $content = str_replace($oldMethod, $newMethod, $content);
    file_put_contents($file, $content);
    echo "✅ Метод updateProductStock успешно обновлён!\n";
    echo "\n📋 Что изменилось:\n";
    echo "1. ✅ Добавлен параметр add_movement=true в StockAvailable::updateQuantity()\n";
    echo "2. ✅ Добавлена интеграция с PrestatillStockMvt\n";
    echo "3. ✅ Движения привязываются к складам через addIdWarehouseToMvt()\n";
    echo "4. ✅ Детальное логирование каждого шага\n";
} else {
    echo "❌ Старый метод не найден! Возможно, файл уже изменён.\n";
}

