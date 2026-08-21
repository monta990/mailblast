<?php
/**
 * Mail Blast service.
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Mailblast\Service;

use Config;

final class ConfigurationService
{
    public const CONFIG_CONTEXT = 'plugin:mailblast';

    public function saveSettings(int $batchSize, int $batchDelay, int $maxAttachment): void
    {
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [
            'batch_size' => $batchSize,
            'batch_delay_ms' => $batchDelay,
            'max_attachment_mb' => $maxAttachment,
        ]);
    }

    public function saveFormConfig(string $subject, string $body, string $footer): void
        {
            Config::setConfigurationValues(self::CONFIG_CONTEXT, [
                'last_subject' => $subject,
                'last_footer'  => $footer,
            ]);
        }

    public function loadFormConfig(): array
        {
            $cfg = Config::getConfigurationValues(
                self::CONFIG_CONTEXT,
                ['last_subject', 'last_footer']
            );
    
            return [
                'subject' => (string) ($cfg['last_subject'] ?? ''),
                'body'    => '',  // never persisted — editor always starts blank
                'footer'  => (string) ($cfg['last_footer']  ?? ''),
            ];
        }

    public function getBatchSize(): int
        {
            $cfg = Config::getConfigurationValues(self::CONFIG_CONTEXT, ['batch_size']);
            $v   = (int) ($cfg['batch_size'] ?? 0);
            return ($v >= 1 && $v <= 100) ? $v : 15;
        }

    public function getBatchDelayMs(): int
        {
            $cfg = Config::getConfigurationValues(self::CONFIG_CONTEXT, ['batch_delay_ms']);
            // Use -1 as sentinel: if key is not in DB yet, ?? returns -1 which
            // fails the >= 0 check and the default 120 is returned instead.
            $v   = (int) ($cfg['batch_delay_ms'] ?? -1);
            return ($v >= 0 && $v <= 5000) ? $v : 120;
        }

    public function getMaxAttachmentMb(): int
        {
            $cfg = Config::getConfigurationValues(self::CONFIG_CONTEXT, ['max_attachment_mb']);
            $v   = (int) ($cfg['max_attachment_mb'] ?? 0);
            return ($v >= 1 && $v <= 100) ? $v : 15;
        }

    public function cleanupStaleJobs(int $maxAgeSeconds = 7200): void
        {
            global $DB;
    
            $iterator = $DB->request([
                'SELECT' => ['name', 'value'],
                'FROM'   => 'glpi_configs',
                'WHERE'  => [
                    'context' => self::CONFIG_CONTEXT,
                    'name'    => ['LIKE', 'queue_%'],
                ],
            ]);
    
            foreach ($iterator as $row) {
                $job = json_decode((string) $row['value'], true);
                $createdAt = (int) ($job['created_at'] ?? 0);
                if ($createdAt > 0 && (time() - $createdAt) > $maxAgeSeconds) {
                    $DB->delete('glpi_configs', [
                        'context' => self::CONFIG_CONTEXT,
                        'name'    => $row['name'],
                    ]);
                }
            }
        }

    public function cleanupJob(string $sendId): void
        {
            global $DB;
            $DB->delete('glpi_configs', [
                'context' => self::CONFIG_CONTEXT,
                'name'    => 'queue_' . $sendId,
            ]);
        }

    public function addHistory(string $subject, int $sent, int $errors): void
        {
            $cfg  = Config::getConfigurationValues(self::CONFIG_CONTEXT, ['send_history']);
            $list = json_decode((string) ($cfg['send_history'] ?? '[]'), true);
            if (!is_array($list)) $list = [];
    
            array_unshift($list, [
                'date'    => date('Y-m-d H:i'),
                'subject' => strip_tags(html_entity_decode($subject, ENT_QUOTES, 'UTF-8')),
                'sent'    => $sent,
                'errors'  => $errors,
            ]);
    
            $list = array_slice($list, 0, 10);
            Config::setConfigurationValues(self::CONFIG_CONTEXT, [
                'send_history' => json_encode($list),
            ]);
        }

    public function getHistory(): array
        {
            $cfg  = Config::getConfigurationValues(self::CONFIG_CONTEXT, ['send_history']);
            $list = json_decode((string) ($cfg['send_history'] ?? '[]'), true);
            return is_array($list) ? $list : [];
        }

    /**
     * Check the latest stable GitHub release.
     *
     * The result is cached outside glpi_configs so the Mail Blast queue keeps
     * its existing storage contract. A failed remote check never affects the
     * plugin: the last known stable version is retained as a stale fallback.
     */
    public function checkLatestVersion(): array
    {
        $cache = $this->readVersionCache();
        $now = time();
        $cacheAge = $now - (int) ($cache['checked_at'] ?? 0);
        $cacheFresh = $cacheAge >= 0 && $cacheAge < 21600; // 6 hours

        $latest = (string) ($cache['latest'] ?? '');
        $checkOk = (bool) ($cache['check_ok'] ?? false);

        if (!$cacheFresh) {
            $fetched = $this->fetchLatestGithubRelease();
            $checkOk = $fetched !== '';
            if ($checkOk) {
                $latest = $fetched;
            }

            // Record both success and failure. On failure, keep the last known
            // stable version as a stale fallback, but do not present it as a
            // current successful check.
            $this->writeVersionCache([
                'checked_at' => $now,
                'latest'     => $latest,
                'check_ok'   => $checkOk,
            ]);
        }

        $available = $checkOk
            && $latest !== ''
            && version_compare($latest, PLUGIN_MAILBLAST_VERSION, '>');

        return [
            'current'          => PLUGIN_MAILBLAST_VERSION,
            'latest'           => $latest,
            'update_available' => $available,
            'check_available'  => $checkOk && $latest !== '',
            'checked_at'       => $cacheFresh ? (int) ($cache['checked_at'] ?? 0) : $now,
            'releases_url'     => 'https://github.com/monta990/mailblast/releases',
        ];
    }

    private function fetchLatestGithubRelease(): string
    {
        if (!function_exists('curl_init')) {
            return '';
        }

        $url = 'https://api.github.com/repos/monta990/mailblast/releases?per_page=30';
        $ch = curl_init($url);
        if ($ch === false) {
            return '';
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: mailblast-glpi-plugin/1.8',
                'Accept: application/vnd.github+json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXREDIRS      => 0,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($body) || $body === '' || $code !== 200 || strlen($body) > 65536) {
            return '';
        }

        $releases = json_decode($body, true);
        if (!is_array($releases)) {
            return '';
        }

        $versions = [];
        foreach ($releases as $release) {
            if (!is_array($release)
                || !empty($release['draft'])
                || !empty($release['prerelease'])) {
                continue;
            }

            $tag = ltrim((string) ($release['tag_name'] ?? ''), 'vV');
            if (preg_match('/^\d+(?:\.\d+){0,3}$/', $tag) !== 1) {
                continue;
            }

            $versions[] = $tag;
        }

        if ($versions === []) {
            return '';
        }

        usort($versions, 'version_compare');
        return (string) end($versions);
    }

    private function getVersionCachePath(): string
    {
        $base = defined('GLPI_PLUGIN_DOC_DIR')
            ? GLPI_PLUGIN_DOC_DIR
            : (defined('GLPI_VAR_DIR') ? GLPI_VAR_DIR . '/_plugins' : sys_get_temp_dir());

        return rtrim($base, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'mailblast'
            . DIRECTORY_SEPARATOR . 'version-check.json';
    }

    private function readVersionCache(): array
    {
        $path = $this->getVersionCachePath();
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false || strlen($raw) > 8192) {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function writeVersionCache(array $data): void
    {
        $path = $this->getVersionCachePath();
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return;
        }

        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, $path);
        } else {
            @unlink($tmp);
        }
    }

    public function checkCooldown(int $cooldownSeconds = 30): ?string
        {
            $cfg     = Config::getConfigurationValues(self::CONFIG_CONTEXT, ['last_send_at']);
            $lastAt  = (int) ($cfg['last_send_at'] ?? 0);
            $elapsed = time() - $lastAt;
    
            if ($lastAt > 0 && $elapsed < $cooldownSeconds) {
                $remaining = $cooldownSeconds - $elapsed;
                return sprintf(
                    __('Please wait %d seconds before sending again.', 'mailblast'),
                    $remaining
                );
            }
    
            return null;
        }

    public function recordSendCompleted(): void
        {
            Config::setConfigurationValues(self::CONFIG_CONTEXT, ['last_send_at' => time()]);
        }

}
