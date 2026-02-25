<?php
/**
 * AccuPOS Sync Admin Controller
 * 
 * Контроллер для отображения модуля в главном меню PrestaShop
 * 
 * @author    Aleksei Nekrasov <info@impserver.ru>
 * @copyright 2025 ООО «Свобода» / impserver.ru
 * @license   Proprietary
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminAccuPosSyncController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    /**
     * При открытии контроллера перенаправляем на страницу настроек модуля
     */
    public function initContent()
    {
        // Перенаправление на страницу конфигурации модуля
        Tools::redirectAdmin(
            $this->context->link->getAdminLink('AdminModules') . 
            '&configure=accupos_sync&tab_module=administration&module_name=accupos_sync'
        );
    }
}

