<?php
/**
 * PrestaShop 8 Login Test Script
 */

// Database config - use Unix socket
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'prestashop_dev_ps8_test';

// Login credentials to test
$testEmail = 'notification@geek-shelter.com';
$testPassword = 'TestPass123456';

try {
    $conn = new mysqli($host, $user, $password, $database);
    
    if ($conn->connect_error) {
        die('Connection failed: ' . $conn->connect_error);
    }
    
    echo "=== PrestaShop 8 Login Test ===\n\n";
    
    // Get admin from DB
    $result = $conn->query("SELECT id_employee, email, firstname, lastname, passwd FROM 4667750Dimaemployee WHERE email = '$testEmail'");
    
    if ($result->num_rows == 0) {
        echo "❌ Admin not found in database!\n";
        exit;
    }
    
    $admin = $result->fetch_assoc();
    echo "✅ Admin found:\n";
    echo "   Email: " . $admin['email'] . "\n";
    echo "   Name: " . $admin['firstname'] . " " . $admin['lastname'] . "\n";
    echo "   Password hash length: " . strlen($admin['passwd']) . " chars\n";
    echo "   Hash starts with: " . substr($admin['passwd'], 0, 20) . "...\n";
    echo "\n";
    
    // Test password
    echo "Testing password: '$testPassword'\n";
    
    if (password_verify($testPassword, $admin['passwd'])) {
        echo "✅ PASSWORD IS CORRECT!\n";
        echo "\n✅✅✅ You can now login to admin panel!\n";
    } else {
        echo "❌ Password is INCORRECT!\n";
        echo "\nTrying to set new hash...\n";
        
        $newHash = password_hash($testPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        echo "New hash: $newHash\n";
        echo "\nRun this on server to fix:\n";
        echo "mysql -u root prestashop_dev_ps8_test -e \"UPDATE 4667750Dimaemployee SET passwd = '$newHash' WHERE email = '$testEmail';\"";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
