## Установка модуля AccuPOS Sync (dev/prod)

### Что копировать

Для установки/обновления **копируйте только одну папку**:

- `modules/accupos_sync/`

### Что гарантирует модуль при установке

При `Install` модуль:

- создаёт сотрудника **AccuPOS Sync** и сохраняет его ID в `ACCUPOS_EMPLOYEE_ID`
- создаёт причину движения **ID=13 "AccuPOS Sync"** (с переводами) и сохраняет в `ACCUPOS_REASON_ID`

### Что происходит при переустановке

- `Uninstall` удаляет параметры подключения к AccuPOS и CRON-настройки из `ps_configuration`
- `ACCUPOS_EMPLOYEE_ID` и `ACCUPOS_REASON_ID` **не удаляются**

### Быстрая проверка “пакета модуля” на Windows

Запуск из корня репозитория:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File ops/check_accupos_module_package.ps1
```


