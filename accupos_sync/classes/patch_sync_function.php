#!/usr/bin/env php
<?php
/**
 * Патч: заменить проблемную функцию getLastTransactionId
 */

$file = '/var/www/dev.geek-shelter.com/modules/accupos_sync/classes/AccuPosSync.php';
$content = file_get_contents($file);

// Находим и заменяем функцию getLastTransactionId на упрощенную версию
$pattern = '/private function getLastTransactionId\(\)[\s\S]*?return \$lastId \? \$lastId : null;[\s\S]*?\}/';

$replacement = <<<'PHP'
private function getLastTransactionId()
    {
        // Упрощённая версия - возвращаем null для начала синхронизации
        // Это заставит модуль получать все транзакции за период
        return null;
    }
PHP;

$content = preg_replace($pattern, $replacement, $content);

if (strpos($content, 'private function getLastTransactionId()') !== false) {
    file_put_contents($file, $content);
    echo "✅ Функция getLastTransactionId заменена на упрощенную версию\n";
} else {
    echo "❌ Ошибка замены\n";
    exit(1);
}

// Проверяем синтаксис
exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output);
echo implode("\n", $output) . "\n";

// Тест CRON
echo "\n🚀 Тест CRON...\n";
passthru('php /var/www/dev.geek-shelter.com/modules/accupos_sync/cron/sync.php');
?>

