<?php

declare(strict_types=1);

/**
 * Fail if cluster packages are not installed at expected index SHAs and aliases,
 * or if unexpected Training-Datasmith packages are installed.
 */

$expected = [
    'psr/cache' => [
        'alias' => '3.0.99',
        'reference' => '46880c803d3ff3e5826ba4bbf09d67c46caf0a8a',
    ],
    'psr/log' => [
        'alias' => '3.0.2',
        'reference' => 'bde874f9b44efec5901a6783185c050a3855b0f0',
    ],
    'psr/simple-cache' => [
        'alias' => '3.0.0',
        'reference' => 'd03ac6ca8193e8b800f667a63745f1db7806f412',
    ],
];

$allowedOrgPackages = array_keys($expected);
$vendorDir          = dirname(__DIR__) . '/vendor';
$installedPhpPath   = $vendorDir . '/composer/installed.php';
$installedJsonPath  = $vendorDir . '/composer/installed.json';

if (! is_readable($installedPhpPath)) {
    fwrite(STDERR, "check-cluster-pins: vendor/composer/installed.php not found; run composer update first.\n");
    exit(1);
}

if (! is_readable($installedJsonPath)) {
    fwrite(STDERR, "check-cluster-pins: vendor/composer/installed.json not found; run composer update first.\n");
    exit(1);
}

/** @var array{versions?: array<string, array{reference?: string, aliases?: list<string>}>} $installedPhp */
$installedPhp = require $installedPhpPath;
$versions     = $installedPhp['versions'] ?? [];

$installedJsonRaw = file_get_contents($installedJsonPath);
if ($installedJsonRaw === false) {
    fwrite(STDERR, "check-cluster-pins: could not read installed.json.\n");
    exit(1);
}

/** @var array{packages?: list<array<string, mixed>>, 'packages-dev'?: list<array<string, mixed>>} $installedJson */
$installedJson = json_decode($installedJsonRaw, true, 512, JSON_THROW_ON_ERROR);
$jsonPackages    = array_merge($installedJson['packages'] ?? [], $installedJson['packages-dev'] ?? []);

/** @var array<string, string> $jsonReferences */
$jsonReferences = [];
foreach ($jsonPackages as $package) {
    if (! isset($package['name'], $package['source']['reference'])) {
        continue;
    }
    $jsonReferences[$package['name']] = strtolower((string) $package['source']['reference']);
}

/** @var array<string, string> $orgPackages */
$orgPackages = [];
foreach ($jsonPackages as $package) {
    if (! isset($package['name'], $package['source']['url'])) {
        continue;
    }
    $url = (string) $package['source']['url'];
    if (stripos($url, 'github.com/Training-Datasmith/') === false
        && stripos($url, 'github.com/training-datasmith/') === false) {
        continue;
    }
    $orgPackages[$package['name']] = $url;
}

foreach ($orgPackages as $name => $url) {
    if (! in_array($name, $allowedOrgPackages, true)) {
        fwrite(STDERR, sprintf(
            "check-cluster-pins: unexpected Training-Datasmith package installed: %s (%s)\n",
            $name,
            $url
        ));
        exit(1);
    }
}

$failed = false;

