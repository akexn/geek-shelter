# AccuPOS Sync Module

**Version:** 0.1.0  
**Author:** Aleksei Nekrasov (impserver.ru)  
**Organization:** ООО «Свобода»  
**PrestaShop Compatibility:** 1.7.8.11 - 9.x  
**PHP Requirements:** 7.4+ (recommended 8.1+)

## 📋 Описание

Модуль AccuPOS Sync обеспечивает автоматическую синхронизацию продаж из облачной системы AccuPOS с PrestaShop. Модуль подключается к внешней базе данных AccuPOS, получает транзакции продаж и автоматически списывает товары со складов PrestaShop.

### Основные возможности

- ✅ Подключение к облачной БД AccuPOS (`cloud.accupos.com`)
- ✅ Автоматическая синхронизация продаж (manual + CRON)
- ✅ Маппинг терминалов AccuPOS → склады PrestaShop
- ✅ Защита от дублирующихся транзакций
- ✅ Детальное логирование (БД + файлы)
- ✅ Ежедневные email отчёты об ошибках (CSV + HTML)
- ✅ Поддержка мультискладовой системы
- ✅ Интеграция с модулем `prestatillstockperstore`
- ✅ Шифрование учётных данных
- ✅ Совместимость с PrestaShop 1.7.8, 8.x, 9.x

## 🚀 Установка

### Шаг 1: Загрузка модуля

1. Скопируйте папку `accupos_sync` в директорию `/modules/` вашего PrestaShop
2. Перейдите в админ-панель PrestaShop → Модули → Менеджер модулей
3. Найдите модуль "AccuPOS Sync" и нажмите "Установить"

### Шаг 2: Настройка подключения к AccuPOS

После установки перейдите в настройки модуля и заполните:

**Подключение к AccuPOS:**
- **Host:** `cloud.accupos.com`
- **Port:** `3306`
- **Database:** `IL45121`
- **Username:** (ваш логин AccuPOS)
- **Password:** (ваш пароль AccuPOS)

> ⚠️ **Безопасность:** Все учётные данные хранятся в зашифрованном виде и никогда не выводятся в логи

Нажмите кнопку **"Тест подключения"** чтобы убедиться что соединение работает.

### Шаг 3: Настройка синхронизации

**Настройки синхронизации:**
- **Включить CRON:** Да (для автоматической синхронизации)
- **Время CRON:** `02:00` (время ночной синхронизации)
- **Склад по умолчанию:** Выберите склад для терминалов без явного маппинга
- **Окно синхронизации (дни):** `7` (сколько дней назад синхронизировать)

**Отчёты:**
- **Включить ежедневные отчёты:** Да
- **Email администратора:** ваш@email.com
- **Формат отчёта:** CSV + HTML email

Нажмите **"Сохранить"**.

### Шаг 4: Настройка маппинга терминалов

Для корректной работы необходимо настроить соответствие терминалов AccuPOS и складов PrestaShop.

Это можно сделать через SQL:

```sql
INSERT INTO ps_accupos_terminals (terminal_id, warehouse_id, store_name, active, date_add, date_upd)
VALUES
    ('TERMINAL_001', 1, 'Тель-Авив', 1, NOW(), NOW()),
    ('TERMINAL_002', 2, 'Хайфа', 1, NOW(), NOW()),
    ('TERMINAL_003', 3, 'Иерусалим', 1, NOW(), NOW());
```

Где:
- `terminal_id` - идентификатор терминала в AccuPOS
- `warehouse_id` - ID склада в PrestaShop (`ps_warehouse`)
- `store_name` - название магазина (для удобства)

### Шаг 5: Настройка CRON (опционально)

Для автоматической синхронизации рекомендуется использовать **dispatcher**:
- В `crontab` ставим **две строки**, которые запускаются каждую минуту
- Реальная периодичность и режим выбираются в админке модуля (CRON расписание)

```bash
# SSH на сервер
ssh root@your-server

# Редактирование crontab
crontab -e

# Добавить строки (PrestaShop root на DO: /var/www/html)
* * * * * /usr/bin/php7.4 /var/www/html/modules/accupos_sync/cron/dispatcher.php >> /var/www/html/var/logs/cron/accupos_cron.log 2>&1
* * * * * /usr/bin/php7.4 /var/www/html/modules/accupos_sync/cron/async_worker.php >> /var/www/html/var/logs/accupos_async_worker.log 2>&1
```

