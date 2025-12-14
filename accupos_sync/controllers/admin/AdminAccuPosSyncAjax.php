<?php
/**
 * AccuPOS Sync - Асинхронный AJAX контроллер для синхронизации
 * Запускает Manual Sync в фоне без блокирования админки
 */

class AdminAccuPosSyncAjaxController extends ModuleAdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->bootstrap = true;
    }

    /**
     * Инициирует асинхронную синхронизацию
     * Сохраняет флаг в БД, затем CRON или background worker запустит её
     */
    public function ajaxProcessStartAsyncSync()
    {
        try {
            $module = Module::getInstanceByName('accupos_sync');
            
            if (!$module) {
                die(json_encode([
                    'success' => false,
                    'message' => 'Модуль не найден'
                ]));
            }

            // Сохраняем флаг для запуска синхронизации
            Configuration::updateValue('ACCUPOS_ASYNC_SYNC_PENDING', 1);
            Configuration::updateValue('ACCUPOS_ASYNC_SYNC_START_TIME', time());

            die(json_encode([
                'success' => true,
                'message' => 'Синхронизация запущена в фоне. Пожалуйста, подождите...',
                'sync_id' => uniqid('sync_')
            ]));

        } catch (Exception $e) {
            die(json_encode([
                'success' => false,
                'message' => 'Ошибка: ' . $e->getMessage()
            ]));
        }
    }

    /**
     * Проверяет статус асинхронной синхронизации
     */
    public function ajaxProcessGetAsyncSyncStatus()
    {
        try {
            $isPending = (bool)Configuration::get('ACCUPOS_ASYNC_SYNC_PENDING');
            $result = Configuration::get('ACCUPOS_ASYNC_SYNC_RESULT');
            $startTime = (int)Configuration::get('ACCUPOS_ASYNC_SYNC_START_TIME');
            
            if (!$isPending && $result) {
                $result = json_decode($result, true);
                Configuration::deleteByName('ACCUPOS_ASYNC_SYNC_RESULT');
                Configuration::deleteByName('ACCUPOS_ASYNC_SYNC_START_TIME');
                
                die(json_encode([
                    'success' => true,
                    'status' => 'completed',
                    'result' => $result,
                    'duration' => time() - $startTime
                ]));
            } elseif ($isPending) {
                $elapsed = time() - $startTime;
                die(json_encode([
                    'success' => true,
                    'status' => 'running',
                    'elapsed' => $elapsed,
                    'message' => 'Синхронизация выполняется... (' . $elapsed . ' сек)'
                ]));
            } else {
                die(json_encode([
                    'success' => true,
                    'status' => 'idle',
                    'message' => 'Не запущена'
                ]));
            }

        } catch (Exception $e) {
            die(json_encode([
                'success' => false,
                'message' => 'Ошибка: ' . $e->getMessage()
            ]));
        }
    }
}

