#!/usr/bin/env php
<?php
/**
 * Финальное исправление AccuPosSync.php
 */

$file = '/var/www/dev.geek-shelter.com/modules/accupos_sync/classes/AccuPosSync.php';

$content = file_get_contents($file);

// Заменяем ошибочную строку на правильную (без экранирования)
$wrongSQL = '                \$idStock = Db::getInstance()->getValue(\'SELECT id_stock FROM \' . _DB_PREFIX_ . \'stock WHERE id_product = \' . (int)\$productId . \' AND id_warehouse = \' . (int)\$warehouseId . \' AND id_product_attribute = 0 LIMIT 1\');';

$correctSQL = '                $idStock = Db::getInstance()->getValue(\'SELECT id_stock FROM \' . _DB_PREFIX_ . \'stock WHERE id_product = \' . (int)$productId . \' AND id_warehouse = \' . (int)$warehouseId . \' AND id_product_attribute = 0 LIMIT 1\');';

if (strpos($content, $wrongSQL) !== false) {
    $content = str_replace($wrongSQL, $correctSQL, $content);
    file_put_contents($file, $content);
    echo "✅ SQL исправлен - убраны ненужные экранирования\n";
} else {
    echo "⚠️  Ошибочная строка не найдена\n";
    echo "Попытаемся найти и исправить другим способом...\n";
    
    // Альтернативный способ - найти и заменить через регулярное выражение
    $pattern = '/\\\\\$idStock = Db::getInstance/';
    if (preg_match($pattern, $content)) {
        $content = preg_replace('/\\\\\$idStock/', '$idStock', $content);
        $content = preg_replace('/\\\\\$productId/', '$productId', $content);
        $content = preg_replace('/\\\\\$warehouseId/', '$warehouseId', $content);
        file_put_contents($file, $content);
        echo "✅ SQL исправлен через регулярные выражения\n";
    }
}

echo "\nПроверка синтаксиса PHP...\n";
exec('php -l ' . $file . ' 2>&1', $output);
foreach ($output as $line) {
    echo $line . "\n";
}

echo "\n🚀 Запуск CRON синхронизации...\n";
echo "===============================================\n";
passthru('php /var/www/dev.geek-shelter.com/modules/accupos_sync/cron/sync.php');
?>

