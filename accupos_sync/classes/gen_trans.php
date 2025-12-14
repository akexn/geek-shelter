<?php
define('_PS_ADMIN_DIR_', '/var/www/dev.geek-shelter.com/admin/');
require_once '/var/www/dev.geek-shelter.com/config/config.inc.php';
require_once '/var/www/dev.geek-shelter.com/init.php';

\System.Management.Automation.Internal.Host.InternalHost = Configuration::get('ACCUPOS_DB_HOST');
\ = Configuration::get('ACCUPOS_DB_PORT');
\ = Configuration::get('ACCUPOS_DB_NAME');
\ = base64_decode(Configuration::get('ACCUPOS_DB_USER'));
\ = base64_decode(Configuration::get('ACCUPOS_DB_PASS'));

echo 'Подключаемся к AccuPOS...';
\ = 'mysql:host='.\System.Management.Automation.Internal.Host.InternalHost.';port='.\.';dbname='.\.';charset=utf8mb4';
\ = new PDO(\, \, \, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

\ = \->query('SELECT COALESCE(MAX(Id), 10450) as max_id FROM apcshead')->fetch();
\ = \['max_id'];
\ = \ + 1;

echo ' OK\nДобавляем 70 заказов... ';

\ = ['dizengof', 'HAIFA'];
for (\ = 1; \ <= 70; \++) {
    \ = \[(\ - 1) % 2];
    \ = \ + \ - 1;
    \ = 'INSERT INTO apcshead (Id, DocDate, DocTime, Status, UserName, RefDocId, RefDocId2, Location, Notes, TransactionNumber, CurrencyIso, FiscalLineNumber) VALUES (:id, NOW(), CURTIME(), NULL, " system\, 0, 0, :location, :notes, :ref, \ILS\, :fline)';
 \ = \->prepare(\);
 \->execute([':id' => \, ':location' => \, ':notes' => 'Test '.\, ':ref' => 'TEST-'.\, ':fline' => \]);
}

echo ' OK\n';
\ = \->query('SELECT COUNT(*) as total FROM apcshead')->fetch();
echo 'Всего заказов: ' . \['total'] . '\n';
?>
