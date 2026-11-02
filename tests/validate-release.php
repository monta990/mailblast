<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$read = static function (string $path) use ($root, &$errors): string {
    $fullPath = $root . '/' . $path;
    if (!is_file($fullPath) || !is_readable($fullPath)) {
        $errors[] = "Required file is missing or unreadable: {$path}";
        return '';
    }
    $content = file_get_contents($fullPath);
    if ($content === false) {
        $errors[] = "Could not read: {$path}";
        return '';
    }
    return $content;
};

$setup = $read('setup.php');
$pluginXml = $read('plugin.xml');
$hook = $read('hook.php');
$controller = $read('src/Controller/MailBlastController.php');
$queue = $read('src/Service/QueueService.php');
$action = $read('src/Service/ActionService.php');

$version = null;
if (preg_match("/define\\('PLUGIN_MAILBLAST_VERSION',\\s*'([^']+)'\\)/", $setup, $match) === 1) {
    $version = $match[1];
} else {
    $errors[] = 'PLUGIN_MAILBLAST_VERSION is missing from setup.php.';
}

if ($version !== null) {
    if (!str_contains($setup, "define('PLUGIN_MAILBLAST_MIN_GLPI', '11.0.0')")) {
        $errors[] = 'The declared minimum GLPI version changed unexpectedly.';
    }
    if (!str_contains($setup, "define('PLUGIN_MAILBLAST_MAX_GLPI', '12.99.99')")) {
        $errors[] = 'The declared maximum GLPI version changed unexpectedly.';
    }
    if (!class_exists(SimpleXMLElement::class)) {
        $errors[] = 'SimpleXML is required to validate plugin.xml.';
    } else {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($pluginXml, SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            $errors[] = 'plugin.xml is not valid XML.';
        } else {
            if ((string) $xml->key !== 'mailblast') {
                $errors[] = 'plugin.xml key must remain mailblast.';
            }
            $compatibility = [];
            foreach ($xml->versions->version as $release) {
                if ((string) $release->num === $version) {
                    foreach ($release->compatibility as $item) {
                        $compatibility[] = (string) $item;
                    }
                }
            }
            sort($compatibility);
            $expected = ['~11.0', '~12.0'];
            sort($expected);
            if ($compatibility !== $expected) {
                $errors[] = 'plugin.xml compatibility does not match the GLPI 11/12 support declared by setup.php.';
            }
        }
    }
}

$requiredRuntimeFiles = [
    'setup.php', 'hook.php', 'plugin.xml', 'src/MailBlastMenu.php',
    'src/Controller/MailBlastController.php',
    'src/Service/ActionService.php', 'src/Service/AttachmentService.php',
    'src/Service/ConfigurationService.php', 'src/Service/ContentService.php',
    'src/Service/MailService.php', 'src/Service/QueueService.php',
    'src/Service/RecipientService.php', 'src/Service/ReportService.php',
    'src/Service/ViewService.php', 'templates/config.html.twig',
    'templates/send.html.twig', 'js/mailblast_send.js',
];
foreach ($requiredRuntimeFiles as $path) {
    $read($path);
}

$srcRoot = $root . '/src';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $source = file_get_contents($file->getPathname());
    if ($source === false) {
        $errors[] = 'Could not read source file: ' . $file->getPathname();
        continue;
    }
    if (preg_match('/^namespace\\s+([^;]+);/m', $source, $namespaceMatch) !== 1) {
        $errors[] = 'PSR-4 namespace declaration is missing: ' . $file->getPathname();
        continue;
    }
    if (preg_match('/^(?:final\\s+|abstract\\s+)?(?:class|interface|trait|enum)\\s+([A-Za-z_][A-Za-z0-9_]*)/m', $source, $classMatch) !== 1) {
        $errors[] = 'No class-like declaration was found: ' . $file->getPathname();
        continue;
    }

    $relative = substr($file->getPathname(), strlen($srcRoot) + 1);
    $expectedClass = 'GlpiPlugin\\Mailblast\\'
        . str_replace('/', '\\', substr($relative, 0, -4));
    $actualClass = trim($namespaceMatch[1]) . '\\' . $classMatch[1];
    if ($actualClass !== $expectedClass) {
        $errors[] = "PSR-4 mismatch in {$relative}: expected {$expectedClass}, found {$actualClass}.";
    }
}

if (!str_contains($controller, 'extends AbstractController')) {
    $errors[] = 'The modern Symfony controller base class is missing.';
}
if (!str_contains($controller, '#[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]')) {
    $errors[] = 'Authenticated route security metadata is missing.';
}
if (substr_count($controller, "Session::checkRight('config', UPDATE)") < 4) {
    $errors[] = 'Every controller endpoint must retain the GLPI config/UPDATE permission check.';
}
if (!str_contains($action, "'queue_process' =>") || !str_contains($action, "'queue_init' =>")) {
    $errors[] = 'The existing browser-driven queue actions were changed or removed.';
}
if (!str_contains($queue, "ConfigurationService::CONFIG_CONTEXT") || !str_contains($queue, "'payload_hash'")) {
    $errors[] = 'The queue metadata storage/integrity contract is missing.';
}
if (!str_contains($hook, "context' => 'plugin:mailblast'")) {
    $errors[] = 'The existing plugin configuration cleanup context changed.';
}
foreach (['composer.json', 'composer.lock'] as $path) {
    if (file_exists($root . '/' . $path)) {
        $errors[] = "Unexpected Composer file added to the production plugin root: {$path}";
    }
}
foreach (['vendor', 'front', 'ajax', 'inc'] as $path) {
    if (file_exists($root . '/' . $path)) {
        $errors[] = "Unexpected runtime directory found: {$path}";
    }
}
foreach (glob($root . '/locales/*.po') ?: [] as $poFile) {
    if (filesize($poFile) === 0) {
        $errors[] = 'Empty translation catalog: ' . basename($poFile);
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, array_map(static fn(string $error): string => 'ERROR: ' . $error, $errors)) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Mail Blast {$version}: release metadata, PSR-4 layout, protected endpoints and production architecture contracts are valid." . PHP_EOL);
