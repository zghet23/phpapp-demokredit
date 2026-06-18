<?php
declare(strict_types=1);

// ── Autoloader ────────────────────────────────────────────────────────────────
spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/src/' . $class . '.php';
    if (file_exists($file)) require_once $file;
});

// ── Bootstrap ─────────────────────────────────────────────────────────────────
Logger::init();

$db   = new Db();
$http = new Http();

// ── Router ────────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$body   = (array)(json_decode(file_get_contents('php://input') ?: '{}', true) ?? []);

// Helper: return JSON
function respond(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper: wrap handler in a try/catch
function safe(callable $fn): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        Logger::exception($e, 'Request handler exception');
        respond(['error' => $e->getMessage(), 'type' => get_class($e)], max(500, $e->getCode() ?: 500));
    }
}

// ─── Routes ──────────────────────────────────────────────────────────────────

// UI
if ($method === 'GET' && ($path === '/' || $path === '')) {
    serveUi();
    exit;
}

// Health
if ($path === '/health') {
    Logger::info('Health check');
    respond(['status' => 'ok', 'service' => getenv('DD_SERVICE') ?: 'kredit-plus', 'version' => getenv('DD_VERSION') ?: '1.0.0', 'db' => $db->isConnected(), 'timestamp' => date('c')]);
}

// ── Log generation ───────────────────────────────────────────────────────────
if ($method === 'POST' && preg_match('#^/api/log/(\w+)$#', $path, $m)) {
    safe(function() use ($m) {
        $level   = $m[1];
        $message = "Log level {$level} generated from APM demo";
        $ctx     = ['source' => 'ui', 'level_requested' => $level];
        match ($level) {
            'debug'     => Logger::debug($message, $ctx),
            'info'      => Logger::info($message, $ctx),
            'warning'   => Logger::warning($message, $ctx),
            'error'     => Logger::error($message, $ctx),
            'critical'  => Logger::critical($message, $ctx),
            'exception' => (static function() use ($ctx) {
                try {
                    throw new \RuntimeException('Simulated exception for APM demo', 500);
                } catch (\Throwable $e) {
                    Logger::exception($e, 'Simulated exception triggered from UI');
                }
            })(),
            default     => Logger::info($message, $ctx),
        };
        respond(['logged' => true, 'level' => $level, 'message' => $message]);
    });
}

if ($method === 'POST' && $path === '/api/log/batch') {
    safe(function() {
        $levels = ['debug','info','info','info','warning','error'];
        for ($i = 0; $i < 20; $i++) {
            $level = $levels[array_rand($levels)];
            Logger::$level("Batch log #{$i} ({$level})", ['batch' => true, 'index' => $i]);
        }
        respond(['logged' => 20, 'note' => '20 mixed-level log entries generated']);
    });
}

// ── External flows ────────────────────────────────────────────────────────────
if ($method === 'POST' && preg_match('#^/api/flow/(\w+)$#', $path, $m)) {
    safe(function() use ($m, $http) {
        $result = match ($m[1]) {
            'posts'   => $http->runPostsFlow(),
            'users'   => $http->runUsersFlow(),
            'github'  => $http->runGitHubFlow(),
            'crypto'  => $http->runCryptoFlow(),
            'httpbin' => $http->runHttpBinFlow(),
            'quotes'  => $http->runQuotesFlow(),
            'all'     => $http->runAllFlows(),
            default   => throw new \InvalidArgumentException("Unknown flow: {$m[1]}"),
        };
        respond(['flow' => $m[1], 'result' => $result]);
    });
}

// ── Database operations ───────────────────────────────────────────────────────
if ($method === 'POST' && preg_match('#^/api/db/(\S+)$#', $path, $m)) {
    safe(function() use ($m, $db, $body) {
        $op     = $m[1];
        $result = match ($op) {
            'connection'      => $db->testConnection(),
            'top-customers'   => $db->topCustomers((int)($body['days'] ?? 30), (int)($body['limit'] ?? 10)),
            'orders-status'   => $db->ordersByStatus($body['status'] ?? 'pending'),
            'catalog'         => $db->productCatalog($body['category'] ?? null),
            'search-reviews'  => $db->searchReviews($body['term'] ?? 'great'),
            'heavy-report'    => $db->heavyReport(),
            'create-order'    => $db->createRandomOrder(),
            default           => throw new \InvalidArgumentException("Unknown DB operation: {$op}"),
        };
        respond(['operation' => $op, 'result' => $result]);
    });
}

