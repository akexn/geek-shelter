<?php
/**
 * Замена метода updateProductStock на новую версию с движениями
 */

$file = '/var/www/dev.geek-shelter.com/modules/accupos_sync/classes/AccuPosSync.php';
$lines = file($file);

// Находим начало и конец метода updateProductStock
$startLine = null;
$endLine = null;
$braceCount = 0;
$inMethod = false;

foreach ($lines as $num => $line) {
    if (strpos($line, 'private function updateProductStock') !== false) {
        $startLine = $num;
        $inMethod = true;
        continue;
    }
    
    if ($inMethod) {
        // Считаем фигурные скобки
        $braceCount += substr_count($line, '{');
        $braceCount -= substr_count($line, '}');
        
        // Когда braceCount вернётся к 0, метод закончился
        if ($braceCount == 0 && strpos($line, '}') !== false) {
            $endLine = $num;
            break;
        }
    }
}

if ($startLine === null || $endLine === null) {
    die("❌ Метод updateProductStock не найден!\n");
}

echo "Найден метод: строки $startLine - $endLine\n";

// Новый метод
$newMethod = <<<'NEWMETHOD'
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

NEWMETHOD;

// Заменяем строки
$newLines = array_merge(
    array_slice($lines, 0, $startLine), // До метода
    explode("\n", $newMethod . "\n"),   // Новый метод
    array_slice($lines, $endLine + 1)   // После метода
);

file_put_contents($file, implode('', $newLines));

echo "✅ Метод updateProductStock успешно заменён!\n\n";
echo "📋 Что изменилось:\n";
echo "1. ✅ Добавлен параметр add_movement=true в StockAvailable::updateQuantity()\n";
echo "2. ✅ Добавлена интеграция с PrestatillStockMvt\n";
echo "3. ✅ Движения привязываются к складам через addIdWarehouseToMvt()\n";
echo "4. ✅ Детальное логирование каждого шага\n";

