<?php

declare(strict_types=1);

// Starts your own service from this repository: gives the code your namespace and your package name.
//
//   php scripts/rename.php "Acme\Orders" acme/orders
//   composer dump-autoload && composer ci
//
// It changes the PHP namespace `Demo\` (in src/, tests/, config/, public/, bin/) and the package name in composer.json.
// What is left to you: remove what you do not need (the demo's import, the identity provider, the chaos switches), and change
// the names in compose.yaml, the Dockerfile and the README.

if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR, "Usage: php scripts/rename.php \"Vendor\\Namespace\" vendor/package\n");
    exit(1);
}

[, $namespace, $package] = $argv;
if (1 !== preg_match('/^[A-Z][A-Za-z0-9]*(\\\\[A-Z][A-Za-z0-9]*)*$/', $namespace) || 1 !== preg_match('#^[a-z0-9-]+/[a-z0-9-]+$#', $package)) {
    fwrite(STDERR, "The namespace looks like Acme\\Orders, the package like acme/orders.\n");
    exit(1);
}

$root = dirname(__DIR__);
$changed = 0;
foreach (['src', 'tests', 'config', 'public', 'bin', 'scripts'] as $folder) {
    if (!is_dir("$root/$folder")) {
        continue;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$folder", FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || str_ends_with($file->getFilename(), 'rename.php')) {
            continue;
        }
        $content = (string) file_get_contents($file->getPathname());
        $new = preg_replace('/(?<![A-Za-z0-9_])Demo\\\\/', str_replace('\\', '\\\\', $namespace) . '\\\\', $content);
        if (null !== $new && $new !== $content) {
            file_put_contents($file->getPathname(), $new);
            ++$changed;
        }
    }
}

// composer.json is edited as text, not decoded and encoded again: that would turn an empty JSON object into an array
$json = (string) file_get_contents("$root/composer.json");
$escaped = str_replace('\\', '\\\\', $namespace);
$json = preg_replace('/"name":\s*"[^"]*"/', '"name": "' . $package . '"', $json, 1) ?? $json;
$json = str_replace(['"Demo\\\\Tests\\\\": "tests/"', '"Demo\\\\": "src/"'], ['"' . $escaped . '\\\\Tests\\\\": "tests/"', '"' . $escaped . '\\\\": "src/"'], $json);
file_put_contents("$root/composer.json", $json);
echo "$changed files now use the namespace $namespace; composer.json is $package.\nRun: composer dump-autoload && composer ci\n";