// ── Error simulation ──────────────────────────────────────────────────────────
if ($method === 'POST' && preg_match('#^/api/error/(\w+(?:-\w+)*)$#', $path, $m)) {
    safe(function() use ($m, $http) {
        match ($m[1]) {
            'timeout'       => $http->simulateTimeout(),
            'http-500'      => $http->simulateHttp500(),
            'dns-failure'   => $http->simulateDnsFailure(),
            'retry-backoff' => respond(['simulation' => 'retry-backoff', 'result' => $http->simulateRetryBackoff()]),
            default         => throw new \InvalidArgumentException("Unknown error simulation: {$m[1]}"),
        };
    });
}

// 404
Logger::warning('Route not found', ['path' => $path, 'method' => $method]);
respond(['error' => 'Not found', 'path' => $path], 404);

// ─────────────────────────────────────────────────────────────────────────────
// UI
// ─────────────────────────────────────────────────────────────────────────────
function serveUi(): void
{
    $service = getenv('DD_SERVICE') ?: 'kredit-plus';
    $env     = getenv('DD_ENV')     ?: 'local';
    $version = getenv('DD_VERSION') ?: '1.0.0';
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kredit Plus — APM Demo</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg:      #0f1117;
    --surface: #1a1d2e;
    --card:    #21253a;
    --border:  #2e3352;
    --text:    #e2e8f0;
    --muted:   #94a3b8;
    --accent:  #6366f1;
    --green:   #22c55e;
    --yellow:  #eab308;
    --red:     #ef4444;
    --orange:  #f97316;
    --blue:    #3b82f6;
    --purple:  #a855f7;
  }
  body { background: var(--bg); color: var(--text); font-family: system-ui, sans-serif; min-height: 100vh; }

  header { background: var(--surface); border-bottom: 1px solid var(--border); padding: 1rem 2rem; display: flex; align-items: center; gap: 1rem; }
  header h1 { font-size: 1.4rem; font-weight: 700; letter-spacing: -.5px; }
  .badge { background: var(--accent); color: #fff; font-size: .7rem; font-weight: 600; padding: .2rem .6rem; border-radius: 99px; text-transform: uppercase; }
  .badge.green { background: var(--green); }
  .tag { background: var(--card); border: 1px solid var(--border); font-size: .75rem; padding: .2rem .5rem; border-radius: 4px; color: var(--muted); }
  header .spacer { flex: 1; }

  main { padding: 1.5rem 2rem; display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.25rem; }

  .card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 1.25rem; }
  .card h2 { font-size: .85rem; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin-bottom: 1rem; display: flex; align-items: center; gap: .5rem; }
  .card h2 .icon { font-size: 1rem; }

  .btn-grid { display: flex; flex-wrap: wrap; gap: .5rem; }
  button {
    background: var(--surface); border: 1px solid var(--border); color: var(--text);
    font-size: .8rem; padding: .45rem .9rem; border-radius: 6px; cursor: pointer;
    transition: all .15s; white-space: nowrap; font-weight: 500;
  }
  button:hover { border-color: var(--accent); color: #fff; background: rgba(99,102,241,.15); }
  button:active { transform: scale(.97); }
  button.loading { opacity: .6; pointer-events: none; }
  button.ok   { border-color: var(--green);  color: var(--green); }
  button.fail { border-color: var(--red);    color: var(--red); }

  button[data-color="debug"]    { border-color: #64748b; }
  button[data-color="info"]     { border-color: var(--blue); }
  button[data-color="warning"]  { border-color: var(--yellow); }
  button[data-color="error"]    { border-color: var(--red); }
  button[data-color="critical"] { border-color: var(--orange); }
  button[data-color="exception"]{ border-color: var(--purple); }
  button[data-color="all"]      { border-color: var(--accent); background: rgba(99,102,241,.12); }

  #response-area { grid-column: 1 / -1; }
  #response-area h2 { font-size: .85rem; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin-bottom: .75rem; }
  #response-box {
    background: #0a0d18; border: 1px solid var(--border); border-radius: 8px;
    padding: 1rem; min-height: 180px; max-height: 400px; overflow-y: auto;
    font-family: 'Fira Code', 'Cascadia Code', monospace; font-size: .78rem; line-height: 1.6;
    white-space: pre-wrap; word-break: break-word; color: #a5f3fc;
  }
  #response-box.error-state { color: #fca5a5; }
  #last-op { font-size: .75rem; color: var(--muted); margin-bottom: .4rem; }
  .spinner { display: inline-block; animation: spin .6s linear infinite; }
  @keyframes spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>
<header>
  <h1>Kredit Plus</h1>
  <span class="badge">APM Demo</span>
  <span class="tag">service: {$service}</span>
  <span class="tag">env: {$env}</span>
  <span class="tag">v{$version}</span>
  <span class="spacer"></span>
  <span id="db-badge" class="badge"></span>
</header>
<main>

  <!-- Log Generation -->
  <div class="card">
    <h2><span class="icon">📋</span> Log Generation</h2>
    <div class="btn-grid">
      <button data-color="debug"     onclick="call('/api/log/debug',    this)">DEBUG</button>
      <button data-color="info"      onclick="call('/api/log/info',     this)">INFO</button>
      <button data-color="warning"   onclick="call('/api/log/warning',  this)">WARNING</button>
      <button data-color="error"     onclick="call('/api/log/error',    this)">ERROR</button>
      <button data-color="critical"  onclick="call('/api/log/critical', this)">CRITICAL</button>
      <button data-color="exception" onclick="call('/api/log/exception',this)">EXCEPTION</button>
      <button data-color="all"       onclick="call('/api/log/batch',    this)">BATCH ×20</button>
    </div>
  </div>

  <!-- External Flows -->
  <div class="card">
    <h2><span class="icon">🌐</span> External API Flows</h2>
    <div class="btn-grid">
      <button onclick="call('/api/flow/posts',   this)">Posts</button>
      <button onclick="call('/api/flow/users',   this)">Users</button>
      <button onclick="call('/api/flow/github',  this)">GitHub</button>
      <button onclick="call('/api/flow/crypto',  this)">Crypto</button>
      <button onclick="call('/api/flow/httpbin', this)">HTTPBin</button>
      <button onclick="call('/api/flow/quotes',  this)">Quotes</button>
      <button data-color="all" onclick="call('/api/flow/all', this)">▶ Run All</button>
    </div>
  </div>

  <!-- DB Operations -->
  <div class="card">
    <h2><span class="icon">🗄️</span> Database Operations</h2>
    <div class="btn-grid">
      <button onclick="call('/api/db/connection',    this)">Test Connection</button>
      <button onclick="call('/api/db/top-customers', this)">Top Customers</button>
      <button onclick="callWith('/api/db/orders-status', {status:'pending'}, this)">Orders Pending</button>
      <button onclick="callWith('/api/db/orders-status', {status:'delivered'}, this)">Orders Delivered</button>
      <button onclick="call('/api/db/catalog',       this)">Catalog Overview</button>
      <button onclick="callWith('/api/db/search-reviews', {term:'great'}, this)">Search Reviews 🐢</button>
      <button onclick="call('/api/db/heavy-report',  this)">Heavy Report 🐢</button>
      <button data-color="all" onclick="call('/api/db/create-order', this)">Create Order (TX)</button>
    </div>
  </div>

  <!-- Error Simulation -->
  <div class="card">
    <h2><span class="icon">💥</span> Error Simulation</h2>
    <div class="btn-grid">
      <button onclick="call('/api/error/timeout',       this)">Timeout</button>
      <button onclick="call('/api/error/http-500',      this)">HTTP 500</button>
      <button onclick="call('/api/error/dns-failure',   this)">DNS Failure</button>
      <button onclick="call('/api/error/retry-backoff', this)">Retry + Backoff</button>
    </div>
  </div>

  <!-- Response -->
  <div id="response-area" class="card">
    <h2>Response</h2>
    <div id="last-op">—</div>
    <div id="response-box">Hit any button to see the APM-instrumented response here.</div>
  </div>

</main>
<script>
  // Check DB status on load
  fetch('/api/db/connection', {method:'POST'})
    .then(r => r.json())
    .then(d => {
      const b = document.getElementById('db-badge');
      b.textContent = d.result?.connected ? '✓ DB' : '✗ DB';
      b.className   = 'badge ' + (d.result?.connected ? 'green' : '');
    }).catch(() => {
      const b = document.getElementById('db-badge');
      b.textContent = '✗ DB'; b.className = 'badge';
    });

  async function call(url, btn, body = {}) {
    const box     = document.getElementById('response-box');
    const lastOp  = document.getElementById('last-op');
    const origTxt = btn.textContent;
    btn.classList.add('loading');
    btn.textContent = '⏳ ' + origTxt;
    box.classList.remove('error-state');
    box.textContent = 'Loading…';
    lastOp.textContent = 'POST ' + url;

    try {
      const res  = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)});
      const data = await res.json();
      box.textContent = JSON.stringify(data, null, 2);
      if (!res.ok) box.classList.add('error-state');
      btn.classList.remove('loading'); btn.textContent = origTxt;
    } catch(e) {
      box.textContent = 'Network error: ' + e.message;
      box.classList.add('error-state');
      btn.classList.remove('loading'); btn.textContent = origTxt;
    }
  }

  function callWith(url, body, btn) { call(url, btn, body); }
</script>
</body>
</html>
HTML;
}
