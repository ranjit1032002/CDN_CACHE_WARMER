#!/usr/bin/env php
<?php

/**
 * CDN Cache Warmer — CLI Runner
 *
 * Usage:
 *   php run.php <sitemap> [options]
 *
 * Arguments:
 *   <sitemap>               Path to a local sitemap XML file OR a remote URL.
 *                           e.g. /var/www/html/stocks-sitemap.xml
 *                           e.g. https://www.5paisa.com/sitemap.xml
 *
 * Options:
 *   --concurrency=N         Parallel requests per batch      (default: 10)
 *   --timeout=N             Per-request timeout in seconds   (default: 30)
 *   --connect-timeout=N     Connection timeout in seconds    (default: 10)
 *   --method=GET|HEAD       HTTP method (GET warms body)     (default: GET)
 *   --user-agent=STRING     Custom User-Agent header         (default: CdnCacheWarmer/1.0)
 *   --header=VALUE          Extra header, repeatable         (e.g. --header="X-Env: prod")
 *   --batch-delay=N         Microsecond delay between batches(default: 0)
 *   --log-file=PATH         Write CSV results to this file
 *   --no-ssl-verify         Disable SSL certificate verification
 *   --no-follow-redirects   Do not follow HTTP redirects
 *   --retry=N               Retry transport-error URLs N times (default: 1)
 *   --verbose               Print per-URL results to stdout
 *   --help                  Show this help message
 *
 * Examples:
 *   php run.php stocks-sitemap.xml --verbose
 *   php run.php stocks-sitemap.xml --concurrency=20 --log-file=/tmp/warm.csv
 *   php run.php https://www.5paisa.com/sitemap.xml --method=HEAD --verbose
 *   php run.php sitemap-index.xml --concurrency=15 --timeout=60 --log-file=results.csv
 */

// ── Enforce CLI-only execution ────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require_once __DIR__ . '/CdnCacheWarmer.php';

// ── Parse CLI arguments ───────────────────────────────────────────────────────
$args    = $argv;
array_shift($args); // remove script name

if (empty($args) || in_array('--help', $args, true) || in_array('-h', $args, true)) {
    printHelp();
    exit(0);
}

// First positional argument is the sitemap source.
$sitemapSource = null;
$options       = [];
$extraHeaders  = [];

foreach ($args as $arg) {
    if (str_starts_with($arg, '--')) {
        [$key, $val] = parseOption($arg);
        switch ($key) {
            case 'concurrency':
                $options['concurrency'] = (int) $val;
                break;
            case 'timeout':
                $options['timeout'] = (int) $val;
                break;
            case 'connect-timeout':
                $options['connect_timeout'] = (int) $val;
                break;
            case 'method':
                $options['method'] = strtoupper($val);
                break;
            case 'user-agent':
                $options['user_agent'] = $val;
                break;
            case 'header':
                $extraHeaders[] = $val;
                break;
            case 'batch-delay':
                $options['batch_delay_us'] = (int) $val;
                break;
            case 'log-file':
                $options['log_file'] = $val;
                break;
            case 'no-ssl-verify':
                $options['verify_ssl'] = false;
                break;
            case 'no-follow-redirects':
                $options['follow_redirects'] = false;
                break;
            case 'retry':
                $options['retry_on_error'] = (int) $val;
                break;
            case 'verbose':
                $options['verbose'] = true;
                break;
            default:
                fwrite(STDERR, "Unknown option: {$arg}\n");
                exit(1);
        }
    } elseif ($sitemapSource === null) {
        $sitemapSource = $arg;
    }
}

if ($sitemapSource === null) {
    fwrite(STDERR, "Error: No sitemap source provided.\n\n");
    printHelp();
    exit(1);
}

if (!empty($extraHeaders)) {
    $options['headers'] = $extraHeaders;
}

// ── Boot and run ──────────────────────────────────────────────────────────────
$warmer  = new CdnCacheWarmer($options);
$results = $warmer->warm($sitemapSource);
$warmer->printSummary();

// Exit with non-zero code if any cURL-level errors occurred.
$hasErrors = !empty(array_filter($results, fn($r) => !empty($r['error'])));
exit($hasErrors ? 1 : 0);

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Parses "--key=value" or "--flag" into [key, value].
 */
function parseOption(string $arg): array
{
    $stripped = ltrim($arg, '-');
    if (str_contains($stripped, '=')) {
        [$key, $val] = explode('=', $stripped, 2);
    } else {
        $key = $stripped;
        $val = true;
    }
    return [$key, $val];
}

function printHelp(): void
{
    $script = basename($_SERVER['argv'][0] ?? 'run.php');
    echo <<<HELP

CDN Cache Warmer
================
Hits every URL in a sitemap so that pages get cached at the CDN edge.

Usage:
  php {$script} <sitemap> [options]

Arguments:
  <sitemap>               Local file path or remote URL of a sitemap XML.
                          Supports <sitemapindex> files that reference child sitemaps.

Options:
  --concurrency=N         Parallel requests per batch      (default: 10)
  --timeout=N             Per-request timeout in seconds   (default: 30)
  --connect-timeout=N     Connection timeout in seconds    (default: 10)
  --method=GET|HEAD       HTTP method (GET warms full body)(default: GET)
  --user-agent=STRING     Custom User-Agent header
  --header=VALUE          Extra request header (repeatable)
  --batch-delay=N         Microseconds to sleep between batches (default: 0)
  --log-file=PATH         Write results to a CSV file
  --no-ssl-verify         Disable SSL certificate verification
  --no-follow-redirects   Do not follow HTTP redirects
  --retry=N               Retry failed requests N times    (default: 1)
  --verbose               Print per-URL status to stdout
  --help                  Show this help message

Examples:
  php {$script} /var/www/html/stocks-sitemap.xml --verbose
  php {$script} /var/www/html/sitemap-index.xml --concurrency=20 --log-file=/tmp/warm.csv
  php {$script} https://www.5paisa.com/sitemap.xml --method=HEAD --verbose
  php {$script} stocks-sitemap.xml --concurrency=15 --timeout=60 --no-ssl-verify

HELP;
}
