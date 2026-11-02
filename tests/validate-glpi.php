<?php
declare(strict_types=1);

$glpiRoot = $argv[1] ?? '';
$pluginRoot = $argv[2] ?? '';
if ($glpiRoot === '' || $pluginRoot === '' || !is_dir($glpiRoot) || !is_dir($pluginRoot)) {
    fwrite(STDERR, "Usage: php tests/validate-glpi.php <glpi-root> <plugin-root>" . PHP_EOL);
    exit(2);
}

$errors = [];
$requiredCoreSymbols = [
    'src/Glpi/Controller/AbstractController.php' => [
        'abstract class AbstractController', 'function render(',
    ],
    'src/Glpi/Security/Attribute/SecurityStrategy.php' => [
        'final readonly class SecurityStrategy', 'public string $strategy',
    ],
    'src/Glpi/Http/Firewall.php' => [
        'final class Firewall', 'STRATEGY_AUTHENTICATED',
    ],
    'src/Glpi/Cache/CacheManager.php' => [
        'function resetAllCaches(): bool',
    ],
    'src/Html.php' => [
        'function initEditorSystem(', 'array $plugins_to_remove',
    ],
    'src/GLPIMailer.php' => [
        'function buildDsn(bool $with_clear_password): string',
    ],
    'src/Config.php' => [
        'function getConfigurationValues(', 'function setConfigurationValues(',
    ],
    'src/Session.php' => [
        'function checkRight(', 'function haveRight(',
    ],
];

foreach ($requiredCoreSymbols as $relative => $symbols) {
    $path = rtrim($glpiRoot, DIRECTORY_SEPARATOR) . '/' . $relative;
    if (!is_file($path)) {
        $errors[] = "GLPI core file is missing: {$relative}";
        continue;
    }
    $source = file_get_contents($path);
    if ($source === false) {
        $errors[] = "GLPI core file is unreadable: {$relative}";
        continue;
    }
    foreach ($symbols as $symbol) {
        if (!str_contains($source, $symbol)) {
            $errors[] = "GLPI API contract not found in {$relative}: {$symbol}";
        }
    }
}

$autoloadFile = rtrim($glpiRoot, DIRECTORY_SEPARATOR) . '/vendor/autoload.php';
if (!is_file($autoloadFile)) {
    $errors[] = 'GLPI Composer autoloader is missing.';
} else {
    $loader = require_once $autoloadFile;
    if (is_object($loader) && method_exists($loader, 'addPsr4')) {
        $loader->addPsr4(
            'GlpiPlugin\\Mailblast\\',
            rtrim($pluginRoot, DIRECTORY_SEPARATOR) . '/src'
        );
    }

    $runtimeSymbols = [
        'Glpi\\Controller\\AbstractController',
        'Glpi\\Security\\Attribute\\SecurityStrategy',
        'Glpi\\Http\\Firewall',
        'Glpi\\Cache\\CacheManager',
        'Symfony\\Component\\Routing\\Attribute\\Route',
        'Symfony\\Component\\HttpFoundation\\Request',
        'Symfony\\Component\\HttpFoundation\\JsonResponse',
        'Symfony\\Component\\Mailer\\Transport',
        'Symfony\\Component\\Mailer\\Transport\\TransportInterface',
        'Symfony\\Component\\Mime\\Email',
        'PhpOffice\\PhpSpreadsheet\\Spreadsheet',
        'GlpiPlugin\\Mailblast\\Controller\\MailBlastController',
        'GlpiPlugin\\Mailblast\\Service\\ActionService',
        'GlpiPlugin\\Mailblast\\Service\\AttachmentService',
        'GlpiPlugin\\Mailblast\\Service\\ConfigurationService',
        'GlpiPlugin\\Mailblast\\Service\\ContentService',
        'GlpiPlugin\\Mailblast\\Service\\MailService',
        'GlpiPlugin\\Mailblast\\Service\\QueueService',
        'GlpiPlugin\\Mailblast\\Service\\RecipientService',
        'GlpiPlugin\\Mailblast\\Service\\ReportService',
        'GlpiPlugin\\Mailblast\\Service\\ViewService',
    ];
    foreach ($runtimeSymbols as $symbol) {
        if (!class_exists($symbol) && !interface_exists($symbol)) {
            $errors[] = "GLPI runtime class/interface cannot be autoloaded: {$symbol}";
        }
    }

    if (class_exists('GlpiPlugin\\Mailblast\\Controller\\MailBlastController')) {
        $controllerReflection = new ReflectionClass('GlpiPlugin\\Mailblast\\Controller\\MailBlastController');
        $routeCount = 0;
        $securityCount = 0;
        foreach ($controllerReflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes() as $attribute) {
                $instance = $attribute->newInstance();
                if ($instance instanceof Symfony\Component\Routing\Attribute\Route) {
                    ++$routeCount;
                }
                if ($instance instanceof Glpi\Security\Attribute\SecurityStrategy) {
                    ++$securityCount;
                }
            }
        }
        if ($routeCount !== 4 || $securityCount !== 4) {
            $errors[] = "Expected 4 protected controller routes; found {$routeCount} routes and {$securityCount} security attributes.";
        }
    }
}

$pluginFiles = [
    'setup.php', 'hook.php', 'src/Controller/MailBlastController.php',
    'src/Service/ActionService.php', 'src/Service/AttachmentService.php',
    'src/Service/ConfigurationService.php', 'src/Service/ContentService.php',
    'src/Service/MailService.php', 'src/Service/QueueService.php',
    'src/Service/RecipientService.php', 'src/Service/ReportService.php',
    'src/Service/ViewService.php', 'templates/config.html.twig',
    'templates/send.html.twig',
];
foreach ($pluginFiles as $relative) {
    if (!is_file(rtrim($pluginRoot, DIRECTORY_SEPARATOR) . '/' . $relative)) {
        $errors[] = "Plugin file was not copied into the GLPI test installation: {$relative}";
    }
}
$setup = file_get_contents(rtrim($pluginRoot, DIRECTORY_SEPARATOR) . '/setup.php');
if (!is_string($setup) || !str_contains($setup, "define('PLUGIN_MAILBLAST_MIN_GLPI', '11.0.0')")) {
    $errors[] = 'Plugin minimum GLPI declaration is missing.';
}
if (!is_string($setup) || !str_contains($setup, "define('PLUGIN_MAILBLAST_MAX_GLPI', '12.99.99')")) {
    $errors[] = 'Plugin maximum GLPI declaration is missing.';
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, array_map(static fn(string $error): string => 'ERROR: ' . $error, $errors)) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Mail Blast core API contract is compatible with the checked GLPI source tree." . PHP_EOL);
