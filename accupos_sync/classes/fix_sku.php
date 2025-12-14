<?php
/**
 * Исправление findProductBySku - удаление неправильных кавычек
 */

$file = '/var/www/dev.geek-shelter.com/modules/accupos_sync/classes/AccuPosSync.php';
$content = file_get_contents($file);

// Счётчик замен
$count = 0;

// Замена 1: reference
$old = 'WHERE reference = "' . "'" . '" . pSQL($sku) . "' . "'" . '" AND active = 1';
$new = 'WHERE reference = ' . "'" . '" . pSQL($sku) . "' . "'" . ' AND active = 1';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $count++;
    echo "✅ Замена 1 (reference): OK\n";
} else {
    echo "⚠️ Замена 1 (reference): не найдена\n";
}

// Замена 2: ean13
$old = 'WHERE ean13 = "' . "'" . '" . pSQL($sku) . "' . "'" . '" AND active = 1';
$new = 'WHERE ean13 = ' . "'" . '" . pSQL($sku) . "' . "'" . ' AND active = 1';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $count++;
    echo "✅ Замена 2 (ean13): OK\n";
} else {
    echo "⚠️ Замена 2 (ean13): не найдена\n";
}

// Замена 3: upc
$old = 'WHERE upc = "' . "'" . '" . pSQL($sku) . "' . "'" . '" AND active = 1';
$new = 'WHERE upc = ' . "'" . '" . pSQL($sku) . "' . "'" . ' AND active = 1';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $count++;
    echo "✅ Замена 3 (upc): OK\n";
} else {
    echo "⚠️ Замена 3 (upc): не найдена\n";
}

if ($count > 0) {
    file_put_contents($file, $content);
    echo "\n✅ Всего заменено: $count ошибок\n";
} else {
    echo "\n⚠️ Ошибки не найдены - проверьте вручную\n";
    exit(1);
}

// Проверка синтаксиса
echo "\nПроверка синтаксиса PHP...\n";
exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
echo implode("\n", $output) . "\n";

if ($code !== 0) {
    echo "❌ Синтаксическая ошибка!\n";
    exit(1);
}

echo "✅ Готово! Файл исправлен и синтаксис верен.\n";
?>

