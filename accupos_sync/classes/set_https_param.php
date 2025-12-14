<?php
/**
 * Add HTTPS to parameters.php
 */

$paramsFile = '/var/www/dev-ps8-test.geek-shelter.com/app/config/parameters.php';

// Read current params
$content = file_get_contents($paramsFile);

// Check if already has https setting
if (strpos($content, "'https' =>") !== false) {
    echo "❌ Already has https parameter\n";
    exit;
}

// Add https parameter before the closing bracket
$content = str_replace(
    "    'new_cookie_key' =>",
    "    'https' => 'https',\n    'new_cookie_key' =>",
    $content
);

// Write back
file_put_contents($paramsFile, $content);

echo "✅ HTTPS parameter added to parameters.php\n";
echo "Content:\n";
system('tail -10 ' . $paramsFile);
?>