foreach ($expected as $name => $want) {
    if (! isset($versions[$name])) {
        fwrite(STDERR, sprintf("check-cluster-pins: %s is not installed.\n", $name));
        $failed = true;
        continue;
    }

    $pkg     = $versions[$name];
    $aliases = $pkg['aliases'] ?? [];
    if (! in_array($want['alias'], $aliases, true)) {
        fwrite(STDERR, sprintf(
            "check-cluster-pins: %s alias mismatch (expected %s in aliases %s).\n",
            $name,
            $want['alias'],
            json_encode($aliases)
        ));
        $failed = true;
    }

    $referencePhp = $pkg['reference'] ?? null;
    if (! is_string($referencePhp) || $referencePhp === '') {
        fwrite(STDERR, sprintf("check-cluster-pins: %s has no reference in installed.php.\n", $name));
        $failed = true;
    } elseif (! hash_equals($want['reference'], $referencePhp)) {
        fwrite(STDERR, sprintf(
            "check-cluster-pins: %s installed.php reference mismatch (expected %s, got %s).\n",
            $name,
            $want['reference'],
            $referencePhp
        ));
        $failed = true;
    }

    if (! isset($jsonReferences[$name])) {
        fwrite(STDERR, sprintf("check-cluster-pins: %s is missing from installed.json.\n", $name));
        $failed = true;
        continue;
    }

    $referenceJson = $jsonReferences[$name];
    if (! hash_equals(strtolower($want['reference']), $referenceJson)) {
        fwrite(STDERR, sprintf(
            "check-cluster-pins: %s installed.json source.reference mismatch (expected %s, got %s).\n",
            $name,
            strtolower($want['reference']),
            $referenceJson
        ));
        $failed = true;
    }
}

if ($failed) {
    exit(1);
}

// Wrong-SHA demo: installed.php reference only.
$phpVersionsBroken = $versions;
foreach ($phpVersionsBroken as $pkgName => $meta) {
    if (! isset($expected[$pkgName])) {
        continue;
    }
    $ref = $meta['reference'] ?? '';
    if (! is_string($ref) || strlen($ref) < 1) {
        continue;
    }
    $last          = $ref[strlen($ref) - 1];
    $phpVersionsBroken[$pkgName]['reference'] = substr($ref, 0, -1) . ($last === 'a' ? 'b' : 'a');
    break;
}
$phpDemoFailed = verifyInstalledPhpVersions($phpVersionsBroken, $expected);
if (! $phpDemoFailed) {
    fwrite(STDERR, "check-cluster-pins: wrong-SHA demo did not fail for installed.php reference.\n");
    exit(1);
}
fwrite(STDOUT, "check-cluster-pins: wrong-SHA demo failed as expected for installed.php reference\n");

// Wrong-SHA demo: installed.json source.reference only.
$jsonReferencesBroken = $jsonReferences;
foreach ($jsonReferencesBroken as $pkgName => $ref) {
    if (! isset($expected[$pkgName])) {
        continue;
    }
    $last                           = $ref[strlen($ref) - 1];
    $jsonReferencesBroken[$pkgName] = substr($ref, 0, -1) . ($last === 'a' ? 'b' : 'a');
    break;
}
$jsonDemoFailed = verifyInstalledJsonReferences($jsonReferencesBroken, $expected);
if (! $jsonDemoFailed) {
    fwrite(STDERR, "check-cluster-pins: wrong-SHA demo did not fail for installed.json source.reference.\n");
    exit(1);
}
fwrite(STDOUT, "check-cluster-pins: wrong-SHA demo failed as expected for installed.json source.reference\n");

fwrite(STDOUT, "check-cluster-pins: all cluster packages match expected SHAs and aliases.\n");
exit(0);

/**
 * @param array<string, array{reference?: string, aliases?: list<string>}> $versionsMap
 * @param array<string, array{alias: string, reference: string}> $expectedMap
 */
function verifyInstalledPhpVersions(array $versionsMap, array $expectedMap): bool
{
    foreach ($expectedMap as $name => $want) {
        if (! isset($versionsMap[$name])) {
            return true;
        }
        $reference = $versionsMap[$name]['reference'] ?? '';
        if (! is_string($reference) || ! hash_equals($want['reference'], $reference)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, string> $referencesMap
 * @param array<string, array{alias: string, reference: string}> $expectedMap
 */
function verifyInstalledJsonReferences(array $referencesMap, array $expectedMap): bool
{
    foreach ($expectedMap as $name => $want) {
        if (! isset($referencesMap[$name])) {
            return true;
        }
        if (! hash_equals(strtolower($want['reference']), $referencesMap[$name])) {
            return true;
        }
    }

    return false;
}
