<?php
require_once('/var/www/dev.geek-shelter.com/config/config.inc.php');
require_once('/var/www/dev.geek-shelter.com/init.php');

echo  === AccuPOS DB Credentials ===\n;
echo Host:  . Configuration::get('ACCUPOS_DB_HOST') .  \n;
echo Port:  . Configuration::get('ACCUPOS_DB_PORT') .  \n;
echo Database:  . Configuration::get('ACCUPOS_DB_NAME') .  \n;
echo User:  . Configuration::get('ACCUPOS_DB_USER') .  \n;
echo Pass:  . Configuration::get('ACCUPOS_DB_PASS') .  \n;
?>
