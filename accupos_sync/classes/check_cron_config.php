<?php
require_once '/var/www/dev.geek-shelter.com/config/config.inc.php';
require_once '/var/www/dev.geek-shelter.com/init.php';

echo " ACCUPOS_ENABLE_CRON: \ . Configuration::get('ACCUPOS_ENABLE_CRON') . \\\n\;
echo \ACCUPOS_CRON_BATCH: \ . Configuration::get('ACCUPOS_CRON_BATCH') . \\\n\;
