<?php
/**
 * CDN Cache Warmer — Web UI
 *
 * Upload a sitemap.xml (or provide a URL) and warm all listed URLs.
 * Results stream to the browser in real time.
 */

// ── Security: block CLI calls ─────────────────────────────────────────────────
if (PHP_SAPI === 'cli') {
    exit("Use run.php for CLI usage.\n");
}

// ── Authentication config ─────────────────────────────────────────────────────
// Set credentials via environment variables (recommended) or change defaults here.
define('AUTH_USER',     getenv('WARMER_USER')     ?: 'ranjit');
define('AUTH_PASSWORD', getenv('WARMER_PASSWORD') ?: 'Ranjit@9062');
define('SESSION_NAME',  'cdnwarmer_sess');

// ── Session-based auth ────────────────────────────────────────────────────────
session_name(SESSION_NAME);
session_start();

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /');
    exit;
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    if ($user === AUTH_USER && hash_equals(AUTH_PASSWORD, $pass)) {
        session_regenerate_id(true);
        $_SESSION['authed'] = true;
        header('Location: /');
        exit;
    } else {
        $loginError = 'Invalid username or password.';
    }
}

// Gate: show login page if not authenticated
if (empty($_SESSION['authed'])) {
    showLoginPage($loginError ?? null);
    exit;
}

define('UPLOAD_TMP_DIR', sys_get_temp_dir());

// ── Handle warm request (AJAX/streaming) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'warm') {
    streamWarmResults();
    exit;
}

