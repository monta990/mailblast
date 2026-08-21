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
    //
    // GLPI 11.0.9+ and GLPI 12 handle the plugin/template cache lifecycle
    // in the core. Older GLPI 11 releases require the plugin to invalidate
    // the cache explicitly after installation or upgrade so that compiled
    // Twig templates from a previous plugin version are not reused.
    if (version_compare(GLPI_VERSION, '11.0.9', '<')) {
        (new \Glpi\Cache\CacheManager())->resetAllCaches();
    }

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
