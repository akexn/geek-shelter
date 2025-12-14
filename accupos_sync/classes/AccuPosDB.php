<?php
/**
 * AccuPOS Database Connection Handler
 * 
 * PDO wrapper для безопасного подключения к внешней БД AccuPOS
 * 
 * @author    Aleksei Nekrasov <info@impserver.ru>
 * @copyright 2025 ООО «Свобода» / impserver.ru
 * @license   Proprietary
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Класс для работы с БД AccuPOS через PDO
 */
class AccuPosDB
{
    /**
     * @var PDO|null PDO соединение
     */
    private $connection = null;

    /**
     * @var int Максимальное количество попыток подключения
     */
    private $maxRetries = 3;

    /**
     * @var int Timeout подключения в секундах
     */
    private $timeout = 10;

    /**
     * Конструктор
     */
    public function __construct()
    {
        // Конструктор пустой, подключение выполняется через connect()
    }

    /**
     * Статический метод для получения PDO соединения
     * 
     * @return PDO PDO объект
     * @throws Exception При ошибке подключения
     */
    public static function connect()
    {
        $instance = new self();
        return $instance->createConnection();
    }

    /**
     * Установка соединения с БД AccuPOS
     * 
     * @return PDO|false PDO объект или false при ошибке
     * @throws Exception При ошибке подключения
     */
    private function createConnection()
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        // Получение учётных данных из конфигурации
        $host = Configuration::get('ACCUPOS_DB_HOST');
        $port = Configuration::get('ACCUPOS_DB_PORT');
        $dbname = Configuration::get('ACCUPOS_DB_NAME');
        
        $encryptedUser = Configuration::get('ACCUPOS_DB_USER');
        $encryptedPass = Configuration::get('ACCUPOS_DB_PASS');

        // Проверка наличия настроек
        if (empty($host) || empty($dbname) || empty($encryptedUser) || empty($encryptedPass)) {
            throw new Exception('AccuPOS DB credentials not configured');
        }

        // Расшифровка учётных данных
        try {
            // В PrestaShop нет Tools::decrypt(), используем обратное base64
            // Для реальной защиты можно использовать openssl_decrypt() с ключом
            $username = base64_decode($encryptedUser);
            $password = base64_decode($encryptedPass);
        } catch (Exception $e) {
            throw new Exception('Failed to decrypt AccuPOS credentials: ' . $e->getMessage());
        }

        // Формирование DSN
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbname);

        // Опции PDO
        $options = array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => $this->timeout,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4'
        );

        // Попытки подключения с retry логикой
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            try {
                $this->connection = new PDO($dsn, $username, $password, $options);
                
                // Успешное подключение
                PrestaShopLogger::addLog(
                    'AccuPOS DB: Successfully connected to ' . $host,
                    1,
                    null,
                    'AccuPosDB'
                );
                
                return $this->connection;
            } catch (PDOException $e) {
                $attempt++;
                $lastException = $e;
                
                PrestaShopLogger::addLog(
                    sprintf('AccuPOS DB: Connection attempt %d/%d failed - %s', $attempt, $this->maxRetries, $e->getMessage()),
                    3,
                    null,
                    'AccuPosDB'
                );
                
                // Пауза перед следующей попыткой
                if ($attempt < $this->maxRetries) {
                    sleep(2);
                }
            }
        }

        // Все попытки исчерпаны
        throw new Exception('Failed to connect to AccuPOS DB after ' . $this->maxRetries . ' attempts: ' . $lastException->getMessage());
    }

    /**
     * Получение активного соединения
     * 
     * @return PDO|false
     * @throws Exception
     */
    public function getConnection()
    {
        if ($this->connection === null) {
            return $this->connect();
        }
        
        return $this->connection;
    }

    /**
     * Выполнение SELECT запроса
     * 
     * @param string $query SQL запрос
     * @param array $params Параметры для prepared statement
     * @return array Результат запроса
     * @throws Exception
     */
    public function query($query, $params = array())
    {
        try {
            $connection = $this->getConnection();
            $stmt = $connection->prepare($query);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            PrestaShopLogger::addLog(
                'AccuPOS DB Query Error: ' . $e->getMessage() . ' | Query: ' . $query,
                4,
                null,
                'AccuPosDB'
            );
            throw new Exception('Database query error: ' . $e->getMessage());
        }
    }

    /**
     * Получение одной строки результата
     * 
     * @param string $query SQL запрос
     * @param array $params Параметры
     * @return array|false
     * @throws Exception
     */
    public function queryRow($query, $params = array())
    {
        try {
            $connection = $this->getConnection();
            $stmt = $connection->prepare($query);
            $stmt->execute($params);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            PrestaShopLogger::addLog(
                'AccuPOS DB Query Error: ' . $e->getMessage(),
                4,
                null,
                'AccuPosDB'
            );
            throw new Exception('Database query error: ' . $e->getMessage());
        }
    }

    /**
     * Получение одного значения
     * 
     * @param string $query SQL запрос
     * @param array $params Параметры
     * @return mixed
     * @throws Exception
     */
    public function queryValue($query, $params = array())
    {
        try {
            $connection = $this->getConnection();
            $stmt = $connection->prepare($query);
            $stmt->execute($params);
            
            return $stmt->fetchColumn();
        } catch (PDOException $e) {
            PrestaShopLogger::addLog(
                'AccuPOS DB Query Error: ' . $e->getMessage(),
                4,
                null,
                'AccuPosDB'
            );
            throw new Exception('Database query error: ' . $e->getMessage());
        }
    }

    /**
     * Закрытие соединения
     */
    public function disconnect()
    {
        $this->connection = null;
    }

    /**
     * Деструктор - автоматически закрывает соединение
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Тестирование подключения
     * 
     * @return bool Успешность подключения
     */
    public function testConnection()
    {
        try {
            $connection = $this->connect();
            
            // Простой тест-запрос
            $stmt = $connection->query('SELECT 1 as test');
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return isset($result['test']) && $result['test'] == 1;
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'AccuPOS DB Test Connection Failed: ' . $e->getMessage(),
                3,
                null,
                'AccuPosDB'
            );
            return false;
        }
    }
}

