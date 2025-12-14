<?php
\ = '/var/www/dev.geek-shelter.com/vendor/doctrine/dbal/lib/Doctrine/DBAL/Driver/PDOConnection.php';
\ = file_get_contents(\);
\ = str_replace('public function query()', 'public function query(\ = null, \ = null, ...\)', \);
file_put_contents(\, \);
echo " Fixed PDOConnection.php\n\;
