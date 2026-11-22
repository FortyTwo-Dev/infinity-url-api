<?php

declare(strict_types=1);

/**
 * Enforces full line coverage on the modules named by `docs/09-tests.md` §5.
 *
 * Usage: php scripts/check-coverage.php build/coverage.xml
 * Exit: 0 when every module is measured and fully covered, 1 otherwise.
 */
const REQUIRED_COVERAGE = [
    // Dedup key, the non-correlation promise.
    'app/Services/Analytics/DedupKey.php',
    'app/Support/Analytics/DedupWindow.php',
    // Encryption cast and blind index, the "email unreadable at rest" promise.
    'app/Casts/EncryptedWithHash.php',
    'app/Services/EmailHasher.php',
    // Slug generation and validation, the enumeration surface.
    'app/Services/SlugGenerator.php',
    'app/Services/SlugRules.php',
    // Retention, the purge promise.
    'app/Support/Analytics/RetentionWindow.php',
    // Redirect route, 95 % of the traffic.
    'app/Http/Controllers/RedirectController.php',
    'app/Services/ResolveLink.php',
    'app/Services/RedirectCache.php',
    'app/Support/RedirectResult.php',
];

const EXIT_OK = 0;

const EXIT_FAILURE = 1;

function normalisePath(string $path): string
{
    return str_replace('\\', '/', $path);
}

function findFile(SimpleXMLElement $document, string $required): ?SimpleXMLElement
{
    $suffix = '/'.normalisePath($required);

    foreach ($document->xpath('//file') ?: [] as $file) {
        $name = normalisePath((string) $file['name']);

        if (str_ends_with($name, $suffix)) {
            return $file;
        }
    }

    return null;
}

/**
 * @return array<int, int>
 */
function uncoveredLines(SimpleXMLElement $file): array
{
    $lines = [];

    foreach ($file->line as $line) {
        $type = (string) $line['type'];
        $count = (int) $line['count'];

        if ($count === 0 && ($type === 'stmt' || $type === 'method')) {
            $lines[] = (int) $line['num'];
        }
    }

    sort($lines);

    return $lines;
}

function isFullyCovered(SimpleXMLElement $file): bool
{
    $metrics = $file->metrics;

    if ($metrics === null) {
        return false;
    }

    return (int) $metrics['statements'] === (int) $metrics['coveredstatements'];
}

$report = $argv[1] ?? 'build/coverage.xml';

if (! is_file($report)) {
    fwrite(STDERR, 'Coverage report not found: '.$report.PHP_EOL);
    exit(EXIT_FAILURE);
}

$document = simplexml_load_file($report);

if ($document === false) {
    fwrite(STDERR, 'Unable to parse the coverage report: '.$report.PHP_EOL);
    exit(EXIT_FAILURE);
}

$failed = false;

foreach (REQUIRED_COVERAGE as $required) {
    $file = findFile($document, $required);

    if ($file === null) {
        fwrite(STDOUT, 'MISSING '.$required.' (absent from the coverage report)'.PHP_EOL);
        $failed = true;

        continue;
    }

    $uncovered = uncoveredLines($file);

    if ($uncovered !== [] || ! isFullyCovered($file)) {
        $detail = $uncovered === [] ? 'metrics disagree' : implode(', ', $uncovered);
        fwrite(STDOUT, 'FAIL    '.$required.' (uncovered lines: '.$detail.')'.PHP_EOL);
        $failed = true;

        continue;
    }

    fwrite(STDOUT, 'OK      '.$required.PHP_EOL);
}

exit($failed ? EXIT_FAILURE : EXIT_OK);