> Важно: `async_worker.php` нужен для “принудительной синхронизации” из админки (асинхронно).

## 📊 Использование

### Ручная синхронизация

1. Перейдите в настройки модуля
2. Нажмите кнопку **"Запустить синхронизацию"**
3. Дождитесь завершения и проверьте результат

### Автоматическая синхронизация (CRON)

Если настроен CRON, синхронизация будет выполняться автоматически:
- **Режим “Текущий день”**: каждые N минут (N задаётся в настройке интервала)
- **Режим “Предыдущий день (legacy)”**: 1 раз в сутки по времени `ACCUPOS_CRON_TIME` (HH:MM)

Проверить логи:

```bash
tail -f /var/www/html/var/logs/accupos/YYYY-MM-DD.log
tail -f /var/www/html/var/logs/cron/accupos_cron.log
tail -f /var/www/html/var/logs/accupos_async_worker.log
```

### Просмотр логов синхронизации

**Через БД:**

```sql
-- Последние синхронизации
SELECT * FROM ps_accupos_sync_log ORDER BY date_add DESC LIMIT 10;

-- Транзакции с ошибками
SELECT * FROM ps_accupos_transactions WHERE status = 'error' ORDER BY date_processed DESC LIMIT 50;

-- Unmapped SKU (товары не найдены)
SELECT sku, COUNT(*) as count 
FROM ps_accupos_transactions 
WHERE status = 'error' AND error_message LIKE '%not found%'
GROUP BY sku;
```

**Через файлы:**

```bash
# Логи за сегодня
cat /var/www/html/var/logs/accupos/$(date +%Y-%m-%d).log

# Последние ошибки
grep ERROR /var/www/html/var/logs/accupos/*.log | tail -20

# Unmapped SKU
grep UNMAPPED_SKU /var/www/html/var/logs/accupos/*.log
```

## 🔧 Архитектура

### Структура модуля

```
modules/accupos_sync/
├── accupos_sync.php              # Главный класс модуля
├── config.xml                    # Метаданные
├── README.md                     # Документация
├── classes/
│   ├── AccuPosDB.php            # PDO подключение к AccuPOS
│   ├── AccuPosSync.php          # Логика синхронизации
│   ├── AccuPosLogger.php        # Логирование и отчёты
│   └── AccuPosTerminalMapper.php # Маппинг терминалов→складов
└── cron/
    └── sync.php                  # CRON endpoint
```

### База данных

**Таблицы:**

1. `ps_accupos_terminals` - маппинг терминалов AccuPOS → склады PrestaShop
2. `ps_accupos_sync_log` - история синхронизаций
3. `ps_accupos_transactions` - все транзакции (защита от дублей)
4. `ps_accupos_config` - зашифрованные настройки (не используется, данные в `ps_configuration`)

### Алгоритм синхронизации

1. **Подключение к AccuPOS DB** через PDO
2. **Получение последнего обработанного ID транзакции** из `ps_accupos_sync_log`
3. **Выборка новых транзакций** с фильтром по дате и ID
4. **Для каждой транзакции:**
   - Проверка на дубликат (по `accupos_transaction_id`)
   - Маппинг терминала → склад через `AccuPosTerminalMapper`
   - Поиск товара по SKU (`product.reference`)
   - Списание через `StockAvailable::updateQuantity()`
   - Логирование результата
5. **Обновление sync_log** с результатами
6. **Отправка email отчётов** (если есть ошибки)

## 🔐 Безопасность

- ✅ Учётные данные AccuPOS шифруются через `Tools::encrypt()`
- ✅ Все SQL запросы используют `pSQL()` или PDO prepared statements
- ✅ Пароли никогда не выводятся в логи
- ✅ CRON endpoint защищён токеном
- ✅ Соединение с AccuPOS через защищённое MySQL соединение

## 📧 Отчёты

### Ежедневный email отчёт

Если включены отчёты и есть ошибки синхронизации, вы получите email с:

- ✅ Статистика (успешно/ошибки/пропущено)
- ❌ Список unmapped SKU (товары не найдены в PS)
- 🔧 Ошибки по терминалам
- 📎 CSV файл с деталями во вложении

