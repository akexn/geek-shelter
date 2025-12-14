<?php
/**
 * ИСПРАВЛЕНИЕ #2: Возврат к ПРАВИЛЬНОМУ порядку параметров
 * StockAvailable::updateQuantity($id_product, $id_product_attribute, $delta_quantity, $id_shop)
 *                                                                     ^^^^^^^^^^^^^^^^ ^^^^^^^^
 *                                                                     3-й параметр     4-й параметр
 */

$file = '/var/www/dev.geek-shelter.com/modules/accupos_sync/classes/AccuPosSync.php';

if (!file_exists($file)) {
    die("❌ Файл не найден: $file\n");
}

$lines = file($file);

// Проверяем текущие строки
echo "До исправления:\n";
echo "Line 407: " . trim($lines[406]) . "\n";
echo "Line 408: " . trim($lines[407]) . "\n\n";

// ПРАВИЛЬНЫЙ порядок: $quantityDelta (3-й), $idShop (4-й)
$lines[406] = "                    \$quantityDelta,\n";
$lines[407] = "                    \$idShop\n";

file_put_contents($file, implode('', $lines));

echo "После исправления:\n";
echo "Line 407: " . trim($lines[406]) . "\n";
echo "Line 408: " . trim($lines[407]) . "\n\n";

echo "✅ Параметры ИСПРАВЛЕНЫ (правильный порядок)!\n";
echo "StockAvailable::updateQuantity(\$productId, 0, \$quantityDelta, \$idShop)\n";
echo "                                                 ^^^^^^^^^^^^^^  ^^^^^^^\n";
echo "                                                 3-й параметр    4-й параметр\n";

