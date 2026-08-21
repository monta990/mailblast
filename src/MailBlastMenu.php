<?php
/**
 * Mail Blast menu integration.
 *
 * @author Edwin Elias Alvarez
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Mailblast;

use Session;

final class MailBlastMenu
{
    public static function getTypeName($nb = 0): string
    {
        return __('Mail Blast', 'mailblast');
    }

    public static function getMenuContent(): array
    {
        global $CFG_GLPI;

        if (!Session::haveRight('config', UPDATE)) {
            return [];
        }

        $root = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
        $sendUrl = $root . '/plugins/mailblast/Send';
        $configurationUrl = $root . '/plugins/mailblast/Configuration';

        return [
            'title' => self::getTypeName(),
            'page'  => $sendUrl,
            'icon'  => 'ti ti-mail-forward',
            'links' => [
                'config' => $configurationUrl,
            ],
        ];
    }

}
