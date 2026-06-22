<?php

/**
 * CdnCacheWarmer - Independent CDN Cache Warming Module
 *
 * Parses sitemap XML files (including sitemap index files) and hits every
 * listed URL so that responses get cached by the CDN (e.g., Akamai, CloudFront).
 *
 * Usage (programmatic):
 *   require_once 'CdnCacheWarmer.php';
 *   $warmer = new CdnCacheWarmer(['concurrency' => 20, 'verbose' => true]);
 *   $results = $warmer->warm('/path/to/sitemap.xml');
 *   // or: $warmer->warm('https://www.example.com/sitemap.xml');
 *   $warmer->printSummary();
 *
 * No external dependencies. Requires PHP 7.4+ with cURL and SimpleXML extensions.
 */
class CdnCacheWarmer
{
    /** @var array Default configuration */
    private const DEFAULTS = [
        // Number of simultaneous HTTP requests
        'concurrency'       => 10,
        // Per-request timeout in seconds
        'timeout'           => 30,
        // Connect timeout in seconds
        'connect_timeout'   => 10,
        // Microsecond delay between dispatching each batch (0 = no delay)
        'batch_delay_us'    => 0,
        // HTTP method to use. HEAD uses less bandwidth; GET warms full response body.
        'method'            => 'GET',
        // Custom User-Agent string
        'user_agent'        => 'CdnCacheWarmer/1.0 (+https://github.com/5paisa)',
        // Extra HTTP request headers  ['X-Forwarded-For: 1.2.3.4']
        'headers'           => [],
        // Follow HTTP redirects
        'follow_redirects'  => true,
        // Maximum redirects to follow
        'max_redirects'     => 5,
        // Verify SSL certificates (set false only for dev/staging)
        'verify_ssl'        => true,
        // Print detailed per-URL output
        'verbose'           => false,
        // File path to write a CSV log (null = no log file)
        'log_file'          => null,
        // Number of retry attempts for failed/error requests (non-2xx are NOT retried)
        'retry_on_error'    => 1,
        // HTTP status codes considered "successful" (anything else is flagged as warning)
        'success_codes'     => [200, 301, 302, 304],
    ];

    /** @var array Resolved configuration */
    private array $config;

    /** @var array Results keyed by URL */
    private array $results = [];

    /** @var array All URLs collected from all sitemaps */
    private array $allUrls = [];

    /** @var resource|null Log file handle */
    private $logHandle = null;

    /** @var float Script start time */
    private float $startTime;

    public function __construct(array $config = [])
    {
        $this->config = array_merge(self::DEFAULTS, $config);
        $this->startTime = microtime(true);

        if ($this->config['log_file']) {
            $this->logHandle = fopen($this->config['log_file'], 'w');
            if ($this->logHandle) {
                fputcsv($this->logHandle, ['url', 'status_code', 'response_time_ms', 'bytes', 'error', 'timestamp']);
            }
        }
    }

