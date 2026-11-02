<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$root = rtrim(getenv('PLUGIN_SOURCE_ROOT') ?: dirname(__DIR__), DIRECTORY_SEPARATOR);
$finder = Finder::create()
    ->files()
    ->name('*.php')
    ->in([
        $root . '/src',
        $root . '/tests',
        $root . '/scripts',
    ])
    ->append([
        $root . '/setup.php',
        $root . '/hook.php',
    ]);

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS3x0' => true,
        'array_syntax' => ['syntax' => 'short'],
    ])
    ->setFinder($finder);
