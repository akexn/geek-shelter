#!/usr/bin/env php
<?php
$file = '/var/www/dev.geek-shelter.com/modules/accupos_sync/classes/AccuPosSync.php';

$content = file_get_contents($file);

// Заменяем SQL - добавляем обратные кавычки вокруг таблицы
$oldSQL = "'SELECT id_stock FROM ' . _DB_PREFIX_ . 'stock WHERE id_product = '";
$newSQL = "'SELECT id_stock FROM `' . _DB_PREFIX_ . 'stock` WHERE id_product = '";

if (strpos($content, $oldSQL) !== false) {
    $content = str_replace($oldSQL, $newSQL, $content);
    file_put_contents($file, $content);
    echo "✅ Добавлены обратные кавычки вокруг таблицы stock\n\n";
} else {
    echo "❌ SQL не найден\n";
    exit(1);
}

// Проверяем синтаксис
echo "Проверка синтаксиса PHP...\n";
exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output);
echo implode("\n", $output) . "\n\n";

// Запускаем CRON
echo "🚀 Запуск CRON...\n";
echo "================================================\n";
passthru('php /var/www/dev.geek-shelter.com/modules/accupos_sync/cron/sync.php');
?>

