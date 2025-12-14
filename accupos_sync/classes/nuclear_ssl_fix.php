<?php
/**
 * NUCLEAR SSL FIX - Полное отключение редиректов
 */

$conn = new mysqli('localhost', 'root', '', 'prestashop_dev_ps8_test');

if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

echo "=== NUCLEAR SSL/REDIRECT FIX ===\n\n";

// Disable ALL SSL/HTTPS redirects
$updates = [
    // SSL Settings
    "UPDATE 4667750Dimaconfiguration SET value = '0' WHERE name = 'PS_SSL_ENABLED'",
    "UPDATE 4667750Dimaconfiguration SET value = '0' WHERE name = 'PS_SSL_ENABLED_EVERYWHERE'",
    "UPDATE 4667750Dimaconfiguration SET value = '0' WHERE name = 'PS_FORCE_HTTPS'",
    
    // HTTP Settings
    "UPDATE 4667750Dimaconfiguration SET value = 'http' WHERE name = 'PS_SHOP_DOMAIN'",
    "UPDATE 4667750Dimaconfiguration SET value = 'dev-ps8-test.geek-shelter.com' WHERE name = 'PS_SHOP_DOMAIN' OR name = 'PS_SHOP_DOMAIN_SSL'",
    
    // Shop URLs - set both to HTTP
    "UPDATE 4667750Dimashop_url SET domain = 'dev-ps8-test.geek-shelter.com', domain_ssl = 'dev-ps8-test.geek-shelter.com'",
];

foreach ($updates as $sql) {
    if ($conn->query($sql) === TRUE) {
        echo "✅ " . substr($sql, 0, 60) . "...\n";
    } else {
        echo "⚠️ Error: " . $conn->error . "\n";
    }
}

// Clear cache entries
$conn->query("DELETE FROM 4667750Dimacache");
$conn->query("DELETE FROM 4667750Dimacache_lang");

echo "\n=== Current Configuration ===\n";

$result = $conn->query("SELECT * FROM 4667750Dimashop_url");
if ($row = $result->fetch_assoc()) {
    echo "Shop URL Domain:     " . $row['domain'] . "\n";
    echo "Shop URL Domain SSL: " . $row['domain_ssl'] . "\n";
}

$result = $conn->query("SELECT name, value FROM 4667750Dimaconfiguration WHERE name LIKE 'PS_FORCE%' OR name LIKE 'PS_SSL%'");
while ($row = $result->fetch_assoc()) {
    echo $row['name'] . " = " . $row['value'] . "\n";
}

$conn->close();

echo "\n✅ Nuclear fix applied!\n";
echo "Now clear browser cache and try again.\n";
?>
