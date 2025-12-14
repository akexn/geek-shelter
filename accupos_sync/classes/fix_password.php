<?php
/**
 * Fix Admin Password on Server
 */

$password = 'TestPass123456';
$newHash = '$2y$12$9wIOm.F7AJUCE93e42fUiu7lcEO9xrAMSGPYrzdi1OIBvEvx.YkoC';
$email = 'notification@geek-shelter.com';

// Connect
$conn = new mysqli('localhost', 'root', '', 'prestashop_dev_ps8_test');

if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

// Update password
$conn->query("UPDATE 4667750Dimaemployee SET passwd = '$newHash' WHERE email = '$email'");

echo "✅ Password updated!\n";

// Verify
$result = $conn->query("SELECT passwd FROM 4667750Dimaemployee WHERE email = '$email'");
$row = $result->fetch_assoc();

if (password_verify($password, $row['passwd'])) {
    echo "✅ Password verification: SUCCESS!\n";
    echo "Email: $email\n";
    echo "Password: $password\n";
} else {
    echo "❌ Verification failed\n";
}

$conn->close();
?>
