#!/usr/bin/env php
<?php
/**
 * Исправление: многострочный SQL в createStockMovement()
 */

$file = '/var/www/dev.geek-shelter.com/modules/accupos_sync/classes/AccuPosSync.php';
$content = file_get_contents($file);

// Найти и заменить многострочный SQL запрос
$oldSQL = <<<'PHP'
            // Получить id_stock (связь product + warehouse)
            $idStock = Db::getInstance()->getValue(
                'SELECT id_stock FROM ' . _DB_PREFIX_ . 'stock 
                 WHERE id_product = ' . (int)$productId . ' 
                 AND id_warehouse = ' . (int)$warehouseId . ' 
                 AND id_product_attribute = 0 
                 LIMIT 1'
            );
PHP;

$newSQL = <<<'PHP'
            // Получить id_stock (связь product + warehouse)
            $idStock = Db::getInstance()->getValue(
                'SELECT id_stock FROM ' . _DB_PREFIX_ . 'stock WHERE id_product = ' . (int)$productId . ' AND id_warehouse = ' . (int)$warehouseId . ' AND id_product_attribute = 0 LIMIT 1'
            );
PHP;

if (strpos($content, $oldSQL) !== false) {
    $content = str_replace($oldSQL, $newSQL, $content);
    file_put_contents($file, $content);
    echo "✅ Многострочный SQL в createStockMovement() исправлен\n";
} else {
    echo "❌ Не найден старый SQL\n";
    exit(1);
}

// Проверка синтаксиса
exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output);
echo implode("\n", $output) . "\n";

// Тест Manual Sync
echo "\n🚀 Тест Manual Sync...\n";
echo "Запустите вручную через админку кнопку 'Manual Sync'\n";
?>

