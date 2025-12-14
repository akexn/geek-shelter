<?php
/**
 * Fix SSL/HTTPS Configuration
 * Отключаем force SSL redirect loop
 */

$conn = new mysqli('localhost', 'root', '', 'prestashop_dev_ps8_test');

if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

echo "=== Fixing SSL/HTTPS Configuration ===\n\n";

// Disable force SSL
$queries = [
    "UPDATE 4667750Dimaconfiguration SET value = '0' WHERE name = 'PS_SSL_ENABLED' OR name = 'PS_SSL_ENABLED_EVERYWHERE'",
    "UPDATE 4667750Dimaconfiguration SET value = '0' WHERE name = 'PS_FORCE_HTTPS'",
    "UPDATE 4667750Dimashop_url SET domain_ssl = domain WHERE 1=1"
];

foreach ($queries as $q) {
    if ($conn->query($q) === TRUE) {
        echo "✅ Executed: $q\n";
    } else {
        echo "⚠️ Error: " . $conn->error . "\n";
    }
}

// Check current settings
echo "\n=== Current Settings ===\n";
$result = $conn->query("SELECT name, value FROM 4667750Dimaconfiguration WHERE name LIKE '%SSL%' OR name LIKE '%HTTPS%'");

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        echo $row['name'] . " = " . $row['value'] . "\n";
    }
} else {
    echo "No SSL-related settings found (normal for fresh install)\n";
}

echo "\n=== Shop URLs ===\n";
$result = $conn->query("SELECT id_shop_url, domain, domain_ssl FROM 4667750Dimashop_url");
while($row = $result->fetch_assoc()) {
    echo "ID: " . $row['id_shop_url'] . " | Domain: " . $row['domain'] . " | SSL: " . $row['domain_ssl'] . "\n";
}

$conn->close();
echo "\n✅ Configuration fixed!\n";
?>