    public function __destruct()
    {
        if ($this->logHandle) {
            fclose($this->logHandle);
        }
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Main entry point. Accepts a local file path or a full HTTP/HTTPS URL.
     *
     * @param  string $sitemapSource  Local path or remote URL to a sitemap XML or sitemap index.
     * @return array  Results array keyed by URL.
     */
    public function warm(string $sitemapSource): array
    {
        $this->log("CDN Cache Warmer started", 'info');
        $this->log("Source: {$sitemapSource}", 'info');

        // Collect all leaf URLs from the sitemap (handles nested sitemap indexes).
        $this->allUrls = $this->collectUrls($sitemapSource);
        $total = count($this->allUrls);

        if ($total === 0) {
            $this->log("No URLs found in sitemap.", 'warn');
            return $this->results;
        }

        $this->log("Found {$total} URL(s). Starting warm-up with concurrency={$this->config['concurrency']}...", 'info');

        // Split into batches and dispatch concurrently.
        $batches = array_chunk($this->allUrls, $this->config['concurrency']);
        $batchCount = count($batches);
        $processed = 0;

        foreach ($batches as $batchIndex => $batch) {
            $this->fetchBatch($batch);
            $processed += count($batch);

            $pct = round(($processed / $total) * 100, 1);
            $this->log("Batch " . ($batchIndex + 1) . "/{$batchCount} done — {$processed}/{$total} ({$pct}%)", 'info');

            if ($this->config['batch_delay_us'] > 0) {
                usleep($this->config['batch_delay_us']);
            }
        }

        $this->log("Warm-up complete.", 'info');
        return $this->results;
    }

    /**
     * Returns the results array populated after warm() is called.
     * Each entry: ['status' => int, 'time_ms' => float, 'bytes' => int, 'error' => string]
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Prints a human-readable summary table to stdout.
     */
    public function printSummary(): void
    {
        $total     = count($this->results);
        $success   = 0;
        $warnings  = 0;
        $errors    = 0;
        $totalMs   = 0;

        foreach ($this->results as $res) {
            $totalMs += $res['time_ms'];
            if (!empty($res['error'])) {
                $errors++;
            } elseif (in_array($res['status'], $this->config['success_codes'])) {
                $success++;
            } else {
                $warnings++;
            }
        }

        $elapsed  = round(microtime(true) - $this->startTime, 2);
        $avgMs    = $total > 0 ? round($totalMs / $total, 1) : 0;

        $line = str_repeat('-', 60);
        echo PHP_EOL . $line . PHP_EOL;
        echo "  CDN CACHE WARMER — SUMMARY" . PHP_EOL;
        echo $line . PHP_EOL;
        printf("  %-20s %s%s", "Total URLs:",     $total,   PHP_EOL);
        printf("  %-20s %s%s", "Successful:",      $success, PHP_EOL);
        printf("  %-20s %s%s", "Warnings (non-2xx):", $warnings, PHP_EOL);
        printf("  %-20s %s%s", "Errors (curl):",  $errors,  PHP_EOL);
        printf("  %-20s %s ms%s", "Avg response:",  $avgMs,   PHP_EOL);
        printf("  %-20s %s s%s", "Total elapsed:",  $elapsed, PHP_EOL);
        if ($this->config['log_file']) {
            printf("  %-20s %s%s", "Log file:", $this->config['log_file'], PHP_EOL);
        }
        echo $line . PHP_EOL . PHP_EOL;
    }

    // -------------------------------------------------------------------------
    // Sitemap Parsing
    // -------------------------------------------------------------------------

    /**
     * Recursively collects page URLs from a sitemap or sitemap index.
     * Handles:
     *  - Standard <urlset> sitemaps
     *  - <sitemapindex> files that reference child sitemaps
     *
     * @param  string $source  Local file path or HTTP(S) URL.
     * @param  int    $depth   Current recursion depth (guard against infinite loops).
     * @return array           Flat list of page URLs.
     */
    private function collectUrls(string $source, int $depth = 0): array
    {
        if ($depth > 5) {
            $this->log("Max sitemap recursion depth reached for: {$source}", 'warn');
            return [];
        }

        $xml = $this->loadXml($source);
        if ($xml === null) {
            return [];
        }

        $urls = [];

        // Sitemap index — recurse into each child sitemap.
        if (isset($xml->sitemap)) {
            $this->log("Sitemap index detected: {$source} (" . count($xml->sitemap) . " child sitemaps)", 'info');
            foreach ($xml->sitemap as $sitemapEntry) {
                $childLoc = trim((string) $sitemapEntry->loc);
                if ($childLoc) {
                    $childUrls = $this->collectUrls($childLoc, $depth + 1);
                    $urls      = array_merge($urls, $childUrls);
                }
            }
            return $urls;
        }

        // Standard urlset — extract <loc> from each <url>.
        if (isset($xml->url)) {
            foreach ($xml->url as $urlEntry) {
                $loc = trim((string) $urlEntry->loc);
                if ($loc) {
                    $urls[] = $loc;
                }
            }
            $this->log("Parsed " . count($urls) . " URL(s) from: {$source}", 'info');
            return $urls;
        }

        $this->log("No <url> or <sitemap> elements found in: {$source}", 'warn');
        return [];
    }

    /**
     * Loads and parses an XML source (file path or URL).
     *
     * @return \SimpleXMLElement|null
     */
    private function loadXml(string $source): ?\SimpleXMLElement
    {
        $isRemote = preg_match('#^https?://#i', $source);

        if ($isRemote) {
            $content = $this->fetchRaw($source);
        } else {
            if (!file_exists($source)) {
                $this->log("Sitemap file not found: {$source}", 'error');
                return null;
            }
            $content = file_get_contents($source);
        }

        if (empty($content)) {
            $this->log("Empty response for sitemap: {$source}", 'error');
            return null;
        }

        // Suppress XML parse warnings; handle them manually.
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $msg = implode('; ', array_map(fn($e) => trim($e->message), $errors));
            $this->log("XML parse error for {$source}: {$msg}", 'error');
            return null;
        }

        return $xml;
    }

