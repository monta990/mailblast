<?php
/**
 * Mail Blast — hook.php
 *
 * @author  Edwin Elias Alvarez
 * @license GPL-3.0-or-later
 */

// ─── Install ──────────────────────────────────────────────────────────────────

function plugin_mailblast_install(): bool
{
    // No custom tables — queue is managed via glpi_configs (already exists)
    // and LIMIT/OFFSET directly on glpi_useremails at send time.
    return true;
}

// ─── Uninstall ────────────────────────────────────────────────────────────────

function plugin_mailblast_uninstall(): bool
{
    global $DB;

    // Remove all persisted plugin config entries
    if ($DB->tableExists('glpi_configs')) {
        $DB->delete('glpi_configs', ['context' => 'plugin:mailblast']);
    }

    return true;
}

// ─── Gear icon in the plugin list ────────────────────────────────────────────

function plugin_mailblast_haveConfigPage(): bool
{
    return Session::haveRight('config', UPDATE);
}
