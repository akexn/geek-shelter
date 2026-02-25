<?php
/**
 * AccuPOS Logger and Reporter
 * 
 * Система логирования синхронизации и генерации отчётов
 * 
 * @author    Aleksei Nekrasov <info@impserver.ru>
 * @copyright 2025 ООО «Свобода» / impserver.ru
 * @license   Proprietary
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Класс логирования AccuPOS
 */
class AccuPosLogger
{
    /**
     * @var string Путь к директории логов
     */
    private $logDir;

    /**
     * @var array Кэш unmapped SKU за текущую сессию
     */
    private $unmappedSkus = array();

    /**
     * Конструктор
     */
    public function __construct()
    {
        $this->logDir = _PS_ROOT_DIR_ . '/var/logs/accupos';
        
        // Создание директории если не существует
        if (!file_exists($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    /**
     * Логирование транзакции в БД
     * 
     * @param string $transactionId ID транзакции AccuPOS
     * @param string $terminalId ID терминала
     * @param int $warehouseId ID склада
     * @param string $sku Артикул товара
     * @param float $qty Количество
     * @param string $status Статус ('success', 'error', 'skipped')
     * @param string $message Сообщение
     * @param string $dateSale Дата продажи
     * @return bool Успешность логирования
     */
    public function logTransaction($transactionId, $terminalId, $warehouseId, $sku, $qty, $status, $message, $dateSale)
    {
        $data = array(
            'accupos_transaction_id' => pSQL($transactionId),
            'terminal_id' => pSQL($terminalId),
            'warehouse_id' => (int)$warehouseId,
            'sku' => pSQL($sku),
            'qty' => (float)$qty,
            'status' => pSQL($status),
            'error_message' => pSQL($message),
            'date_sale' => pSQL($dateSale),
            'date_processed' => date('Y-m-d H:i:s')
        );

        try {
            $result = Db::getInstance()->insert('accupos_transactions', $data);
            
            // ЭКСПЕРТНАЯ ОТЛАДКА: проверяем результат INSERT
            if (!$result) {
                $dbError = Db::getInstance()->getMsgError();
                $this->logToFile('ERROR', sprintf(
                    'INSERT FAILED: %s | TxnID: %s, SKU: %s, Status: %s, Message: %s',
                    $dbError,
                    $transactionId,
                    $sku,
                    $status,
                    $message
                ));
            } else {
                if ($status === 'success') {
                    $this->logToFile('INFO', sprintf(
                        'INSERT SUCCESS: TxnID=%s, SKU=%s, Qty=%s, Warehouse=%s',
                        $transactionId,
                        $sku,
                        $qty,
                        $warehouseId
                    ));
                }
            }
            
        } catch (Exception $e) {
            $this->logToFile('FATAL', sprintf(
                'INSERT EXCEPTION: %s | TxnID: %s, SKU: %s',
                $e->getMessage(),
                $transactionId,
                $sku
            ));
            $result = false;
        }

        // Дополнительное логирование в файл для ошибок
        if ($status === 'error') {
            $this->logToFile('ERROR', sprintf(
                'Transaction: %s, Terminal: %s, SKU: %s, Message: %s',
                $transactionId,
                $terminalId,
                $sku,
                $message
            ));
        }

        return $result;
    }

    /**
     * Логирование unmapped SKU
     * 
     * @param string $sku Артикул товара
     * @param string $transactionId ID транзакции
     * @param string $terminalId ID терминала
     */
    public function logUnmappedSku($sku, $transactionId, $terminalId)
    {
        if (!isset($this->unmappedSkus[$sku])) {
            $this->unmappedSkus[$sku] = array(
                'count' => 0,
                'transactions' => array()
            );
        }

        $this->unmappedSkus[$sku]['count']++;
        $this->unmappedSkus[$sku]['transactions'][] = array(
            'transaction_id' => $transactionId,
            'terminal_id' => $terminalId,
            'timestamp' => date('Y-m-d H:i:s')
        );

        // Логирование в файл
        $this->logToFile('UNMAPPED_SKU', sprintf(
            'SKU: %s, Transaction: %s, Terminal: %s',
            $sku,
            $transactionId,
            $terminalId
        ));
    }

    /**
     * Логирование в файл
     * 
     * @param string $level Уровень ('INFO', 'ERROR', 'WARNING', 'UNMAPPED_SKU')
     * @param string $message Сообщение
     */
    public function logToFile($level, $message)
    {
        $logFile = $this->logDir . '/' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $logLine = sprintf("[%s] [%s] %s\n", $timestamp, $level, $message);

        file_put_contents($logFile, $logLine, FILE_APPEND);
    }

    /**
     * Генерация ежедневного отчёта
     * 
     * @param string|null $date Дата отчёта (по умолчанию сегодня)
     * @return array Данные отчёта
     */
    public function generateDailyReport($date = null)
    {
        if ($date === null) {
            $date = date('Y-m-d');
        }

        // Получение транзакций за день с ошибками
        $errors = Db::getInstance()->executeS('
            SELECT *
            FROM ' . _DB_PREFIX_ . 'accupos_transactions
            WHERE status = "error"
                AND DATE(date_processed) = "' . pSQL($date) . '"
            ORDER BY date_processed DESC
        ');

        // Подсчёт статистики
        $stats = Db::getInstance()->getRow('
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) as success_count,
                SUM(CASE WHEN status = "error" THEN 1 ELSE 0 END) as error_count,
                SUM(CASE WHEN status = "skipped" THEN 1 ELSE 0 END) as skipped_count
            FROM ' . _DB_PREFIX_ . 'accupos_transactions
            WHERE DATE(date_processed) = "' . pSQL($date) . '"
        ');

        // Группировка ошибок по SKU
        $unmappedSkus = array();
        $terminalErrors = array();

        foreach ($errors as $error) {
            // Unmapped SKU
            if (strpos($error['error_message'], 'not found') !== false) {
                if (!isset($unmappedSkus[$error['sku']])) {
                    $unmappedSkus[$error['sku']] = 0;
                }
                $unmappedSkus[$error['sku']]++;
            }

            // Ошибки по терминалам
            if (!isset($terminalErrors[$error['terminal_id']])) {
                $terminalErrors[$error['terminal_id']] = 0;
            }
            $terminalErrors[$error['terminal_id']]++;
        }

        $report = array(
            'date' => $date,
            'stats' => $stats,
            'total_errors' => count($errors),
            'unmapped_skus' => $unmappedSkus,
            'terminal_errors' => $terminalErrors,
            'errors' => $errors
        );

        return $report;
    }

    /**
     * Сохранение отчёта в JSON
     * 
     * @param array $report Данные отчёта
     * @return string Путь к файлу
     */
    public function saveReportAsJson($report)
    {
        $filename = sprintf('error_report_%s.json', $report['date']);
        $filepath = $this->logDir . '/' . $filename;

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($filepath, $json);

        return $filepath;
    }

    /**
     * Сохранение отчёта в CSV
     * 
     * @param array $report Данные отчёта
     * @return string Путь к файлу
     */
    public function saveReportAsCsv($report)
    {
        $filename = sprintf('errors_%s.csv', $report['date']);
        $filepath = $this->logDir . '/' . $filename;

        $fp = fopen($filepath, 'w');
        
        // Заголовки
        fputcsv($fp, array('Transaction ID', 'Terminal', 'SKU', 'Qty', 'Error', 'Timestamp'));

        // Данные
        foreach ($report['errors'] as $error) {
            fputcsv($fp, array(
                $error['accupos_transaction_id'],
                $error['terminal_id'],
                $error['sku'],
                $error['qty'],
                $error['error_message'],
                $error['date_processed']
            ));
        }

        fclose($fp);

        return $filepath;
    }

    /**
     * Генерация HTML содержимого email
     * 
     * @param array $report Данные отчёта
     * @return string HTML содержимое
     */
    private function generateEmailHtml($report)
    {
        $html = '<html><body style="font-family: Arial, sans-serif;">';
        $html .= '<h2>AccuPOS Sync - Ежедневный отчёт об ошибках</h2>';
        $html .= '<p><strong>Дата:</strong> ' . date('d.m.Y', strtotime($report['date'])) . '</p>';
        $html .= '<hr>';

        // Статистика
        $html .= '<h3>Статистика синхронизации:</h3>';
        $html .= '<ul>';
        $html .= '<li>✅ <strong>Успешно:</strong> ' . $report['stats']['success_count'] . ' транзакций</li>';
        $html .= '<li>❌ <strong>Ошибки:</strong> ' . $report['stats']['error_count'] . ' транзакций</li>';
        $html .= '<li>⏩ <strong>Пропущено:</strong> ' . $report['stats']['skipped_count'] . ' транзакций</li>';
        $html .= '</ul>';

        // Unmapped SKU
        if (!empty($report['unmapped_skus'])) {
            $html .= '<h3>Unmapped SKU (товары отсутствуют в PrestaShop):</h3>';
            $html .= '<ul>';
            foreach ($report['unmapped_skus'] as $sku => $count) {
                $html .= '<li><strong>' . htmlspecialchars($sku) . '</strong> (' . $count . ' транзакций)</li>';
            }
            $html .= '</ul>';
        }

        // Ошибки по терминалам
        if (!empty($report['terminal_errors'])) {
            $html .= '<h3>Терминалы с ошибками:</h3>';
            $html .= '<ul>';
            foreach ($report['terminal_errors'] as $terminal => $count) {
                $html .= '<li><strong>' . htmlspecialchars($terminal) . '</strong>: ' . $count . ' ошибок</li>';
            }
            $html .= '</ul>';
        }

        $html .= '<hr>';
        $html .= '<p><em>CSV файл с детальной информацией во вложении.</em></p>';
        $html .= '<p><small>Документация: /modules/accupos_sync/README.md</small></p>';
        $html .= '</body></html>';

        return $html;
    }

    /**
     * Отправка ежедневного отчёта по email
     * 
     * @param string|null $date Дата отчёта
     * @return bool Успешность отправки
     */
    public function sendDailyReport($date = null)
    {
        if (!Configuration::get('ACCUPOS_ENABLE_REPORTS')) {
            return false;
        }

        // Генерация отчёта
        $report = $this->generateDailyReport($date);

        // Если нет ошибок - не отправляем отчёт (это именно "отчёт об ошибках")
        if ($report['total_errors'] == 0) {
            $this->logToFile('INFO', 'Daily report not sent: no errors for date ' . $report['date']);
            return true;
        }

        $reportFormat = Configuration::get('ACCUPOS_REPORT_FORMAT');
        $adminEmail = Configuration::get('ACCUPOS_ADMIN_EMAIL');

        if (empty($adminEmail)) {
            PrestaShopLogger::addLog(
                'AccuPOS: Cannot send report - admin email not configured',
                3,
                null,
                'AccuPosLogger'
            );
            return false;
        }

        // Сохранение отчётов в файлы
        $jsonFile = $this->saveReportAsJson($report);
        $csvFile = null;
        
        if ($reportFormat === 'csv_html') {
            $csvFile = $this->saveReportAsCsv($report);
        }

        // Формирование email (переводим тему под язык по умолчанию PrestaShop)
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $subject = sprintf(
            $this->t('AccuPOS Sync - Ежедневный отчёт об ошибках (%s)', $idLang),
            date('d.m.Y', strtotime($report['date']))
        );
        $htmlContent = $this->generateEmailHtml($report);

        // Отправка email через PrestaShop Mail API
        try {
            $fileAttachment = null;
            if ($csvFile && file_exists($csvFile)) {
                // PrestaShop ожидает массив вложений, а не путь к файлу
                $fileAttachment = array(
                    'content' => file_get_contents($csvFile),
                    'name' => basename($csvFile),
                    'mime' => 'text/csv'
                );
            }

            // PrestaShop 1.7+ использует Mail::Send()
            $sent = Mail::send(
                (int)Configuration::get('PS_LANG_DEFAULT'),
                'accupos_report', // Шаблон (или null для прямого HTML)
                $subject,
                array('{message}' => $htmlContent),
                $adminEmail,
                null,
                null,
                null,
                $fileAttachment, // attachment
                null,
                _PS_MODULE_DIR_ . 'accupos_sync/mails/',
                false,
                null
            );

            if ($sent) {
                PrestaShopLogger::addLog(
                    'AccuPOS: Daily report sent to ' . $adminEmail,
                    1,
                    null,
                    'AccuPosLogger'
                );
                return true;
            } else {
                PrestaShopLogger::addLog(
                    'AccuPOS: Failed to send daily report',
                    3,
                    null,
                    'AccuPosLogger'
                );
                $this->logToFile('ERROR', 'Mail::send returned false for daily report to ' . $adminEmail);
                return false;
            }

        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'AccuPOS: Error sending report - ' . $e->getMessage(),
                4,
                null,
                'AccuPosLogger'
            );
            $this->logToFile('FATAL', 'Mail::send exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Тестовая отправка email (без условий по ошибкам)
     *
     * @return bool
     */
    public function sendTestEmail()
    {
        if (!Configuration::get('ACCUPOS_ENABLE_REPORTS')) {
            return false;
        }

        $adminEmail = (string)Configuration::get('ACCUPOS_ADMIN_EMAIL');
        if (empty($adminEmail) || !Validate::isEmail($adminEmail)) {
            $this->logToFile('ERROR', 'Test email not sent: ACCUPOS_ADMIN_EMAIL is empty/invalid');
            return false;
        }

        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $subject = $this->t('AccuPOS Sync - Тестовое письмо', $idLang);
        $htmlContent = '<p>' . $this->t('Это тестовое письмо модуля <b>AccuPOS Sync</b>.', $idLang) . '</p>'
            . '<p>' . $this->t('Если вы получили это письмо — отправка email из PrestaShop работает.', $idLang) . '</p>'
            . '<p><small>' . $this->t('Время:', $idLang) . ' ' . date('Y-m-d H:i:s') . '</small></p>';

        try {
            $sent = Mail::send(
                (int)Configuration::get('PS_LANG_DEFAULT'),
                'accupos_report',
                $subject,
                array('{message}' => $htmlContent),
                $adminEmail,
                null,
                null,
                null,
                null,
                null,
                _PS_MODULE_DIR_ . 'accupos_sync/mails/',
                false,
                null
            );

            if ($sent) {
                $this->logToFile('INFO', 'Test email sent to ' . $adminEmail);
                return true;
            }

            $this->logToFile('ERROR', 'Test email failed: Mail::send returned false');
            return false;
        } catch (Exception $e) {
            $this->logToFile('FATAL', 'Test email exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Очистка старых логов
     * 
     * @param int $daysToKeep Количество дней для хранения
     * @return int Количество удалённых файлов
     */
    public function cleanOldLogs($daysToKeep = 30)
    {
        $deleted = 0;
        $cutoffDate = strtotime('-' . $daysToKeep . ' days');

        $files = glob($this->logDir . '/*.{log,json,csv}', GLOB_BRACE);

        foreach ($files as $file) {
            if (filemtime($file) < $cutoffDate) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }

        // Очистка старых записей из БД
        Db::getInstance()->execute('
            DELETE FROM ' . _DB_PREFIX_ . 'accupos_transactions
            WHERE date_processed < DATE_SUB(NOW(), INTERVAL ' . (int)$daysToKeep . ' DAY)
        ');

        Db::getInstance()->execute('
            DELETE FROM ' . _DB_PREFIX_ . 'accupos_sync_log
            WHERE date_add < DATE_SUB(NOW(), INTERVAL ' . (int)$daysToKeep . ' DAY)
        ');

        return $deleted;
    }

    /**
     * Перевод строк для классов модуля (когда $this->l() недоступен).
     *
     * @param string $string
     * @param int $idLang
     * @return string
     */
    private function t($string, $idLang)
    {
        try {
            $module = Module::getInstanceByName('accupos_sync');
            if ($module instanceof Module) {
                return $module->l($string, 'accuposlogger', (int) $idLang);
            }
        } catch (Exception $e) {
            // Игнорируем: fallback на исходную строку
        }

        return (string) $string;
    }
}

