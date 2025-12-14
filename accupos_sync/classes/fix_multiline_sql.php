#!/usr/bin/env php
<?php
$file = '/var/www/dev.geek-shelter.com/modules/accupos_sync/classes/AccuPosSync.php';

$content = file_get_contents($file);

// Заменяем multiline SQL на singleline
// 1. Запрос для getLastTransactionId
$oldSQL1 = "'
                SELECT accupos_transaction_id 
                FROM ' . _DB_PREFIX_ . 'accupos_transactions 
                WHERE status = \"success\" 
                ORDER BY date_processed DESC 
                LIMIT 1
            '";

$newSQL1 = "'SELECT accupos_transaction_id FROM ' . _DB_PREFIX_ . 'accupos_transactions WHERE status = \"success\" ORDER BY date_processed DESC LIMIT 1'";

$content = str_replace($oldSQL1, $newSQL1, $content);

// 2. Ищем и исправляем другие multiline запросы
$content = preg_replace('/\'\s+SELECT\s+/i', "'SELECT ", $content);
$content = preg_replace('/\s+FROM\s+\'/i', " FROM '", $content);
$content = preg_replace('/\s+WHERE\s+/i', " WHERE ", $content);
$content = preg_replace('/\s+ORDER\s+BY\s+/i', " ORDER BY ", $content);
$content = preg_replace('/\s+LIMIT\s+/i', " LIMIT ", $content);

file_put_contents($file, $content);
echo "✅ Мультистрочные SQL запросы переведены в однострочные\n";

// Проверяем синтаксис
echo "\nПроверка синтаксиса PHP:\n";
exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output);
echo implode("\n", $output) . "\n";

// Запускаем CRON
echo "\n🚀 Запуск CRON синхронизации...\n";
echo "================================================\n";
passthru('php /var/www/dev.geek-shelter.com/modules/accupos_sync/cron/sync.php');
?>

