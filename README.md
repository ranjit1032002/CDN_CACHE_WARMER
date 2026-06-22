# CDN Cache Warmer

An independent PHP module that reads a sitemap XML file and hits every listed URL so that pages get cached at the CDN edge (Akamai, CloudFront, Fastly, etc.).

No framework dependency. Requires PHP 7.4+ with the `curl` and `simplexml` extensions.

---

## Files

```
cdn_cache_warmer/
├── CdnCacheWarmer.php    # Core class (include this in your own scripts)
├── run.php               # CLI entry point
├── config.example.php    # Annotated config template for programmatic use
└── README.md
```

---

## Quick Start

```bash
# From the webroot (/website_5p/web)
php cdn_cache_warmer/run.php stocks-sitemap.xml --verbose
```

---

## CLI Usage

```
php cdn_cache_warmer/run.php <sitemap> [options]
```

### Arguments

| Argument | Description |
|---|---|
| `<sitemap>` | Local file path **or** remote HTTP(S) URL to a sitemap XML. Supports both `<urlset>` and `<sitemapindex>` formats. |

### Options

| Option | Default | Description |
|---|---|---|
| `--concurrency=N` | `10` | Number of parallel HTTP requests per batch |
| `--timeout=N` | `30` | Per-request timeout in seconds |
| `--connect-timeout=N` | `10` | TCP connection timeout in seconds |
| `--method=GET\|HEAD` | `GET` | HTTP method. `GET` downloads the full response body (recommended for CDN warming). `HEAD` is lighter but may not populate the cache on all CDN providers. |
| `--user-agent=STRING` | `CdnCacheWarmer/1.0` | Custom User-Agent header |
| `--header=VALUE` | — | Extra request header. Repeatable. e.g. `--header="X-Env: prod"` |
| `--batch-delay=N` | `0` | Microseconds to sleep between batches. Use to rate-limit requests to origin. |
| `--log-file=PATH` | — | Write a CSV results log to this path |
| `--no-ssl-verify` | — | Disable SSL certificate verification (dev/staging only) |
| `--no-follow-redirects` | — | Do not follow HTTP 3xx redirects |
| `--retry=N` | `1` | Retry count for transport-level errors (cURL failures). HTTP non-2xx responses are **not** retried. |
| `--verbose` | — | Print per-URL status, response time, and errors to stdout |
| `--help` | — | Show help message |

---

## Examples

### Warm a local sitemap file
```bash
php cdn_cache_warmer/run.php stocks-sitemap.xml --verbose
```

### Higher concurrency with a CSV log
```bash
php cdn_cache_warmer/run.php stocks-sitemap.xml \
  --concurrency=20 \
  --log-file=/tmp/warm-stocks.csv
```

### Warm via a remote URL
```bash
php cdn_cache_warmer/run.php https://www.5paisa.com/sitemap.xml \
  --concurrency=15 \
  --verbose
```

### Sitemap index (auto-walks all child sitemaps)
```bash
php cdn_cache_warmer/run.php sitemap-index.xml \
  --concurrency=15 \
  --timeout=60 \
  --log-file=/tmp/warm-all.csv
```

### Warm all project sitemaps in a loop
```bash
for f in stocks-sitemap.xml articles-sitemap.xml derivatives-sitemap.xml \
          etf-sitemap.xml mutual-funds-scheme-sitemap.xml \
          news-sitemap.xml ipo-sitemap-test.xml; do
  echo "=== Warming $f ==="
  php cdn_cache_warmer/run.php "$f" \
    --concurrency=15 \
    --log-file="/tmp/warm-${f}.csv"
done
```

### Akamai — force edge re-fetch with Pragma headers
```bash
php cdn_cache_warmer/run.php stocks-sitemap.xml \
  --header="Pragma: akamai-x-cache-on, akamai-x-cache-remote-on" \
  --concurrency=10 \
  --verbose
```

### Use HEAD to reduce bandwidth (metadata warm only)
```bash
php cdn_cache_warmer/run.php articles-sitemap.xml \
  --method=HEAD \
  --concurrency=30
```

---

## Programmatic Usage

