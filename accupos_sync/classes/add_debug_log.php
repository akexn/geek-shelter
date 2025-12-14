#!/usr/bin/env php
<?php
$file = '/var/www/dev.geek-shelter.com/modules/accupos_sync/classes/AccuPosSync.php';

$content = file_get_contents($file);

// Вставляем логирование перед SQL запросом
$insertBefore = '$idStock = Db::getInstance()->getValue(\'SELECT id_stock FROM';
$replaceWith = '$sqlQuery = \'SELECT id_stock FROM `\' . _DB_PREFIX_ . \'stock` WHERE id_product = \' . (int)$productId . \' AND id_warehouse = \' . (int)$warehouseId . \' AND id_product_attribute = 0 LIMIT 1\';
                PrestaShopLogger::addLog(\'AccuPOS DEBUG SQL: \' . $sqlQuery, 1, null, \'AccuPosSync\');
                $idStock = Db::getInstance()->getValue($sqlQuery);';

$oldLine = '$idStock = Db::getInstance()->getValue(\'SELECT id_stock FROM `\' . _DB_PREFIX_ . \'stock` WHERE id_product = \' . (int)$productId . \' AND id_warehouse = \' . (int)$warehouseId . \' AND id_product_attribute = 0 LIMIT 1\');';

if (strpos($content, $oldLine) !== false) {
    $content = str_replace(
        $oldLine,
        '                $sqlQuery = \'SELECT id_stock FROM `\' . _DB_PREFIX_ . \'stock` WHERE id_product = \' . (int)$productId . \' AND id_warehouse = \' . (int)$warehouseId . \' AND id_product_attribute = 0 LIMIT 1\';
                PrestaShopLogger::addLog(\'AccuPOS DEBUG SQL: \' . $sqlQuery, 1, null, \'AccuPosSync\');
                $idStock = Db::getInstance()->getValue($sqlQuery);',
        $content
    );
    file_put_contents($file, $content);
    echo "✅ Добавлено логирование SQL\n";
} else {
    echo "❌ SQL не найден для добавления логирования\n";
    exit(1);
}

// Запускаем CRON
echo "\n🚀 Запуск CRON...\n";
echo "================================================\n";
passthru('php /var/www/dev.geek-shelter.com/modules/accupos_sync/cron/sync.php');
?>

