<?php
if (php_sapi_name() === 'cli') {
    ['REQUEST_METHOD'] = 'GET';
    ['HTTP_HOST'] = 'cli';
    ['REQUEST_URI'] = '/cron/sync.php';
    ['SERVER_NAME'] = 'localhost';
    ['SCRIPT_FILENAME'] = __FILE__;
    ['SCRIPT_NAME'] = '/cron/sync.php';
}

require_once dirname(__FILE__) . '/../../../config/config.inc.php';
require_once dirname(__FILE__) . '/../../../init.php';

require_once dirname(__FILE__) . '/../accupos_sync.php';

if (php_sapi_name() !== 'cli') {
     = Tools::getValue('token');
     = md5(_COOKIE_KEY_ . 'accupos_sync_cron');
    
    if ( !== ) {
        header('HTTP/1.1 403 Forbidden');
        die('Access denied');
    }
}

if (!Configuration::get('ACCUPOS_ENABLE_CRON')) {
    echo " CRON is disabled\n\;
 exit(0);
}

 = microtime(true);
 = date('Y-m-d H:i:s');

echo \Starting AccuPOS CRON at -encodedCommand XAAkAHQAaQBtAGUAcwB0AGEAbQBwAA== \n\;

try {
 = new AccuPos_Sync();
 = ->runSync();
 
 echo \CRON Result: \ . (['success'] ? 'SUCCESS' : 'FAILED') . \\n\;
 echo \Processed: \ . ['processed'] . \\n\;
 echo \Success: \ . ['success_count'] . \\n\;
 echo \Errors: \ . ['error_count'] . \\n\;
 echo \Duration: \ . number_format(microtime(true) - , 2) . " sec\n\;
    
    exit(['success'] ? 0 : 1);
    
} catch (Exception ) {
    echo \ERROR: \ . ->getMessage() . \\n\;
    PrestaShopLogger::addLog('AccuPOS CRON Error: ' . ->getMessage(), 4, null, 'AccuPosCron');
    exit(1);
}
