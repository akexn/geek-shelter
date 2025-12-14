<?php
require_once '/var/www/dev.geek-shelter.com/config/config.inc.php';
require_once '/var/www/dev.geek-shelter.com/init.php';

System.Management.Automation.Internal.Host.InternalHost = Configuration::get('ACCUPOS_DB_HOST');
 = Configuration::get('ACCUPOS_DB_PORT');
 = Configuration::get('ACCUPOS_DB_NAME');
 = base64_decode(Configuration::get('ACCUPOS_DB_USER'));
 = base64_decode(Configuration::get('ACCUPOS_DB_PASS'));

echo 'Host: ' . System.Management.Automation.Internal.Host.InternalHost . PHP_EOL;
echo 'Port: ' .  . PHP_EOL;
echo 'DB: ' .  . PHP_EOL;
echo 'User: ' .  . PHP_EOL;
echo 'Pass: ' . (strlen() > 0 ? '***' : 'NOT SET') . PHP_EOL;
