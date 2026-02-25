<?php
/**
 * Upgrade script to 0.1.1
 *
 * - Обновляет названия вкладки AdminAccuPosSync по языкам (чтобы подтянулись переводы).
 *
 * @param AccuPos_Sync $module
 * @return bool
 */
function upgrade_module_0_1_1($module)
{
    try {
        $tabId = (int) Tab::getIdFromClassName('AdminAccuPosSync');
        if (!$tabId) {
            return true;
        }

        $tab = new Tab($tabId);
        $languages = Language::getLanguages(false);

        foreach ($languages as $lang) {
            $idLang = (int) $lang['id_lang'];
            $tab->name[$idLang] = $module->l('AccuPOS Sync', 'accupos_sync', $idLang);
        }

        return (bool) $tab->save();
    } catch (Exception $e) {
        PrestaShopLogger::addLog(
            'AccuPOS Sync: upgrade 0.1.1 failed - ' . $e->getMessage(),
            3,
            null,
            'AccuPos_Sync'
        );
        return false;
    }
}


