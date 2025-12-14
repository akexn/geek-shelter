<?php
/**
 * Исправление порядка параметров в StockAvailable::updateQuantity()
 * ПРОБЛЕМА: параметры были в порядке (product, attribute, quantity, shop)
 * ПРАВИЛЬНО: (product, attribute, shop, quantity)
 */

$file = '/var/www/dev.geek-shelter.com/modules/accupos_sync/classes/AccuPosSync.php';

if (!file_exists($file)) {
    die("❌ Файл не найден: $file\n");
}

$lines = file($file);

// Проверяем текущие строки 406-407 (индексы 406-407 в массиве)
echo "До исправления:\n";
echo "Line 407: " . trim($lines[406]) . "\n";
echo "Line 408: " . trim($lines[407]) . "\n\n";

// Меняем порядок: было $quantityDelta, $idShop → стало $idShop, $quantityDelta
$lines[406] = "                    \$idShop,\n";
$lines[407] = "                    \$quantityDelta\n";

file_put_contents($file, implode('', $lines));

echo "После исправления:\n";
echo "Line 407: " . trim($lines[406]) . "\n";
echo "Line 408: " . trim($lines[407]) . "\n\n";

echo "✅ Параметры исправлены!\n";
echo "Правильный порядок: \$productId, 0, \$idShop, \$quantityDelta\n";

