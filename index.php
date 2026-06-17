<?php
declare(strict_types=1);

// ── Structured JSON logger with Datadog trace correlation ────────────────────
function logJson(string $level, string $message, array $context = []): void
{
    $traceId = null;
    $spanId  = null;

    if (extension_loaded('ddtrace')) {
        $traceId = \DDTrace\logs_correlation_trace_id();
        $span    = \DDTrace\active_span();
        if ($span !== null) {
            $spanId = $span->hexId();
        }
    }

    $entry = array_merge([
        'timestamp'   => date('c'),
        'level'       => strtoupper($level),
        'message'     => $message,
        'service'     => getenv('DD_SERVICE')  ?: 'kredit-plus',
        'env'         => getenv('DD_ENV')       ?: 'local',
        'version'     => getenv('DD_VERSION')   ?: '1.0.0',
        'dd.trace_id' => $traceId,
        'dd.span_id'  => $spanId,
    ], $context);

    fwrite(STDOUT, json_encode($entry) . PHP_EOL);
}

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT);
}

// ── Manual span helpers (no-op when ddtrace not loaded) ──────────────────────
function spanStart(string $name, array $meta = []): ?object
{
    if (!extension_loaded('ddtrace')) {
        return null;
    }
    $span = \DDTrace\start_span();
    $span->name = $name;
    foreach ($meta as $k => $v) {
        $span->meta[$k] = (string) $v;
    }
    return $span;
}

function spanFinish(?object $span): void
{
    if ($span !== null) {
        \DDTrace\close_span();
    }
}

// ── Route handlers ────────────────────────────────────────────────────────────

function handleHealth(): void
{
    logJson('info', 'Health check OK');
    jsonResponse([
        'status'    => 'ok',
        'service'   => getenv('DD_SERVICE') ?: 'kredit-plus',
        'version'   => getenv('DD_VERSION') ?: '1.0.0',
        'timestamp' => date('c'),
    ]);
}

function handleLoanApply(): void
{
    $root = spanStart('loan.apply', ['resource.name' => 'POST /loan/apply']);

    $applicantId = 'APP-' . rand(1000, 9999);
    $amount      = rand(5000, 50000);
    $currency    = 'MXN';

    if ($root) {
        $root->meta['loan.applicant_id'] = $applicantId;
        $root->meta['loan.amount']       = (string) $amount;
        $root->meta['loan.currency']     = $currency;
    }

    logJson('info', 'Loan application received', [
        'applicant_id' => $applicantId,
        'amount'       => $amount,
        'currency'     => $currency,
    ]);

    // Simulate credit bureau HTTP call
    $http = spanStart('http.request', [
        'http.url'     => 'https://bureau.internal/score',
        'http.method'  => 'GET',
        'span.kind'    => 'client',
        'peer.service' => 'credit-bureau',
    ]);
    usleep(rand(80000, 200000));
    $creditScore = rand(450, 900);
    if ($http) {
        $http->meta['http.status_code'] = '200';
        $http->meta['credit.score']     = (string) $creditScore;
    }
    spanFinish($http);

    logJson('info', 'Credit score retrieved', [
        'applicant_id' => $applicantId,
        'credit_score' => $creditScore,
    ]);

    // Simulate DB INSERT
    $db = spanStart('mysql.query', [
        'db.type'      => 'mysql',
        'db.operation' => 'INSERT',
        'db.name'      => 'kredit',
        'db.statement' => 'INSERT INTO loan_applications (applicant_id, amount, score) VALUES (?, ?, ?)',
        'span.kind'    => 'client',
        'peer.service' => 'kredit-db',
    ]);
    usleep(rand(20000, 60000));
    spanFinish($db);

    $approved = $creditScore >= 650;

    if ($root) {
        $root->meta['loan.approved']     = $approved ? 'true' : 'false';
        $root->meta['loan.credit_score'] = (string) $creditScore;
        $root->meta['loan.decision']     = $approved ? 'APPROVED' : 'DENIED';
    }

    logJson($approved ? 'info' : 'warn', 'Loan decision made', [
        'applicant_id' => $applicantId,
        'approved'     => $approved,
        'credit_score' => $creditScore,
    ]);

    spanFinish($root);

    jsonResponse([
        'applicant_id' => $applicantId,
        'amount'       => $amount,
        'currency'     => $currency,
        'credit_score' => $creditScore,
        'status'       => $approved ? 'APPROVED' : 'DENIED',
    ]);
}

