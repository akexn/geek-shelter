<?php
/**
 * Исправление структуры таблицы category_lang для PS8
 * Добавление отсутствующей колонки additional_description
 */

$host = '127.0.0.1';
$user = 'impulse';
$password = '4667750Dima';
$prefix = '4667750Dima';
$dbTest = 'prestashop_dev_ps8_test';

try {
    $conn = new mysqli($host, $user, $password, $dbTest);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    echo "=== ИСПРАВЛЕНИЕ СТРУКТУРЫ CATEGORY_LANG ===\n\n";
    
    // Проверяем существование колонки
    $result = $conn->query("SHOW COLUMNS FROM {$prefix}category_lang LIKE 'additional_description'");
    
    if ($result->num_rows == 0) {
        echo "Колонка 'additional_description' отсутствует. Добавляем...\n";
        
        $sql = "ALTER TABLE {$prefix}category_lang 
                ADD COLUMN additional_description TEXT NULL AFTER description";
        
        if ($conn->query($sql)) {
            echo "✓ Колонка 'additional_description' добавлена\n";
        } else {
            echo "✗ Ошибка: " . $conn->error . "\n";
        }
    } else {
        echo "✓ Колонка 'additional_description' уже существует\n";
    }
    
    // Проверяем другие возможные отсутствующие колонки PS8
    echo "\n=== Проверка других колонок ===\n";
    
    $columns_to_check = [
        'meta_title',
        'meta_description', 
        'meta_keywords',
        'link_rewrite'
    ];
    
    foreach ($columns_to_check as $col) {
        $result = $conn->query("SHOW COLUMNS FROM {$prefix}category_lang LIKE '$col'");
        if ($result->num_rows > 0) {
            echo "✓ $col - существует\n";
        } else {
            echo "⚠️ $col - отсутствует\n";
        }
    }
    
    // Очищаем кэш
    echo "\n=== Очистка кэша ===\n";
    exec("rm -rf /var/www/dev-ps8-test.geek-shelter.com/var/cache/prod/* 2>&1");
    echo "✓ Кэш очищен\n\n";
    
    $conn->close();
    echo "✅ Готово! Попробуйте открыть товар снова.\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}

