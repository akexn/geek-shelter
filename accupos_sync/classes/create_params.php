<?php
$params = array(
    parameters => array(
        database_host => 127.0.0.1,
        database_port => ",
        database_name => prestashop_dev_ps8_test,
        database_user => impulse,
        database_password => 4667750Dima,
        database_prefix => 4667750Dima,
        database_engine => InnoDB,
        mailer_transport => smtp,
        mailer_host => 127.0.0.1,
        mailer_user => null,
        mailer_password => null,
        secret => ThisTokenIsNotSoSecret,
        locale => ru_RU,
        use_debug_toolbar => false,
        cookie_key => def000004e39d7b3ed5cf6d0a9e8c3ba0afc8e1b38a2f5d1c9e3b6a4d8f5c2e9a1b,
        cookie_iv => def000004e39d7b3ed5cf6d0a9e8c3ba,
        api_private_key => test_private_key,
        api_public_key => test_public_key,
        ps_dist_file_host => https://dist.prestashop.com,
    ),
);
file_put_contents(/var/www/dev-ps8-test.geek-shelter.com/app/config/parameters.php, <?php\nreturn  . var_export($params, true) . ;\n);
echo OK;