// ── Login page renderer ───────────────────────────────────────────────────────
function showLoginPage(?string $error): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CDN Cache Warmer — Login</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg: #0f1117; --surface: #1a1d27; --border: #2e3147;
    --accent: #6c63ff; --accent-h: #8b85ff; --text: #e2e8f0;
    --muted: #8892a4; --error: #f87171; --radius: 10px;
  }
  body {
    background: var(--bg); color: var(--text);
    font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
    min-height: 100vh; display: flex; align-items: center; justify-content: center;
  }
  .login-box {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 2.5rem 2rem; width: 100%; max-width: 380px;
  }
  h1 {
    font-size: 1.4rem; font-weight: 700; margin-bottom: .25rem;
    background: linear-gradient(90deg, #6c63ff, #38bdf8);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
  }
  .subtitle { color: var(--muted); font-size: .85rem; margin-bottom: 1.75rem; }
  .field { display: flex; flex-direction: column; gap: .4rem; margin-bottom: 1rem; }
  label { font-size: .78rem; font-weight: 500; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
  input[type=text], input[type=password] {
    background: var(--bg); border: 1px solid var(--border); border-radius: 6px;
    color: var(--text); padding: .55rem .75rem; font-size: .9rem; width: 100%;
    transition: border-color .15s;
  }
  input:focus { outline: none; border-color: var(--accent); }
  .btn {
    width: 100%; background: var(--accent); color: #fff; border: none;
    border-radius: 7px; padding: .7rem; font-size: .95rem; font-weight: 600;
    cursor: pointer; margin-top: .5rem; transition: background .15s;
  }
  .btn:hover { background: var(--accent-h); }
  .error {
    background: rgba(248,113,113,.1); border: 1px solid rgba(248,113,113,.3);
    color: var(--error); border-radius: 6px; padding: .6rem .85rem;
    font-size: .85rem; margin-bottom: 1rem;
  }
</style>
</head>
<body>
<div class="login-box">
  <h1>CDN Cache Warmer</h1>
  <p class="subtitle">Sign in to continue</p>
  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="POST">
    <input type="hidden" name="action" value="login">
    <div class="field">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" autofocus autocomplete="username" required>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required>
    </div>
    <button type="submit" class="btn">Sign In</button>
  </form>
</div>
</body>
</html>
    <?php
}

/**
 * Emits a single Server-Sent Event line to the browser.
 */
function sendSseEvent(string $event, string $data): void
{
    echo "event: {$event}\n";
    foreach (explode("\n", $data) as $line) {
        echo "data: {$line}\n";
    }
    echo "\n";
    flush();
}

/**
 * Streams cache-warming output to the browser as Server-Sent Events (SSE).
 * Runs run.php as a subprocess so its real-time echo output pipes directly.
 */
function streamWarmResults(): void
{
    // Allow the script to run as long as needed (5278 URLs can take many minutes)
    set_time_limit(0);
    // Keep running even if the browser closes the tab
    ignore_user_abort(true);

    $tmpFile = null;

    // ── SSE headers (send immediately so browser starts receiving) ────────────
    // Kill every output buffer first so nothing blocks the stream.
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    flush();

    // ── Resolve sitemap source ────────────────────────────────────────────────
    if (!empty($_FILES['sitemap_file']['tmp_name']) && $_FILES['sitemap_file']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['sitemap_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xml') {
            sendSseEvent('error', 'Only .xml files are accepted.');
            sendSseEvent('done', '');
            return;
        }
        $tmpFile = tempnam(sys_get_temp_dir(), 'cdnwarm_') . '.xml';
        move_uploaded_file($_FILES['sitemap_file']['tmp_name'], $tmpFile);
        $sitemapSource = $tmpFile;

    } elseif (!empty($_POST['sitemap_url'])) {
        $url = trim($_POST['sitemap_url']);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            sendSseEvent('error', 'Invalid URL provided.');
            sendSseEvent('done', '');
            return;
        }
        $sitemapSource = $url;

    } else {
        sendSseEvent('error', 'No sitemap file or URL provided.');
        sendSseEvent('done', '');
        return;
    }

    // ── Build CLI argument list ───────────────────────────────────────────────
    $concurrency   = max(1,  min(50,  (int)($_POST['concurrency']      ?? 10)));
    $timeout       = max(5,  min(120, (int)($_POST['timeout']          ?? 30)));
    $connectTimeout= max(2,  min(30,  (int)($_POST['connect_timeout']  ?? 10)));
    $retry         = max(0,  min(5,   (int)($_POST['retry']            ?? 1)));
    $method        = in_array(strtoupper($_POST['method'] ?? 'GET'), ['GET','HEAD'], true)
                        ? strtoupper($_POST['method']) : 'GET';

    // Resolve PHP binary — prefer PHP_BINARY, fall back to 'php' on PATH
    $phpBin = (PHP_BINARY && is_executable(PHP_BINARY)) ? PHP_BINARY : 'php';

    $argv = [
        escapeshellarg($phpBin),
        escapeshellarg(__DIR__ . '/run.php'),
        escapeshellarg($sitemapSource),
        '--verbose',
        '--concurrency=' . $concurrency,
        '--timeout='     . $timeout,
        '--connect-timeout=' . $connectTimeout,
        '--method='      . $method,
        '--retry='       . $retry,
    ];

    if (!empty($_POST['no_ssl_verify']))        $argv[] = '--no-ssl-verify';
    if (!empty($_POST['no_follow_redirects']))  $argv[] = '--no-follow-redirects';
    if (!empty($_POST['user_agent']))           $argv[] = '--user-agent=' . escapeshellarg(trim($_POST['user_agent']));

    $cmd = implode(' ', $argv);

    // ── Spawn subprocess and stream stdout line-by-line ───────────────────────
    $descriptors = [
        0 => ['pipe', 'r'],   // stdin  (unused)
        1 => ['pipe', 'w'],   // stdout — we read this
        2 => ['pipe', 'w'],   // stderr — merge into log
    ];

    $process = proc_open($cmd, $descriptors, $pipes, __DIR__);

    if (!is_resource($process)) {
        sendSseEvent('error', 'Failed to start warmer process.');
        sendSseEvent('done', '');
        if ($tmpFile) @unlink($tmpFile);
        return;
    }

    fclose($pipes[0]); // close stdin

    // Make stdout/stderr non-blocking so we can interleave them
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdoutBuf = '';
    $stderrBuf = '';

    // Track totals for summary (parse from log lines)
    $total = $success = $warnings = $errors = 0;

    while (true) {
        $read   = [$pipes[1], $pipes[2]];
        $write  = null;
        $except = null;

        $changed = @stream_select($read, $write, $except, 1, 0); // wait up to 1 s

        if ($changed === false) break;

        foreach ($read as $stream) {
            $chunk = fread($stream, 8192);
            if ($chunk === false || $chunk === '') continue;

            if ($stream === $pipes[1]) $stdoutBuf .= $chunk;
            else                       $stderrBuf .= $chunk;
        }

        // Emit complete lines from stdout
        while (($pos = strpos($stdoutBuf, "\n")) !== false) {
            $line = substr($stdoutBuf, 0, $pos);
            $stdoutBuf = substr($stdoutBuf, $pos + 1);
            $line = stripAnsi($line);
            if ($line !== '') {
                sendSseEvent('log', $line);
                parseSummaryLine($line, $total, $success, $warnings, $errors);
            }
        }

        // Emit complete lines from stderr
        while (($pos = strpos($stderrBuf, "\n")) !== false) {
            $line = substr($stderrBuf, 0, $pos);
            $stderrBuf = substr($stderrBuf, $pos + 1);
            $line = stripAnsi($line);
            if ($line !== '') sendSseEvent('log', '[STDERR] ' . $line);
        }

        // Check if process has exited and both pipes are drained
        $status = proc_get_status($process);
        if (!$status['running'] && feof($pipes[1]) && feof($pipes[2])) break;
    }

    // Flush any remaining buffered output
    foreach ([$stdoutBuf, $stderrBuf] as $buf) {
        if (trim($buf) !== '') sendSseEvent('log', stripAnsi($buf));
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    if ($tmpFile) @unlink($tmpFile);

    // ── Emit summary ─────────────────────────────────────────────────────────
    if ($total > 0) {
        sendSseEvent('summary', json_encode([
            'total'    => $total,
            'success'  => $success,
            'warnings' => $warnings,
            'errors'   => $errors,
        ]));
    }

    sendSseEvent('done', '');
}

/**
 * Strips ANSI escape codes from a string.
 */
function stripAnsi(string $str): string
{
    return preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $str);
}

