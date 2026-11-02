<?php
declare(strict_types=1);

$sourceRoot = $argv[1] ?? '';
$namespacePrefix = trim($argv[2] ?? '', '\\');
if ($sourceRoot === '' || !is_dir($sourceRoot) || $namespacePrefix === '') {
    fwrite(STDERR, "Usage: php tests/check-psr4.php <src-root> <namespace-prefix>" . PHP_EOL);
    exit(2);
}

$errors = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $source = file_get_contents($file->getPathname());
    if ($source === false) {
        $errors[] = 'Cannot read ' . $file->getPathname();
        continue;
    }
    if (preg_match('/^namespace\\s+([^;]+);/m', $source, $namespaceMatch) !== 1) {
        $errors[] = 'Missing namespace: ' . $file->getPathname();
        continue;
    }
    if (preg_match('/^(?:final\\s+|abstract\\s+)?(?:class|interface|trait|enum)\\s+([A-Za-z_][A-Za-z0-9_]*)/m', $source, $classMatch) !== 1) {
        $errors[] = 'Missing class-like declaration: ' . $file->getPathname();
        continue;
    }
    $relative = substr($file->getPathname(), strlen(rtrim($sourceRoot, DIRECTORY_SEPARATOR)) + 1);
    $expected = $namespacePrefix . '\\' . str_replace('/', '\\', substr($relative, 0, -4));
    $actual = trim($namespaceMatch[1]) . '\\' . $classMatch[1];
    if ($actual !== $expected) {
        $errors[] = "Expected {$expected}, found {$actual} in {$relative}.";
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "PSR-4 namespace and class/file mapping is valid for {$namespacePrefix}." . PHP_EOL);
