<?php

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/check-phpunit-gate.php <text-log> <junit-log> [<allowlist-path>]\n");
    exit(2);
}

$textLog       = $argv[1];
$junitLog      = $argv[2];
$allowlistPath = $argv[3] ?? dirname(__DIR__) . '/scripts/allowed-skipped-tests.txt';

foreach ([$textLog, $junitLog, $allowlistPath] as $path) {
    if (! is_readable($path)) {
        fwrite(STDERR, "Cannot read file: {$path}\n");
        exit(2);
    }
}

$allowedSkips = [];
$lines        = file($allowlistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    $allowedSkips[$line] = true;
}

$expectedSkippedCount = count($allowedSkips);

$text = file_get_contents($textLog);
if ($text === false) {
    fwrite(STDERR, "Failed to read {$textLog}\n");
    exit(2);
}

$failures = [];

foreach (preg_split('/\R/', $text) as $line) {
    if ($line === '') {
        continue;
    }
    if (preg_match('/^(PHP )?(Warning|Deprecated|Notice):/', $line)) {
        $failures[] = "Disallowed output line: {$line}";
    }
}

foreach (['FAILURES!', 'ERRORS!', 'No tests executed!'] as $needle) {
    if (str_contains($text, $needle)) {
        $failures[] = "Summary contains {$needle}";
    }
}

$badCounterPattern = '/\b(Failures|Errors|Warnings|Deprecations|PHPUnit Deprecations|Notices|Risky):\s*[1-9]/';
if (preg_match($badCounterPattern, $text, $counterMatch)) {
    $failures[] = "Summary reports a non-zero issue counter: {$counterMatch[0]}";
}

$summaryOk = false;
foreach (preg_split('/\R/', $text) as $line) {
    if (preg_match('/^OK \([0-9]+ tests?, [0-9]+ assertions?\)$/', $line)) {
        $summaryOk = true;
        break;
    }
    if ($line === 'OK, but some tests were skipped!') {
        $summaryOk = true;
        break;
    }
}

if (! $summaryOk) {
    $failures[] = 'Missing strict OK summary line.';
}

$testsLine = null;
foreach (preg_split('/\R/', $text) as $line) {
    if (preg_match('/^Tests: [0-9]+, Assertions: [0-9]+(, Skipped: [0-9]+)?\.$/', $line)) {
        $testsLine = $line;
        break;
    }
}

if ($testsLine === null) {
    $failures[] = 'Missing Tests: ... summary line.';
} elseif (! preg_match(
    '/^Tests: [0-9]+, Assertions: [0-9]+, Skipped: ' . $expectedSkippedCount . '\.$/',
    $testsLine
)) {
    $failures[] = sprintf(
        'Tests summary line does not match expected skip count %d: %s',
        $expectedSkippedCount,
        $testsLine
    );
}

$xml = file_get_contents($junitLog);
if ($xml === false) {
    fwrite(STDERR, "Failed to read {$junitLog}\n");
    exit(2);
}

$document = new DOMDocument();
if (! @$document->loadXML($xml)) {
    $failures[] = 'JUnit log is not valid XML.';
} else {
    $xpath = new DOMXPath($document);
    $skippedNodes = $xpath->query('//testcase/skipped');
    $seenSkips    = [];
    if ($skippedNodes !== false) {
        foreach ($skippedNodes as $skipped) {
            $testcase = $skipped->parentNode;
            if (! $testcase instanceof DOMElement) {
                continue;
            }
            $class  = $testcase->getAttribute('class');
            $method = $testcase->getAttribute('name');
            $id     = $class . '::' . $method;
            $seenSkips[$id] = true;
        }
    }

    foreach (array_keys($seenSkips) as $id) {
        if (! isset($allowedSkips[$id])) {
            $failures[] = "Unexpected skipped test: {$id}";
        }
    }

    foreach (array_keys($allowedSkips) as $id) {
        if (! isset($seenSkips[$id])) {
            $failures[] = "Allowed skip not seen in JUnit log: {$id}";
        }
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, $failure . "\n");
    }
    exit(1);
}

fwrite(STDOUT, "PHPUnit gate passed.\n");
exit(0);
