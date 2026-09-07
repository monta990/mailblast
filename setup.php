<?php
/**
 * Mail Blast — GLPI plugin for bulk email to all registered users.
 *
 * @author  Edwin Elias Alvarez
 * @license GPL-3.0-or-later
 */
define('PLUGIN_MAILBLAST_VERSION',  '1.8.1');
define('PLUGIN_MAILBLAST_MIN_GLPI', '11.0.0');
define('PLUGIN_MAILBLAST_MAX_GLPI', '12.99.99');

// GLPI automatically registers the plugin PSR-4 namespace from src/.
// No plugin-local autoloader or Composer dependency is required.

// ─── Version ─────────────────────────────────────────────────────────────────

function plugin_version_mailblast(): array
{
    return [
        'name'         => 'Mail Blast',
        'version'      => PLUGIN_MAILBLAST_VERSION,
        'author'       => 'Edwin Elias Alvarez',
        'license'      => 'GPL v3+',
        'homepage'     => 'https://github.com/monta990/mailblast',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_MAILBLAST_MIN_GLPI,
                'max' => PLUGIN_MAILBLAST_MAX_GLPI,
            ],
            'php'  => [
                'min' => '8.2',
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

    // GLPI prefixes config_page entries with the plugin web path.
    // Keep this relative to the plugin so it resolves to /plugins/mailblast/Configuration.
    $PLUGIN_HOOKS['config_page']['mailblast'] = 'Configuration';

    Plugin::registerClass(\GlpiPlugin\Mailblast\MailBlastMenu::class);

    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['menu_toadd']['mailblast'] = ['admin' => \GlpiPlugin\Mailblast\MailBlastMenu::class];
    }
}