    /**
     * Fetches a remote URL and returns the raw response body (used for sitemaps).
     */
    private function fetchRaw(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->config['timeout'],
            CURLOPT_CONNECTTIMEOUT => $this->config['connect_timeout'],
            CURLOPT_FOLLOWLOCATION => $this->config['follow_redirects'],
            CURLOPT_MAXREDIRS      => $this->config['max_redirects'],
            CURLOPT_SSL_VERIFYPEER => $this->config['verify_ssl'],
            CURLOPT_SSL_VERIFYHOST => $this->config['verify_ssl'] ? 2 : 0,
            CURLOPT_USERAGENT      => $this->config['user_agent'],
            CURLOPT_ENCODING       => 'gzip, deflate',
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);

        if ($err) {
            $this->log("cURL error fetching sitemap {$url}: {$err}", 'error');
            return null;
        }

        return $body ?: null;
    }

    // -------------------------------------------------------------------------
    // Concurrent URL Fetching
    // -------------------------------------------------------------------------

    /**
     * Dispatches a batch of URLs concurrently using curl_multi.
     * Respects the configured retry_on_error setting.
     *
     * @param array $urls Flat list of page URLs.
     */
    private function fetchBatch(array $urls): void
    {
        $multiHandle = curl_multi_init();
        $handles     = [];

        foreach ($urls as $url) {
            $ch = $this->buildCurlHandle($url);
            curl_multi_add_handle($multiHandle, $ch);
            $handles[(int)$ch] = ['handle' => $ch, 'url' => $url];
        }

        // Execute all handles.
        $running = null;
        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($running) {
                curl_multi_select($multiHandle, 0.5);
            }
        } while ($running > 0 && $status === CURLM_OK);

        // Collect results.
        foreach ($handles as $data) {
            $ch  = $data['handle'];
            $url = $data['url'];

            $error   = curl_error($ch);
            $info    = curl_getinfo($ch);
            $status  = (int) ($info['http_code'] ?? 0);
            $timeMs  = round(($info['total_time'] ?? 0) * 1000, 2);
            $bytes   = (int) ($info['size_download'] ?? 0);

            // Retry on curl-level transport errors (not HTTP error codes).
            if ($error && $this->config['retry_on_error'] > 0) {
                $retried = $this->retryRequest($url, $this->config['retry_on_error']);
                if ($retried !== null) {
                    $error  = $retried['error'];
                    $status = $retried['status'];
                    $timeMs = $retried['time_ms'];
                    $bytes  = $retried['bytes'];
                }
            }

            $this->recordResult($url, $status, $timeMs, $bytes, $error);

            curl_multi_remove_handle($multiHandle, $ch);
        }

        curl_multi_close($multiHandle);
    }

    /**
     * Retries a failed request using a simple sequential curl call.
     *
     * @return array|null Result array or null if we should keep the original error.
     */
    private function retryRequest(string $url, int $attempts): ?array
    {
        for ($i = 1; $i <= $attempts; $i++) {
            $this->log("  Retry {$i}/{$attempts}: {$url}", 'warn');
            usleep(500_000); // 500 ms back-off

            $ch     = $this->buildCurlHandle($url);
            curl_exec($ch);
            $error  = curl_error($ch);
            $info   = curl_getinfo($ch);

            if (!$error) {
                return [
                    'error'   => '',
                    'status'  => (int) ($info['http_code'] ?? 0),
                    'time_ms' => round(($info['total_time'] ?? 0) * 1000, 2),
                    'bytes'   => (int) ($info['size_download'] ?? 0),
                ];
            }
        }
        return null;
    }

    /**
     * Builds a cURL handle for a page URL using the configured settings.
     *
     * @return resource cURL handle.
     */
    private function buildCurlHandle(string $url)
    {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->config['timeout'],
            CURLOPT_CONNECTTIMEOUT => $this->config['connect_timeout'],
            CURLOPT_FOLLOWLOCATION => $this->config['follow_redirects'],
            CURLOPT_MAXREDIRS      => $this->config['max_redirects'],
            CURLOPT_SSL_VERIFYPEER => $this->config['verify_ssl'],
            CURLOPT_SSL_VERIFYHOST => $this->config['verify_ssl'] ? 2 : 0,
            CURLOPT_USERAGENT      => $this->config['user_agent'],
            CURLOPT_ENCODING       => 'gzip, deflate',
        ];

        if (strtoupper($this->config['method']) === 'HEAD') {
            $opts[CURLOPT_NOBODY]        = true;
            $opts[CURLOPT_HEADER]        = true;
        }

        if (!empty($this->config['headers'])) {
            $opts[CURLOPT_HTTPHEADER] = $this->config['headers'];
        }

        curl_setopt_array($ch, $opts);
        return $ch;
    }

    // -------------------------------------------------------------------------
    // Result Recording & Logging
    // -------------------------------------------------------------------------

    /**
     * Records a single URL result, logs it, and writes to CSV if configured.
     */
    private function recordResult(string $url, int $status, float $timeMs, int $bytes, string $error): void
    {
        $result = [
            'status'   => $status,
            'time_ms'  => $timeMs,
            'bytes'    => $bytes,
            'error'    => $error,
            'ts'       => date('Y-m-d H:i:s'),
        ];

        $this->results[$url] = $result;

        // Verbose console output.
        if ($this->config['verbose']) {
            $statusLabel = $error ? "ERR" : $status;
            $flag = $error
                ? "\033[31mFAIL\033[0m"
                : (in_array($status, $this->config['success_codes']) ? "\033[32m OK \033[0m" : "\033[33mWARN\033[0m");

            printf(
                "  [%s] [%s] %5.0fms  %s%s",
                $flag,
                str_pad((string)$statusLabel, 3),
                $timeMs,
                $url,
                PHP_EOL
            );
            if ($error) {
                printf("         Error: %s%s", $error, PHP_EOL);
            }
        }

        // CSV log file.
        if ($this->logHandle) {
            fputcsv($this->logHandle, [
                $url,
                $status,
                $timeMs,
                $bytes,
                $error,
                $result['ts'],
            ]);
        }
    }

    /**
     * Prints a timestamped log message to stdout.
     */
    private function log(string $message, string $level = 'info'): void
    {
        $colors = [
            'info'  => "\033[36m",   // cyan
            'warn'  => "\033[33m",   // yellow
            'error' => "\033[31m",   // red
        ];
        $reset = "\033[0m";
        $color = $colors[$level] ?? '';

        $ts     = date('H:i:s');
        $prefix = strtoupper($level);
        $line   = sprintf("[%s] [%s%s%s] %s%s", $ts, $color, $prefix, $reset, $message, PHP_EOL);

        // Always print info/warn/error to stdout (suppress verbose-only messages via verbose flag).
        echo $line;
    }
}
