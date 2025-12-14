# ⚡ Быстрый старт диагностики AccuPOS Sync

## 🚀 Самый простой способ (через браузер)

1. **Запустите Laragon** (Start All)

2. **Откройте в браузере:**
   ```
   http://dev.geek-shelter.com/modules/accupos_sync/accupos_sync/diagnostic_launcher.html
   ```

3. **Выберите дату** и нажмите "Запустить диагностику"

**Готово!** Результаты отобразятся в браузере.

---

## 💻 Через командную строку (Laragon Terminal)

1. Откройте **Laragon Terminal**

2. Выполните:
   ```bash
   cd C:\laragon\www\dev.geek-shelter.com\modules\accupos_sync\accupos_sync
   php diagnostic_2025_12_03.php?date=2025-12-03
   ```

---

## 🖱️ Через BAT-файл (двойной клик)

1. **Двойной клик** на файл:
   ```
   run_diagnostic.bat
   ```

2. Следуйте инструкциям на экране

---

## 📅 Проверка разных дат

### Через браузер:
```
http://dev.geek-shelter.com/modules/accupos_sync/accupos_sync/diagnostic_2025_12_03.php?date=2025-12-01
```

### Через командную строку:
```bash
php diagnostic_2025_12_03.php?date=2025-12-01
```

### Через BAT-файл:
```bash
run_diagnostic.bat 2025-12-01
```

---

## ❓ Проблемы?

### "Access denied"
Добавьте токен в URL:
```
?date=2025-12-03&token=8a5edab282632443219e051e4ade2b1d
```

### "PHP not found"
Убедитесь, что Laragon запущен и PHP в PATH.

### "Database connection failed"
Проверьте настройки БД в `config/settings.inc.php`

---

## 📚 Подробная документация

- **Полная инструкция:** `LARAGON_GUIDE.md`
- **Описание диагностики:** `DIAGNOSTIC_README.md`
- **Документация модуля:** `../README.md`

---

**Время запуска:** ~30 секунд  
**Результат:** HTML отчет + JSON файл