/**
 * Parses log lines to build the summary counters.
 */
function parseSummaryLine(string $line, int &$total, int &$success, int &$warnings, int &$errors): void
{
    // "Found 123 URL(s)."
    if (preg_match('/Found (\d+) URL/', $line, $m)) {
        $total = (int)$m[1];
        return;
    }
    // Per-URL verbose lines: "[ OK ] [200]" / "[WARN] [404]" / "[FAIL] [ERR]"
    if (preg_match('/\[\s*OK\s*\]/', $line))   { $success++;  return; }
    if (preg_match('/\[\s*WARN\s*\]/', $line)) { $warnings++; return; }
    if (preg_match('/\[\s*FAIL\s*\]/', $line)) { $errors++;   return; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CDN Cache Warmer</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:        #0f1117;
    --surface:   #1a1d27;
    --border:    #2e3147;
    --accent:    #6c63ff;
    --accent-h:  #8b85ff;
    --text:      #e2e8f0;
    --muted:     #8892a4;
    --success:   #4ade80;
    --warn:      #facc15;
    --error:     #f87171;
    --info:      #38bdf8;
    --radius:    10px;
  }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
    min-height: 100vh;
    padding: 2rem 1rem;
  }

  .container { max-width: 860px; margin: 0 auto; }

  h1 {
    font-size: 1.8rem;
    font-weight: 700;
    margin-bottom: .25rem;
    background: linear-gradient(90deg, var(--accent), var(--info));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
  }
  .subtitle { color: var(--muted); font-size: .9rem; margin-bottom: 2rem; }

  /* ── Card ── */
  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
  }
  .card-title {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 1.25rem;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: .5rem;
  }

  /* ── Form ── */
  .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  @media (max-width: 600px) { .form-grid { grid-template-columns: 1fr; } }

  .field { display: flex; flex-direction: column; gap: .4rem; }
  .field.full { grid-column: 1 / -1; }

  label { font-size: .8rem; font-weight: 500; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }

  input[type=text], input[type=url], input[type=number], select {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text);
    padding: .5rem .75rem;
    font-size: .9rem;
    width: 100%;
    transition: border-color .15s;
  }
  input:focus, select:focus { outline: none; border-color: var(--accent); }

  /* Drop zone */
  .drop-zone {
    border: 2px dashed var(--border);
    border-radius: var(--radius);
    padding: 2rem;
    text-align: center;
    cursor: pointer;
    transition: border-color .2s, background .2s;
    position: relative;
  }
  .drop-zone:hover, .drop-zone.drag-over { border-color: var(--accent); background: rgba(108,99,255,.06); }
  .drop-zone input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
  .drop-zone .dz-icon { font-size: 2rem; margin-bottom: .5rem; }
  .drop-zone .dz-label { font-size: .9rem; color: var(--muted); }
  .drop-zone .dz-label span { color: var(--accent); font-weight: 600; }
  .drop-zone .dz-filename { margin-top: .5rem; font-size: .85rem; color: var(--success); font-weight: 500; }

  .divider {
    display: flex; align-items: center; gap: 1rem;
    color: var(--muted); font-size: .8rem; margin: 1rem 0;
  }
  .divider::before, .divider::after { content:''; flex:1; height:1px; background: var(--border); }

  /* Toggle group */
  .toggle-row { display: flex; flex-wrap: wrap; gap: .75rem; }
  .toggle-label {
    display: flex; align-items: center; gap: .4rem;
    font-size: .85rem; color: var(--text); cursor: pointer; user-select: none;
  }
  .toggle-label input[type=checkbox] { accent-color: var(--accent); width: 15px; height: 15px; cursor: pointer; }

  /* Buttons */
  .btn {
    display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
    border: none; border-radius: 7px; cursor: pointer;
    font-size: .9rem; font-weight: 600; padding: .65rem 1.4rem;
    transition: background .15s, transform .1s, opacity .15s;
  }
  .btn:active { transform: scale(.97); }
  .btn-primary { background: var(--accent); color: #fff; }
  .btn-primary:hover { background: var(--accent-h); }
  .btn-primary:disabled { opacity: .5; cursor: not-allowed; }
  .btn-secondary { background: var(--border); color: var(--text); }
  .btn-secondary:hover { background: #3a3f5c; }

  .actions { display: flex; gap: .75rem; align-items: center; margin-top: 1.25rem; }

  /* ── Progress / log ── */
  #results-section { display: none; }

  .progress-bar-wrap {
    background: var(--border); border-radius: 100px; height: 6px; margin-bottom: 1rem; overflow: hidden;
  }
  .progress-bar {
    height: 100%; border-radius: 100px;
    background: linear-gradient(90deg, var(--accent), var(--info));
    width: 0%; transition: width .3s ease;
  }

  .log-box {
    background: #0a0c12;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    font-family: 'JetBrains Mono', 'Fira Code', monospace;
    font-size: .78rem;
    line-height: 1.6;
    padding: 1rem;
    height: 340px;
    overflow-y: auto;
    white-space: pre-wrap;
    word-break: break-all;
  }

  .log-line { margin: 0; }
  .log-line.ok   { color: var(--success); }
  .log-line.warn { color: var(--warn); }
  .log-line.err  { color: var(--error); }
  .log-line.info { color: var(--info); }
  .log-line.plain{ color: #a0aec0; }

  /* ── Summary cards ── */
  .summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: .75rem;
    margin-top: 1rem;
  }
  @media (max-width: 600px) { .summary-grid { grid-template-columns: repeat(2,1fr); } }

  .stat-card {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: .9rem 1rem;
    text-align: center;
  }
  .stat-card .stat-val { font-size: 1.7rem; font-weight: 700; }
  .stat-card .stat-lbl { font-size: .75rem; color: var(--muted); margin-top: .15rem; text-transform: uppercase; letter-spacing: .04em; }
  .stat-card.s-total   .stat-val { color: var(--text); }
  .stat-card.s-success .stat-val { color: var(--success); }
  .stat-card.s-warn    .stat-val { color: var(--warn); }
  .stat-card.s-error   .stat-val { color: var(--error); }

  #status-text { font-size: .85rem; color: var(--muted); margin-bottom: .5rem; }
</style>
</head>
<body>
<div class="container">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.25rem;">
    <h1 style="margin-bottom:0">CDN Cache Warmer</h1>
    <a href="/?logout=1" style="font-size:.8rem;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .75rem;border-radius:6px;transition:color .15s;" onmouseover="this.style.color='var(--error)'" onmouseout="this.style.color='var(--muted)'">Sign out</a>
  </div>
  <p class="subtitle">Upload a sitemap XML or paste a URL to warm all listed pages at the CDN edge.</p>

  <!-- ── Configuration form ── -->
  <form id="warm-form" enctype="multipart/form-data">
    <input type="hidden" name="action" value="warm">

    <div class="card">
      <div class="card-title">📄 Sitemap Source</div>

      <!-- Drop zone -->
      <div class="drop-zone" id="drop-zone">
        <input type="file" name="sitemap_file" id="sitemap_file" accept=".xml,application/xml,text/xml">
        <div class="dz-icon">📂</div>
        <div class="dz-label">Drop <span>sitemap.xml</span> here or <span>click to browse</span></div>
        <div class="dz-filename" id="dz-filename"></div>
      </div>

      <div class="divider">or enter a URL</div>

      <div class="field">
        <label for="sitemap_url">Sitemap URL</label>
        <input type="url" name="sitemap_url" id="sitemap_url" placeholder="https://example.com/sitemap.xml">
      </div>
    </div>

    <div class="card">
      <div class="card-title">⚙️ Options</div>
      <div class="form-grid">

        <div class="field">
          <label for="concurrency">Concurrency</label>
          <input type="number" name="concurrency" id="concurrency" value="10" min="1" max="50">
        </div>

        <div class="field">
          <label for="timeout">Timeout (s)</label>
          <input type="number" name="timeout" id="timeout" value="30" min="5" max="120">
        </div>

        <div class="field">
          <label for="connect_timeout">Connect Timeout (s)</label>
          <input type="number" name="connect_timeout" id="connect_timeout" value="10" min="2" max="30">
        </div>

        <div class="field">
          <label for="method">HTTP Method</label>
          <select name="method" id="method">
            <option value="GET" selected>GET (full body)</option>
            <option value="HEAD">HEAD (headers only)</option>
          </select>
        </div>

        <div class="field">
          <label for="retry">Retry on Error</label>
          <input type="number" name="retry" id="retry" value="1" min="0" max="5">
        </div>

        <div class="field">
          <label for="user_agent">User-Agent</label>
          <input type="text" name="user_agent" id="user_agent" placeholder="CdnCacheWarmer/1.0">
        </div>

        <div class="field full">
          <label>Flags</label>
          <div class="toggle-row">
            <label class="toggle-label">
              <input type="checkbox" name="no_ssl_verify" value="1"> Disable SSL verification
            </label>
            <label class="toggle-label">
              <input type="checkbox" name="no_follow_redirects" value="1"> Don't follow redirects
            </label>
          </div>
        </div>

      </div>
    </div>

    <div class="actions">
      <button type="submit" class="btn btn-primary" id="run-btn">▶ Start Warming</button>
      <button type="button" class="btn btn-secondary" id="stop-btn" style="display:none">■ Stop</button>
    </div>
  </form>

  <!-- ── Results ── -->
  <div id="results-section">
    <div class="card" style="margin-top:1.5rem">
      <div class="card-title">📊 Live Output</div>
      <p id="status-text">Running…</p>
      <div class="progress-bar-wrap"><div class="progress-bar" id="progress-bar"></div></div>
      <div class="log-box" id="log-box"></div>
    </div>

    <div class="summary-grid" id="summary-grid" style="display:none">
      <div class="stat-card s-total">
        <div class="stat-val" id="s-total">—</div>
        <div class="stat-lbl">Total URLs</div>
      </div>
      <div class="stat-card s-success">
        <div class="stat-val" id="s-success">—</div>
        <div class="stat-lbl">Successful</div>
      </div>
      <div class="stat-card s-warn">
        <div class="stat-val" id="s-warn">—</div>
        <div class="stat-lbl">Warnings</div>
      </div>
      <div class="stat-card s-error">
        <div class="stat-val" id="s-error">—</div>
        <div class="stat-lbl">Errors</div>
      </div>
    </div>
  </div>

</div><!-- /container -->

<script>
(function () {
  const form       = document.getElementById('warm-form');
  const runBtn     = document.getElementById('run-btn');
  const stopBtn    = document.getElementById('stop-btn');
  const resultsSection = document.getElementById('results-section');
  const logBox     = document.getElementById('log-box');
  const statusText = document.getElementById('status-text');
  const progressBar= document.getElementById('progress-bar');
  const summaryGrid= document.getElementById('summary-grid');
  const dropZone   = document.getElementById('drop-zone');
  const fileInput  = document.getElementById('sitemap_file');
  const dzFilename = document.getElementById('dz-filename');
  const urlInput   = document.getElementById('sitemap_url');

  let evtSource = null;
  let totalUrls = 0;
  let processedUrls = 0;

  // ── Drop zone UX ────────────────────────────────────────────────────────────
  fileInput.addEventListener('change', () => {
    if (fileInput.files.length) {
      dzFilename.textContent = '✓ ' + fileInput.files[0].name;
      urlInput.value = '';
    }
  });

  dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
  dropZone.addEventListener('dragleave', ()=> dropZone.classList.remove('drag-over'));
  dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    const files = e.dataTransfer.files;
    if (files.length) {
      fileInput.files = files;
      dzFilename.textContent = '✓ ' + files[0].name;
      urlInput.value = '';
    }
  });

  // ── Submit ───────────────────────────────────────────────────────────────────
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    startWarming();
  });

  stopBtn.addEventListener('click', () => {
    if (evtSource) { evtSource.close(); evtSource = null; }
    setIdle();
    statusText.textContent = 'Stopped by user.';
  });

  function startWarming() {
    // Reset UI
    logBox.innerHTML = '';
    progressBar.style.width = '0%';
    summaryGrid.style.display = 'none';
    resultsSection.style.display = 'block';
    runBtn.disabled = true;
    stopBtn.style.display = 'inline-flex';
    statusText.textContent = 'Connecting…';
    totalUrls = 0;
    processedUrls = 0;

    // Build FormData and POST via fetch; stream response as SSE
    const fd = new FormData(form);

    // Use fetch + ReadableStream to consume SSE
    fetch(window.location.pathname, { method: 'POST', body: fd })
      .then(res => {
        if (!res.ok) throw new Error('Server error ' + res.status);
        statusText.textContent = 'Running…';
        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        function pump() {
          reader.read().then(({ done, value }) => {
            if (done) { setIdle(); return; }
            buffer += decoder.decode(value, { stream: true });
            const parts = buffer.split('\n\n');
            buffer = parts.pop(); // keep incomplete event
            parts.forEach(parseEvent);
            pump();
          }).catch(err => {
            appendLog('Connection lost: ' + err.message, 'err');
            setIdle();
          });
        }
        pump();
      })
      .catch(err => {
        appendLog('Fetch error: ' + err.message, 'err');
        setIdle();
      });
  }

  function parseEvent(raw) {
    const lines = raw.split('\n');
    let event = 'message', data = '';
    lines.forEach(line => {
      if (line.startsWith('event: ')) event = line.slice(7).trim();
      else if (line.startsWith('data: ')) data += line.slice(6);
    });

    switch (event) {
      case 'log':
        processLogLine(data);
        break;
      case 'summary':
        try {
          const s = JSON.parse(data);
          totalUrls = s.total;
          document.getElementById('s-total').textContent   = s.total;
          document.getElementById('s-success').textContent = s.success;
          document.getElementById('s-warn').textContent    = s.warnings;
          document.getElementById('s-error').textContent   = s.errors;
          summaryGrid.style.display = 'grid';
          progressBar.style.width = '100%';
        } catch(_) {}
        break;
      case 'error':
        appendLog('ERROR: ' + data, 'err');
        break;
      case 'done':
        statusText.textContent = 'Done.';
        setIdle();
        break;
    }
  }

  function processLogLine(rawHtml) {
    // Decode HTML entities (server htmlspecialchars-encoded the output)
    const txt = decodeHtml(rawHtml);

    // Classify line
    let cls = 'plain';
    if (/\[ OK \]|\[INFO\].*Warm-up complete|Warm-up complete/.test(txt)) cls = 'ok';
    else if (/\[WARN\]/.test(txt))  cls = 'warn';
    else if (/\[ERROR\]/.test(txt)) cls = 'err';
    else if (/\[INFO\]/.test(txt))  cls = 'info';
    else if (/\[FAIL\]/.test(txt))  cls = 'err';
    else if (/\[WARN\]/.test(txt))  cls = 'warn';

    // Try to parse total from "Found N URL(s)"
    const foundMatch = txt.match(/Found (\d+) URL\(s\)/);
    if (foundMatch) totalUrls = parseInt(foundMatch[1], 10);

    // Track progress from batch lines "Batch X/Y done — processed/total"
    const batchMatch = txt.match(/(\d+)\/(\d+)\s+\(/);
    if (batchMatch && totalUrls > 0) {
      processedUrls = parseInt(batchMatch[1], 10);
      const pct = Math.min(99, Math.round((processedUrls / totalUrls) * 100));
      progressBar.style.width = pct + '%';
    }

    appendLog(txt, cls);
  }

  function appendLog(text, cls) {
    const p = document.createElement('p');
    p.className = 'log-line ' + (cls || 'plain');
    p.textContent = text;
    logBox.appendChild(p);
    logBox.scrollTop = logBox.scrollHeight;
  }

  function decodeHtml(html) {
    const ta = document.createElement('textarea');
    ta.innerHTML = html;
    return ta.value;
  }

  function setIdle() {
    runBtn.disabled = false;
    stopBtn.style.display = 'none';
    if (statusText.textContent === 'Running…') statusText.textContent = 'Done.';
  }
})();
</script>
</body>
</html>
