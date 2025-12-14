<?php
/**
 * Добавление детального логирования в updateProductStock
 */

$file = '/var/www/dev.geek-shelter.com/modules/accupos_sync/classes/AccuPosSync.php';
$content = file_get_contents($file);

// Находим метод updateProductStock и добавляем логирование
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
OLD;

$newMethod = <<<'NEW'
    private function updateProductStock($productId, $warehouseId, $qty)
    {
        try {
            // 🔍 DEBUG: начало метода
            error_log("AccuPOS updateProductStock START: Product=$productId, Warehouse=$warehouseId, Qty=$qty");
            
            // Использование StockAvailable API PrestaShop
            // Параметры: id_product, id_product_attribute, id_shop_group, quantity_delta
            // Для мульти-складской системы может потребоваться StockManager
            
            // Проверка версии PS для выбора правильного метода
            if (class_exists('StockAvailable')) {
                // Получение id_shop (если мультимагазин)
                $idShop = (int)Context::getContext()->shop->id;
                error_log("AccuPOS: idShop=$idShop");
                
                // Списание товара (отрицательное значение)
                $quantityDelta = -(int)$qty;
                error_log("AccuPOS: quantityDelta=$quantityDelta");
                
                // Обновление через StockAvailable
                // ✅ ПРАВИЛЬНЫЙ ПОРЯДОК: id_product, id_product_attribute, delta_quantity, id_shop
                error_log("AccuPOS: Calling StockAvailable::updateQuantity($productId, 0, $quantityDelta, $idShop)");
                
                $result = StockAvailable::updateQuantity(
                    $productId,
                    0, // id_product_attribute (0 для простого товара)
                    $quantityDelta,
                    $idShop
                );
                
                error_log("AccuPOS: StockAvailable::updateQuantity returned: " . var_export($result, true));
NEW;

$content = str_replace($oldMethod, $newMethod, $content);

file_put_contents($file, $content);

echo "✅ Детальное логирование добавлено!\n";
echo "Теперь все шаги будут логироваться в PHP error_log\n";

