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

    // Remove the version-check cache created outside glpi_configs. Never recurse:
    // preserve any unrelated files that an administrator may have placed there.
    $pluginDataRoot = defined('GLPI_PLUGIN_DOC_DIR')
        ? GLPI_PLUGIN_DOC_DIR
        : (defined('GLPI_VAR_DIR') ? GLPI_VAR_DIR . '/_plugins' : sys_get_temp_dir());
    $pluginDataDir = rtrim($pluginDataRoot, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'mailblast';
    $versionCache = $pluginDataDir . DIRECTORY_SEPARATOR . 'version-check.json';

    if (is_file($versionCache) || is_link($versionCache)) {
        @unlink($versionCache);
    }

    if (is_dir($pluginDataDir) && !is_link($pluginDataDir)) {
        $entries = @scandir($pluginDataDir);
        if (is_array($entries) && array_diff($entries, ['.', '..']) === []) {
            @rmdir($pluginDataDir);
        }
    }

    return true;
}
