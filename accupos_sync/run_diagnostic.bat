@echo off
chcp 65001 >nul
title AccuPOS Sync - Диагностика

echo.
echo ========================================
echo   AccuPOS Sync - Диагностика модуля
echo ========================================
echo.

REM Определяем путь к проекту
set PROJECT_DIR=%~dp0
set PROJECT_DIR=%PROJECT_DIR:~0,-1%

REM Определяем дату (по умолчанию сегодня)
set CHECK_DATE=%1
if "%CHECK_DATE%"=="" (
    for /f "tokens=2 delims==" %%a in ('wmic os get localdatetime /value') do set datetime=%%a
    set CHECK_DATE=%datetime:~0,4%-%datetime:~4,2%-%datetime:~6,2%
)

echo Проверка даты: %CHECK_DATE%
echo Путь к проекту: %PROJECT_DIR%
echo.

REM Проверяем наличие PHP
where php >nul 2>&1
if %errorlevel% neq 0 (
    echo [ОШИБКА] PHP не найден в PATH!
    echo.
    echo Убедитесь, что:
    echo 1. Laragon запущен
    echo 2. PHP добавлен в PATH системы
    echo.
    echo Или используйте полный путь к PHP:
    echo C:\laragon\bin\php\php-7.4.x\php.exe
    pause
    exit /b 1
)

echo [OK] PHP найден
echo.

REM Переходим в директорию скрипта
cd /d "%PROJECT_DIR%"

REM Запускаем диагностику
echo Запуск диагностики...
echo.

REM Устанавливаем переменную окружения для даты
set QUERY_STRING=date=%CHECK_DATE%
php diagnostic_2025_12_03.php > diagnostic_output_%CHECK_DATE%.html 2>&1

if %errorlevel% equ 0 (
    echo.
    echo [УСПЕХ] Диагностика завершена!
    echo.
    echo Результаты сохранены в: diagnostic_output_%CHECK_DATE%.html
    echo.
    
    REM Открываем результат в браузере
    set /p OPEN="Открыть результат в браузере? (Y/N): "
    if /i "%OPEN%"=="Y" (
        start diagnostic_output_%CHECK_DATE%.html
    )
) else (
    echo.
    echo [ОШИБКА] При выполнении диагностики произошла ошибка!
    echo.
    echo Проверьте файл diagnostic_output_%CHECK_DATE%.html для деталей.
    pause
    exit /b 1
)

echo.
pause

