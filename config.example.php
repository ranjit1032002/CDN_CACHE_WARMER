<?php

/**
 * CDN Cache Warmer — Example Configuration
 *
 * Copy this file to config.php and adjust values as needed.
 * Include it from your own PHP script to use the warmer programmatically.
 *
 * Usage in a custom script:
 *   require_once __DIR__ . '/CdnCacheWarmer.php';
 *   $config = require __DIR__ . '/config.php';
 *   $warmer = new CdnCacheWarmer($config);
 *   $warmer->warm('/path/to/sitemap.xml');
 *   $warmer->printSummary();
 */

return [

    // ── Concurrency & timing ──────────────────────────────────────────────────

    // Number of simultaneous HTTP requests dispatched per batch.
    // Increase for faster warming; decrease to avoid overwhelming the origin.
    'concurrency' => 10,

    // Maximum seconds to wait for a single response.
    'timeout' => 30,

    // Maximum seconds to wait for the TCP connection to establish.
    'connect_timeout' => 10,

    // Microseconds to sleep between each batch dispatch (0 = no delay).
    // Useful for rate-limiting: 1_000_000 = 1 second between batches.
    'batch_delay_us' => 0,

    // ── HTTP behaviour ────────────────────────────────────────────────────────

    // HTTP method used to warm each page.
    //   'GET'  — downloads the full response body (best cache population).
    //   'HEAD' — only headers (lighter; does NOT populate CDN response cache on all providers).
    'method' => 'GET',

    // User-Agent string sent with every request.
    'user_agent' => 'CdnCacheWarmer/1.0 (+cdn-warmer)',

    // Additional HTTP headers to send with every request.
    // Useful for Akamai Pragma headers, auth tokens, environment flags, etc.
    // Example: force Akamai to re-fetch from origin:
    //   'Pragma: akamai-x-cache-on, akamai-x-cache-remote-on'
    'headers' => [
        // 'Pragma: akamai-x-cache-on',
        // 'X-Forwarded-For: 1.2.3.4',
    ],

    // Follow HTTP 3xx redirects automatically.
    'follow_redirects' => true,

    // Maximum number of redirects to follow before giving up.
    'max_redirects' => 5,

    // Verify SSL/TLS certificates. Set to false ONLY on dev/staging environments.
    'verify_ssl' => true,

    // ── Retry behaviour ───────────────────────────────────────────────────────

    // Number of times to retry a request that failed at the transport layer
    // (cURL error, connection refused, timeout). HTTP error codes are NOT retried.
    'retry_on_error' => 1,

    // ── Output & logging ──────────────────────────────────────────────────────

    // Print per-URL status lines to stdout (useful for interactive debugging).
    'verbose' => false,

    // Absolute or relative path to write a CSV results log.
    // Set to null to disable file logging.
    // CSV columns: url, status_code, response_time_ms, bytes, error, timestamp
    'log_file' => null,
    // 'log_file' => __DIR__ . '/warm-' . date('Ymd-His') . '.csv',

    // HTTP status codes that are treated as "successful" in the summary.
    // URLs returning other codes are flagged as warnings (not errors).
    'success_codes' => [200, 301, 302, 304],
];
