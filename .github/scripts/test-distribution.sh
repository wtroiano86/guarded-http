#!/bin/sh
set -eu

package_root="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
cd "$package_root"
mkdir -p tmp
distribution_dir="$(mktemp -d "$package_root/tmp/distribution.XXXXXX")"
trap 'rm -rf -- "$distribution_dir"' EXIT

composer archive --format=zip --file=guarded-http --dir="$distribution_dir" --no-interaction

php /dev/stdin "$distribution_dir" <<'PHP'
<?php

declare(strict_types=1);

$directory = $argv[1];
$archivePath = $directory.'/guarded-http.zip';
$archive = new ZipArchive;
if ($archive->open($archivePath) !== true) {
    throw new RuntimeException('Unable to open the package archive.');
}

foreach (['composer.json', 'LICENSE', 'README.md', 'CHANGELOG.md', 'SECURITY.md', 'src/Contracts/EndpointPolicy.php', 'src/Contracts/RequestProfile.php', 'src/Providers/GuardedHttpServiceProvider.php', 'src/Pipelines/RequestPipeline.php', 'src/Pipelines/RequestProfilePipeline.php', 'src/Support/RequestSettings.php', 'resources/boost/guidelines/core.blade.php'] as $required) {
    if ($archive->locateName($required) === false) {
        throw new RuntimeException('The package archive is missing '.$required);
    }
}

for ($index = 0; $index < $archive->numFiles; $index++) {
    $entry = $archive->getNameIndex($index);
    $root = explode('/', $entry)[0];
    if (in_array($root, ['.git', '.github', '.phpstan', '.phpunit.cache', '.phpunit.result.cache', '.php-cs-fixer.cache', 'vendor', 'tests', 'tmp', 'temp', 'composer.lock', 'composer.local.json', 'composer.local.lock', 'pint.json', 'rector.php', 'phpstan.neon', 'phpunit.xml'], true)) {
        throw new RuntimeException('The package archive includes development files: '.$entry);
    }
}

$package = json_decode($archive->getFromName('composer.json'), true, flags: JSON_THROW_ON_ERROR);
$archive->close();
if (isset($package['version'])) {
    throw new RuntimeException('Published package versions must come from Git tags.');
}

// Assign a test-only version to install the unreleased archive through Composer.
$package['version'] = '0.1.0';
$package['dist'] = ['type' => 'zip', 'url' => $archivePath];
$consumer = [
    'name' => 'guarded-http/distribution-test',
    'type' => 'project',
    'require' => ['laravel/framework' => '^13.34.0', 'wtroiano/guarded-http' => '0.1.0'],
    'repositories' => [['type' => 'package', 'package' => $package]],
    'config' => ['allow-plugins' => false],
];

mkdir($directory.'/consumer/bootstrap/cache', 0777, true);
mkdir($directory.'/consumer/storage/logs', 0777, true);
file_put_contents($directory.'/consumer/composer.json', json_encode($consumer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
PHP

composer install --working-dir="$distribution_dir/consumer" --no-dev --no-interaction --prefer-dist --no-progress
composer check-platform-reqs --working-dir="$distribution_dir/consumer" --no-dev
php .github/scripts/verify-consumer.php "$distribution_dir/consumer"
