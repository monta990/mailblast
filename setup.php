<?php
/**
 * Mail Blast — GLPI plugin for bulk email to all registered users.
 *
 * @author  Edwin Elias Alvarez
 * @license GPL-2.0-or-later
 */

define('PLUGIN_MAILBLAST_VERSION',  '1.0.0');
define('PLUGIN_MAILBLAST_MIN_GLPI', '11.0.0');
define('PLUGIN_MAILBLAST_MAX_GLPI', '11.99.99');

// ─── Version ─────────────────────────────────────────────────────────────────

function plugin_version_mailblast(): array
{
    return [
        'name'         => 'Mail Blast',
        'version'      => PLUGIN_MAILBLAST_VERSION,
        'author'       => 'Edwin Elias Alvarez',
        'license'      => 'GPL v2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_MAILBLAST_MIN_GLPI,
                'max' => PLUGIN_MAILBLAST_MAX_GLPI,
            ],
        ],
    ];
}

// ─── Prerequisites & config check ────────────────────────────────────────────

function plugin_mailblast_check_prerequisites(): bool
{
    if (
        version_compare(GLPI_VERSION, PLUGIN_MAILBLAST_MIN_GLPI, 'lt')
        || version_compare(GLPI_VERSION, PLUGIN_MAILBLAST_MAX_GLPI, 'gt')
    ) {
        echo 'This plugin requires GLPI >= '
            . PLUGIN_MAILBLAST_MIN_GLPI
            . ' and <= '
            . PLUGIN_MAILBLAST_MAX_GLPI;
        return false;
    }
    return true;
}

function plugin_mailblast_check_config(bool $verbose = false): bool
{
    return true;
}

// ─── Initialisation (called by GLPI on every page load) ──────────────────────

function plugin_init_mailblast(): void
{
    global $PLUGIN_HOOKS;

    // Required for GLPI 10+ CSRF protection
    $PLUGIN_HOOKS['csrf_compliant']['mailblast'] = true;

    // Register the main class so the menu system can discover it
    Plugin::registerClass('PluginMailblastMailblast');

    // ── Gear icon in Setup → Plugins list ────────────────────────────────
    // This is the hook that makes the wrench/gear icon appear and clickable.
    $PLUGIN_HOOKS['config_page']['mailblast'] = 'front/send.php';

    // ── Administration menu entry ─────────────────────────────────────────
    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['menu_toadd']['mailblast'] = ['admin' => 'PluginMailblastMailblast'];
    }
}
