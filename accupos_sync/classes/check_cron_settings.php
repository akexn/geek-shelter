<?php
require_once('/var/www/dev.geek-shelter.com/config/config.inc.php');

echo "=== НАСТРОЙКИ CRON ===\n\n";
echo "CRON enabled: " . Configuration::get('ACCUPOS_ENABLE_CRON') . "\n";
echo "Email: " . Configuration::get('ACCUPOS_NOTIFICATION_EMAIL') . "\n";
echo "Sync window (days): " . Configuration::get('ACCUPOS_SYNC_WINDOW_DAYS') . "\n";

if (Configuration::get('ACCUPOS_ENABLE_CRON') == '1') {
    echo "\n✅ CRON включен в настройках модуля\n";
} else {
    echo "\n❌ CRON выключен в настройках модуля\n";
}