Include `CdnCacheWarmer.php` directly in any PHP script — no autoloader needed.

```php
require_once __DIR__ . '/cdn_cache_warmer/CdnCacheWarmer.php';

$warmer = new CdnCacheWarmer([
    'concurrency' => 20,
    'timeout'     => 45,
    'verbose'     => true,
    'log_file'    => '/tmp/warm-' . date('Ymd') . '.csv',
]);

// Local file
$warmer->warm($_SERVER['DOCUMENT_ROOT'] . '/stocks-sitemap.xml');

// OR remote URL
// $warmer->warm('https://www.5paisa.com/sitemap.xml');

$warmer->printSummary();

// Access raw results if needed
$results = $warmer->getResults();
// $results['https://www.5paisa.com/stocks/tcs-share-price']
// => ['status' => 200, 'time_ms' => 312.5, 'bytes' => 48200, 'error' => '', 'ts' => '2026-06-22 02:00:01']
```

Copy `config.example.php` to `config.php` and use it to keep settings out of your scripts:

```php
require_once __DIR__ . '/cdn_cache_warmer/CdnCacheWarmer.php';
$config = require __DIR__ . '/cdn_cache_warmer/config.php';

$warmer = new CdnCacheWarmer($config);
$warmer->warm('stocks-sitemap.xml');
$warmer->printSummary();
```

---

## Cron Integration

Add to crontab (`crontab -e`) to warm the cache automatically after sitemaps are regenerated:

```cron
# Warm CDN cache daily at 2 AM
0 2 * * * cd /var/www/html && php cdn_cache_warmer/run.php stocks-sitemap.xml --concurrency=20 --log-file=/tmp/cdn-warm-stocks.csv >> /var/log/cdn-warmer.log 2>&1
```

Or call it at the end of an existing sitemap cron script:

```php
// At the bottom of cron/stock-sitemap.php, after writing the XML file:
require_once __DIR__ . '/../cdn_cache_warmer/CdnCacheWarmer.php';
$warmer = new CdnCacheWarmer(['concurrency' => 15, 'timeout' => 30]);
$warmer->warm($_SERVER['DOCUMENT_ROOT'] . '/stocks-sitemap.xml');
$warmer->printSummary();
```

---

## Output

### Console (with `--verbose`)
```
[02:00:01] [INFO] CDN Cache Warmer started
[02:00:01] [INFO] Source: stocks-sitemap.xml
[02:00:01] [INFO] Parsed 4821 URL(s) from: stocks-sitemap.xml
[02:00:01] [INFO] Found 4821 URL(s). Starting warm-up with concurrency=20...
  [ OK ] [200]   312ms  https://www.5paisa.com/stocks/tcs-share-price
  [ OK ] [200]   287ms  https://www.5paisa.com/stocks/infy-share-price
  [WARN] [404]   198ms  https://www.5paisa.com/stocks/old-delisted-stock
  [FAIL] [ERR]   ---    https://www.5paisa.com/stocks/timeout-example
         Error: Operation timed out after 30000 milliseconds
[02:00:45] [INFO] Warm-up complete.
```

### Summary
```
------------------------------------------------------------
  CDN CACHE WARMER — SUMMARY
------------------------------------------------------------
  Total URLs:          4821
  Successful:          4818
  Warnings (non-2xx):  2
  Errors (curl):       1
  Avg response:        294.3 ms
  Total elapsed:       48.7 s
  Log file:            /tmp/warm-stocks.csv
------------------------------------------------------------
```

### CSV log file columns
| Column | Description |
|---|---|
| `url` | Full page URL |
| `status_code` | HTTP status (0 = curl error) |
| `response_time_ms` | Total request time in milliseconds |
| `bytes` | Response body size in bytes |
| `error` | cURL error message (empty if none) |
| `timestamp` | Date/time the request completed |

---

## Requirements

- PHP 7.4 or higher
- `curl` extension enabled
- `simplexml` extension enabled (standard in most PHP installs)

---

## Exit Codes

| Code | Meaning |
|---|---|
| `0` | All requests completed without cURL-level errors |
| `1` | One or more cURL transport errors occurred (after retries) |