### JSON отчёты

JSON отчёты сохраняются автоматически:

```bash
/var/logs/accupos/error_report_YYYY-MM-DD.json
```

Формат:

```json
{
  "date": "2025-10-23",
  "total_errors": 15,
  "unmapped_skus": ["SKU123", "SKU456"],
  "terminal_errors": {
    "TERM_001": 5,
    "TERM_002": 10
  },
  "errors": [...]
}
```

## 🛠 Устранение неполадок

### Проблема: "Не удалось подключиться к AccuPOS"

**Решение:**
1. Проверьте учётные данные в настройках модуля
2. Убедитесь что сервер имеет доступ к `cloud.accupos.com:3306`
3. Проверьте firewall правила:
   ```bash
   telnet cloud.accupos.com 3306
   ```
4. Проверьте логи PHP:
   ```bash
   tail -f /var/log/php7.4-fpm.log
   ```

### Проблема: "Product not found by SKU"

**Решение:**
1. Проверьте что товары в PrestaShop имеют заполненное поле `reference`
2. Убедитесь что `reference` в PS совпадает с `product_code` в AccuPOS
3. Проверьте unmapped SKU в отчётах:
   ```sql
   SELECT sku, COUNT(*) as count 
   FROM ps_accupos_transactions 
   WHERE status = 'error' 
   GROUP BY sku 
   ORDER BY count DESC;
   ```

### Проблема: "Terminal not mapped"

**Решение:**
1. Добавьте маппинг терминала:
   ```sql
   INSERT INTO ps_accupos_terminals (terminal_id, warehouse_id, store_name, active, date_add, date_upd)
   VALUES ('YOUR_TERMINAL_ID', 1, 'Store Name', 1, NOW(), NOW());
   ```
2. Проверьте список терминалов:
   ```sql
   SELECT * FROM ps_accupos_terminals;
   ```

### Проблема: CRON не запускается

**Решение:**
1. Проверьте crontab:
   ```bash
   crontab -l | grep accupos
   ```
2. Проверьте права на файл:
   ```bash
   chmod +x /var/www/dev.geek-shelter.com/modules/accupos_sync/cron/sync.php
   ```
3. Проверьте логи cron:
   ```bash
   tail -f /var/logs/accupos/cron.log
   ```
4. Запустите вручную для тестирования:
   ```bash
   php /var/www/dev.geek-shelter.com/modules/accupos_sync/cron/sync.php
   ```

## 📝 Обновление

### Обновление с версии 0.1.0 на будущие версии

1. Сделайте backup БД
2. Скопируйте новые файлы модуля
3. Перейдите в админ-панель → Модули
4. Нажмите "Обновить" на модуле AccuPOS Sync

## 🔄 Интеграция с prestatillstockperstore

Модуль совместим с `prestatillstockperstore` и использует его структуру складов.

При поиске склада для терминала модуль проверяет:
1. Таблицу `ps_accupos_terminals` (приоритет)
2. Таблицы `prestatillstockperstore` (fallback)
3. Склад по умолчанию из настроек

## 💻 Разработка и дебаг

### Включение debug режима

```php
// В accupos_sync.php добавьте:
define('ACCUPOS_DEBUG', true);
```

### Тестирование подключения

```php
require_once('config/config.inc.php');
require_once('modules/accupos_sync/classes/AccuPosDB.php');

$db = new AccuPosDB();
$result = $db->testConnection();
var_dump($result); // bool(true) если успешно
```

### Ручной запуск синхронизации

```bash
php -r "
require 'config/config.inc.php';
require 'modules/accupos_sync/accupos_sync.php';
\$module = new AccuPos_Sync();
\$result = \$module->runSync();
print_r(\$result);
"
```

## 📞 Поддержка

**Автор:** Aleksei Nekrasov  
**Email:** info@impserver.ru  
**Организация:** ООО «Свобода» / impserver.ru  
**Версия:** 0.1.0  
**Дата:** Октябрь 2025

## 📄 Лицензия

Proprietary - Закрытый исходный код  
© 2025 ООО «Свобода» / impserver.ru

---

**Статус:** ✅ Production Ready  
**Последнее обновление:** 23.10.2025

