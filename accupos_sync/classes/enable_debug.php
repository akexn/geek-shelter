<?php
/**
 * Включение debug режима PrestaShop для диагностики ошибок
 */

$configFile = '/var/www/dev-ps8-test.geek-shelter.com/config/defines.inc.php';

if (file_exists($configFile)) {
    $content = file_get_contents($configFile);
    
    // Включаем debug
    if (strpos($content, "define('_PS_MODE_DEV_', false)") !== false) {
        $content = str_replace(
            "define('_PS_MODE_DEV_', false)",
            "define('_PS_MODE_DEV_', true)",
            $content
        );
        file_put_contents($configFile, $content);
        echo "✓ Debug режим включён в defines.inc.php\n";
    } else {
        echo "✓ Debug режим уже включён или не найден\n";
    }
} else {
    echo "⚠️ Файл defines.inc.php не найден\n";
}

// Также включаем в parameters.php
$paramsFile = '/var/www/dev-ps8-test.geek-shelter.com/app/config/parameters.php';

if (file_exists($paramsFile)) {
    $content = file_get_contents($paramsFile);
    
    if (strpos($content, "'use_debug_toolbar' => false") !== false) {
        $content = str_replace(
            "'use_debug_toolbar' => false",
            "'use_debug_toolbar' => true",
            $content
        );
        file_put_contents($paramsFile, $content);
        echo "✓ Debug toolbar включён в parameters.php\n";
    } else {
        echo "✓ Debug toolbar уже включён\n";
    }
}

// Очищаем кэш
exec("rm -rf /var/www/dev-ps8-test.geek-shelter.com/var/cache/prod/* 2>&1");
echo "✓ Кэш очищен\n\n";

echo "✅ Debug режим активирован!\n";
echo "Теперь откройте товар в браузере - вы увидите детальную ошибку.\n";