function handleLoanScore(): void
{
    $root = spanStart('loan.score', ['resource.name' => 'GET /loan/score']);

    $customerId = $_GET['customer_id'] ?? ('CUST-' . rand(100, 999));
    if ($root) {
        $root->meta['customer.id'] = $customerId;
    }

    logJson('info', 'Credit scoring requested', ['customer_id' => $customerId]);

    $fraud = spanStart('model.fraud_check', ['model.name' => 'fraud-v2', 'span.kind' => 'internal']);
    usleep(rand(40000, 100000));
    $fraudRisk = round(lcg_value() * 0.4, 3);
    if ($fraud) {
        $fraud->meta['fraud.risk_score'] = (string) $fraudRisk;
    }
    spanFinish($fraud);

    $income = spanStart('model.income_verification', ['model.name' => 'income-v1', 'span.kind' => 'internal']);
    usleep(rand(60000, 130000));
    $incomeVerified = (rand(0, 1) === 1);
    if ($income) {
        $income->meta['income.verified'] = $incomeVerified ? 'true' : 'false';
    }
    spanFinish($income);

    $finalScore = rand(500, 850);
    if ($root) {
        $root->meta['score.value']      = (string) $finalScore;
        $root->meta['score.fraud_risk'] = (string) $fraudRisk;
    }

    logJson('info', 'Scoring complete', [
        'customer_id'     => $customerId,
        'score'           => $finalScore,
        'fraud_risk'      => $fraudRisk,
        'income_verified' => $incomeVerified,
    ]);

    spanFinish($root);

    jsonResponse([
        'customer_id'     => $customerId,
        'score'           => $finalScore,
        'fraud_risk'      => $fraudRisk,
        'income_verified' => $incomeVerified,
        'tier'            => $finalScore >= 750 ? 'PRIME' : ($finalScore >= 620 ? 'STANDARD' : 'SUBPRIME'),
    ]);
}

function handleLoanSlow(): void
{
    $root = spanStart('loan.slow_report', ['resource.name' => 'GET /loan/slow']);

    logJson('warn', 'Slow report generation started — high latency expected');

    // 5 sequential DB reads — classic N+1 problem, great APM demo
    for ($i = 0; $i < 5; $i++) {
        $q = spanStart('mysql.query', [
            'db.type'      => 'mysql',
            'db.operation' => 'SELECT',
            'db.name'      => 'kredit',
            'db.statement' => 'SELECT * FROM loan_history WHERE month = ?',
        ]);
        usleep(rand(150000, 300000));
        spanFinish($q);
    }

    if ($root) {
        $root->meta['report.pages'] = '5';
    }
    logJson('warn', 'Slow report finished', ['pages_processed' => 5]);
    spanFinish($root);

    jsonResponse(['status' => 'report_ready', 'pages' => 5]);
}

function handleLoanError(): void
{
    $root = spanStart('loan.error_demo', ['resource.name' => 'GET /loan/error']);
    $downstream = null;

    logJson('warn', 'Error simulation endpoint called');

    try {
        $downstream = spanStart('http.request', [
            'http.url'     => 'https://payments.internal/charge',
            'http.method'  => 'POST',
            'span.kind'    => 'client',
            'peer.service' => 'payments',
        ]);
        usleep(50000);
        throw new \RuntimeException('Payment gateway timeout after 5000ms', 504);

    } catch (\RuntimeException $e) {
        if ($downstream) {
            $downstream->meta['error']            = 'true';
            $downstream->meta['error.message']    = $e->getMessage();
            $downstream->meta['error.type']       = get_class($e);
            $downstream->meta['http.status_code'] = '504';
        }
        spanFinish($downstream);

        if ($root) {
            $root->meta['error']         = 'true';
            $root->meta['error.message'] = $e->getMessage();
            $root->meta['error.type']    = get_class($e);
            $root->meta['error.stack']   = $e->getTraceAsString();
        }

        logJson('error', 'Payment gateway error', [
            'error.type'    => get_class($e),
            'error.message' => $e->getMessage(),
            'error.code'    => $e->getCode(),
        ]);

        spanFinish($root);
        jsonResponse(['error' => $e->getMessage(), 'code' => $e->getCode()], 504);
        return;
    }

    spanFinish($root);
}

// ── Router ────────────────────────────────────────────────────────────────────
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

switch ($path) {
    case '/':
    case '/health':
        handleHealth();
        break;
    case '/loan/apply':
        handleLoanApply();
        break;
    case '/loan/score':
        handleLoanScore();
        break;
    case '/loan/slow':
        handleLoanSlow();
        break;
    case '/loan/error':
        handleLoanError();
        break;
    default:
        logJson('warn', 'Route not found', ['path' => $path]);
        jsonResponse(['error' => 'Not found', 'path' => $path], 404);
}
