<?php
/**
 * Fix Frontend Redirect Loops
 * Отключаем все редиректы для фронтенда
 */

$conn = new mysqli('localhost', 'root', '', 'prestashop_dev_ps8_test');

if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

echo "=== FIXING FRONTEND REDIRECTS ===\n\n";

// Disable all redirect-related settings
$updates = [
    // Disable SSL/HTTPS redirects
    "UPDATE 4667750Dimaconfiguration SET value = '0' WHERE name IN ('PS_SSL_ENABLED', 'PS_SSL_ENABLED_EVERYWHERE', 'PS_FORCE_HTTPS')",
    
    // Set HTTP URLs (not HTTPS)
    "UPDATE 4667750Dimashop_url SET domain = 'dev-ps8-test.geek-shelter.com', domain_ssl = 'dev-ps8-test.geek-shelter.com'",
    
    // Ensure shop is set to HTTP
    "UPDATE 4667750Dimaconfiguration SET value = 'http://dev-ps8-test.geek-shelter.com/' WHERE name = 'PS_SHOP_DOMAIN'",
    "UPDATE 4667750Dimaconfiguration SET value = 'dev-ps8-test.geek-shelter.com' WHERE name IN ('PS_SHOP_DOMAIN_SSL', 'PS_DOMAIN_SSL')",
    
    // Clear all caches related to redirects
    "DELETE FROM 4667750Dimaconfiguration_lang WHERE id_configuration IN (SELECT id_configuration FROM 4667750Dimaconfiguration WHERE name LIKE '%REDIRECT%')",
];

foreach ($updates as $sql) {
    if ($conn->query($sql) === TRUE) {
        echo "✅ Executed\n";
    } else {
        echo "⚠️ " . $conn->error . "\n";
    }
}

// Show final config
echo "\n=== CURRENT SHOP URLS ===\n";
$result = $conn->query("SELECT * FROM 4667750Dimashop_url");
if ($row = $result->fetch_assoc()) {
    echo "Domain: " . $row['domain'] . "\n";
    echo "Domain SSL: " . $row['domain_ssl'] . "\n";
}

echo "\n=== SSL SETTINGS ===\n";
$result = $conn->query("SELECT name, value FROM 4667750Dimaconfiguration WHERE name LIKE '%SSL%' OR name LIKE '%FORCE%' OR name LIKE '%HTTPS%'");
while ($row = $result->fetch_assoc()) {
    echo $row['name'] . " = " . $row['value'] . "\n";
}

$conn->close();
echo "\n✅ Frontend fixes applied!\n";
echo "Clear browser cache and try: https://dev-ps8-test.geek-shelter.com/\n";
?>
