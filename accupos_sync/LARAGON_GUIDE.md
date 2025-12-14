# 🚀 Запуск диагностики AccuPOS Sync через Laragon

## Быстрый старт

### Вариант 1: Через веб-интерфейс (рекомендуется)

1. **Запустите Laragon** (если еще не запущен)
   - Откройте Laragon
   - Нажмите "Start All" (Apache/Nginx + MySQL)

2. **Откройте в браузере:**
   ```
   http://dev.geek-shelter.com/modules/accupos_sync/accupos_sync/diagnostic_launcher.html
   ```
   
   Или если используете автоматический домен Laragon:
   ```
   http://dev.geek-shelter.test/modules/accupos_sync/accupos_sync/diagnostic_launcher.html
   ```

3. **Выберите дату** и нажмите "Запустить диагностику"

### Вариант 2: Прямой доступ к скрипту

Откройте в браузере:
```
http://dev.geek-shelter.com/modules/accupos_sync/accupos_sync/diagnostic_2025_12_03.php?date=2025-12-03
```

### Вариант 3: Через командную строку Laragon

1. Откройте **Laragon Terminal** (правой кнопкой на иконке Laragon → Terminal)

2. Перейдите в директорию проекта:
   ```bash
   cd C:\laragon\www\dev.geek-shelter.com\modules\accupos_sync\accupos_sync
   ```

3. Запустите диагностику:
   ```bash
   php diagnostic_2025_12_03.php > diagnostic_output.html
   ```

4. Откройте результат:
   ```bash
   start diagnostic_output.html
   ```

## Настройка Laragon для проекта

### 1. Проверка домена

Laragon автоматически создает домены для папок в `www/`. Проверьте:

1. Откройте Laragon → **Menu** → **Tools** → **Quick add domain**
2. Или вручную добавьте в `C:\Windows\System32\drivers\etc\hosts`:
   ```
   127.0.0.1    dev.geek-shelter.com
   ```

### 2. Настройка виртуального хоста (Apache)

Если используете Apache, создайте файл:
`C:\laragon\bin\apache\apache-2.x.x\conf\extra\httpd-vhosts.conf`

Добавьте:
```apache
<VirtualHost *:80>
    ServerName dev.geek-shelter.com
    DocumentRoot "C:/laragon/www/dev.geek-shelter.com"
    <Directory "C:/laragon/www/dev.geek-shelter.com">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 3. Настройка виртуального хоста (Nginx)

Если используете Nginx, создайте файл:
`C:\laragon\bin\nginx\nginx-1.x.x\conf\vhosts\dev.geek-shelter.com.conf`

Добавьте:
```nginx
server {
    listen 80;
    server_name dev.geek-shelter.com;
    root C:/laragon/www/dev.geek-shelter.com;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

После изменений перезапустите веб-сервер в Laragon.

## Проверка работы

### 1. Проверка PHP

В Laragon Terminal выполните:
```bash
php -v
```

Должна быть версия PHP 7.4 или выше.

### 2. Проверка MySQL

В Laragon Terminal выполните:
```bash
mysql -u root -p
```

Пароль по умолчанию обычно пустой (просто нажмите Enter).

### 3. Проверка доступа к PrestaShop

Откройте в браузере:
```
http://dev.geek-shelter.com
```

Должен открыться сайт PrestaShop.

## Решение проблем

### Проблема: "Access denied" при запуске диагностики

**Решение:** Добавьте токен в URL:
```
http://dev.geek-shelter.com/modules/accupos_sync/accupos_sync/diagnostic_2025_12_03.php?date=2025-12-03&token=8a5edab282632443219e051e4ade2b1d
```

Или откройте скрипт и временно закомментируйте проверку доступа (строки 20-40).

### Проблема: "Class not found" или ошибки подключения

**Решение:** 
1. Убедитесь, что Laragon запущен (Start All)
2. Проверьте, что путь к проекту правильный
3. Проверьте файл `config/config.inc.php` - он должен существовать

### Проблема: "Database connection failed"

**Решение:**
1. Проверьте, что MySQL запущен в Laragon
2. Проверьте настройки БД в `config/settings.inc.php`:
   ```php
   define('_DB_SERVER_', '127.0.0.1');
   define('_DB_NAME_', 'prestashop_dev');
   define('_DB_USER_', 'root');
   define('_DB_PASSWD_', ''); // Обычно пустой в Laragon
   ```

### Проблема: Домен не открывается

**Решение:**
1. Проверьте, что Apache/Nginx запущен
2. Проверьте файл hosts: `C:\Windows\System32\drivers\etc\hosts`
3. Очистите DNS кэш:
   ```bash
   ipconfig /flushdns
   ```

## Полезные команды Laragon

### Быстрый доступ к проекту
- **Правой кнопкой на Laragon** → **www** → выберите папку проекта

### Открыть терминал
- **Правой кнопкой на Laragon** → **Terminal**

### Открыть базу данных
- **Правой кнопкой на Laragon** → **Database** → **phpMyAdmin**

### Просмотр логов
- **Правой кнопкой на Laragon** → **Logs**

## Структура файлов диагностики

```
modules/accupos_sync/accupos_sync/
├── diagnostic_2025_12_03.php      # Основной скрипт диагностики
├── diagnostic_launcher.html         # Веб-интерфейс для запуска
├── DIAGNOSTIC_README.md            # Полная документация
├── LARAGON_GUIDE.md               # Этот файл
└── diagnostic_results_*.json       # Результаты (создаются автоматически)
```

## Примеры использования

### Проверка конкретной даты
```
http://dev.geek-shelter.com/modules/accupos_sync/accupos_sync/diagnostic_2025_12_03.php?date=2025-12-01
```

### Проверка сегодняшнего дня
```
http://dev.geek-shelter.com/modules/accupos_sync/accupos_sync/diagnostic_2025_12_03.php?date=<?php echo date('Y-m-d'); ?>
```

### Сохранение результатов в файл
В Laragon Terminal:
```bash
cd C:\laragon\www\dev.geek-shelter.com\modules\accupos_sync\accupos_sync
php diagnostic_2025_12_03.php > diagnostic_<?php echo date('Y-m-d'); ?>.html
```

## Дополнительная информация

- **Документация модуля:** `modules/accupos_sync/README.md`
- **Инструкция по диагностике:** `modules/accupos_sync/accupos_sync/DIAGNOSTIC_README.md`
- **Версия Laragon:** Проверьте в Laragon → About

---

**Создано:** 2025-12-03  
**Автор:** Aleksei Nekrasov (info@impserver.ru)

