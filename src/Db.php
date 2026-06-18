<?php
declare(strict_types=1);

class Db
{
    private ?PDO $pdo   = null;
    private string $host   = 'unknown';
    private int    $port   = 3306;
    private string $dbname = 'kredit';

    public function __construct()
    {
        $dsn  = getenv('MYSQL_DSN') ?: '';
        $user = getenv('MYSQL_USER') ?: getenv('DB_USER') ?: 'root';
        $pass = getenv('MYSQL_PASSWORD') ?: getenv('DB_PASS') ?: '';

        if (!$dsn) {
            $host = getenv('DB_HOST') ?: '';
            $port = (int)(getenv('DB_PORT') ?: 3306);
            $db   = getenv('DB_NAME') ?: 'kredit';
            if ($host) {
                $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
            }
        }

        if ($dsn) {
            try {
                $this->pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT            => 5,
                ]);
                preg_match('/host=([^;]+)/', $dsn, $m);  $this->host   = $m[1] ?? 'unknown';
                preg_match('/port=(\d+)/', $dsn, $m);    $this->port   = (int)($m[1] ?? 3306);
                preg_match('/dbname=([^;]+)/', $dsn, $m); $this->dbname = $m[1] ?? 'kredit';
                Logger::info('Database connected', ['host' => $this->host, 'db' => $this->dbname]);
            } catch (\PDOException $e) {
                Logger::error('Database connection failed', ['error' => $e->getMessage()]);
            }
        }
    }

    public function isConnected(): bool { return $this->pdo !== null; }

    // ── Span helpers ─────────────────────────────────────────────────────────

    private function spanOpen(string $statement, string $operation): ?object
    {
        if (!extension_loaded('ddtrace')) return null;
        $span = \DDTrace\start_span();
        $span->name = 'mysql.query';
        $span->type = 'sql';
        $span->meta['db.type']      = 'mysql';
        $span->meta['db.system']    = 'mysql';
        $span->meta['db.name']      = $this->dbname;
        $span->meta['db.statement'] = $statement;
        $span->meta['db.operation'] = $operation;
        $span->meta['span.kind']    = 'client';
        $span->meta['component']    = 'PDO';
        $span->meta['peer.service'] = 'kredit-db';
        $span->meta['peer.hostname']= $this->host;
        $span->meta['out.host']     = $this->host;
        $span->meta['out.port']     = (string) $this->port;
        $span->meta['network.destination.name'] = $this->host;
        $span->meta['network.destination.port'] = (string) $this->port;
        return $span;
    }

    private function spanClose(?object $span, float $start, int $rows = 0): void
    {
        if (!$span) return;
        $span->meta['db.rows']       = (string) $rows;
        $span->meta['db.elapsed_ms'] = (string) round((microtime(true) - $start) * 1000, 2);
        \DDTrace\close_span();
    }

    private function spanError(?object $span, \Throwable $e): void
    {
        if (!$span) return;
        $span->meta['error']         = 'true';
        $span->meta['error.type']    = get_class($e);
        $span->meta['error.message'] = $e->getMessage();
    }

    // ── Query runner ──────────────────────────────────────────────────────────

    private function run(string $sql, array $params = []): array
    {
        if (!$this->pdo) return [];
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ── Public operations ─────────────────────────────────────────────────────

    public function testConnection(): array
    {
        $sql  = 'SELECT VERSION() AS version, NOW() AS server_time, DATABASE() AS db_name';
        $span = $this->spanOpen($sql, 'SELECT');
        $t    = microtime(true);
        try {
            if (!$this->pdo) return ['connected' => false, 'note' => 'DB not configured'];
            $row = $this->run($sql)[0] ?? [];
            $this->spanClose($span, $t, 1);
            Logger::info('DB connection test OK', ['host' => $this->host]);
            return array_merge($row, ['connected' => true, 'host' => $this->host]);
        } catch (\Throwable $e) {
            $this->spanError($span, $e); $this->spanClose($span, $t);
            Logger::exception($e, 'DB connection test failed');
            throw $e;
        }
    }

    public function topCustomers(int $days = 30, int $limit = 10): array
    {
        $sql = <<<SQL
            SELECT c.full_name, c.email, c.segment, c.country,
                   COUNT(o.id) AS order_count,
                   ROUND(SUM(o.total_cents)/100,2) AS revenue
            FROM customers c
            JOIN orders o ON o.customer_id = c.id
            WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND o.status IN ('paid','shipped','delivered')
            GROUP BY c.id
            ORDER BY revenue DESC
            LIMIT ?
        SQL;
        $span = $this->spanOpen($sql, 'SELECT');
        $t    = microtime(true);
        try {
            $rows = $this->run($sql, [$days, $limit]);
            $this->spanClose($span, $t, count($rows));
            Logger::info('Top customers query', ['days' => $days, 'rows' => count($rows)]);
            return $rows;
        } catch (\Throwable $e) {
            $this->spanError($span, $e); $this->spanClose($span, $t);
            Logger::exception($e, 'Top customers query failed'); throw $e;
        }
    }

    public function ordersByStatus(string $status = 'pending'): array
    {
        $sql = <<<SQL
            SELECT o.id, c.full_name, c.email, o.status,
                   ROUND(o.total_cents/100,2) AS total, o.created_at
            FROM orders o
            JOIN customers c ON c.id = o.customer_id
            WHERE o.status = ?
            ORDER BY o.created_at DESC
            LIMIT 25
        SQL;
        $span = $this->spanOpen($sql, 'SELECT');
        $t    = microtime(true);
        try {
            $rows = $this->run($sql, [$status]);
            $this->spanClose($span, $t, count($rows));
            Logger::info('Orders by status', ['status' => $status, 'rows' => count($rows)]);
            return $rows;
        } catch (\Throwable $e) {
            $this->spanError($span, $e); $this->spanClose($span, $t);
            Logger::exception($e, 'Orders by status failed'); throw $e;
        }
    }

    public function productCatalog(?string $category = null): array
    {
        if ($category) {
            $sql    = 'SELECT id, sku, name, category, ROUND(price_cents/100,2) AS price, stock FROM products WHERE category = ? ORDER BY name LIMIT 20';
            $params = [$category];
        } else {
            $sql    = 'SELECT category, COUNT(*) AS products, ROUND(AVG(price_cents)/100,2) AS avg_price, SUM(stock) AS total_stock FROM products GROUP BY category ORDER BY products DESC';
            $params = [];
        }
        $span = $this->spanOpen($sql, 'SELECT');
        $t    = microtime(true);
        try {
            $rows = $this->run($sql, $params);
            $this->spanClose($span, $t, count($rows));
            Logger::info('Product catalog query', ['category' => $category ?? 'all', 'rows' => count($rows)]);
            return $rows;
        } catch (\Throwable $e) {
            $this->spanError($span, $e); $this->spanClose($span, $t);
            Logger::exception($e, 'Product catalog failed'); throw $e;
        }
    }

    public function searchReviews(string $term = 'great'): array
    {
        $sql = <<<SQL
            SELECT r.id, p.name AS product, c.full_name AS customer,
                   r.rating, r.comment
            FROM product_reviews r
            JOIN products p  ON p.id = r.product_id
            JOIN customers c ON c.id = r.customer_id
            WHERE r.comment LIKE ?
            ORDER BY r.rating DESC
            LIMIT 20
        SQL;
        $span = $this->spanOpen($sql, 'SELECT');
        if ($span) $span->meta['db.note'] = 'full-table-scan-no-index';
        $t = microtime(true);
        try {
            $rows = $this->run($sql, ["%{$term}%"]);
            $this->spanClose($span, $t, count($rows));
            Logger::warning('Slow query: LIKE scan with no index', ['term' => $term, 'rows' => count($rows)]);
            return $rows;
        } catch (\Throwable $e) {
            $this->spanError($span, $e); $this->spanClose($span, $t);
            Logger::exception($e, 'Review search failed'); throw $e;
        }
    }

    public function heavyReport(): array
    {
        $sql = <<<SQL
            SELECT p.category, p.name AS product,
                   COUNT(r.id)                                       AS reviews,
                   ROUND(AVG(r.rating),2)                            AS avg_rating,
                   COALESCE(SUM(oi.quantity),0)                      AS units_sold,
                   ROUND(COALESCE(SUM(oi.quantity*oi.unit_price_cents),0)/100,2) AS revenue
            FROM products p
            LEFT JOIN product_reviews r  ON r.product_id = p.id
            LEFT JOIN order_items oi     ON oi.product_id = p.id
            LEFT JOIN orders o           ON o.id = oi.order_id
                AND o.status IN ('paid','shipped','delivered')
            GROUP BY p.id
            ORDER BY revenue DESC
            LIMIT 20
        SQL;
        $span = $this->spanOpen($sql, 'SELECT');
        if ($span) $span->meta['db.note'] = 'heavy-join-4-tables';
        $t = microtime(true);
        try {
            $rows = $this->run($sql);
            $this->spanClose($span, $t, count($rows));
            Logger::info('Heavy report completed', ['rows' => count($rows)]);
            return $rows;
        } catch (\Throwable $e) {
            $this->spanError($span, $e); $this->spanClose($span, $t);
            Logger::exception($e, 'Heavy report failed'); throw $e;
        }
    }

    public function createRandomOrder(): array
    {
        if (!$this->pdo) {
            return ['mock' => true, 'order_id' => rand(1000, 9999), 'total' => round(rand(1000, 50000) / 100, 2)];
        }

        $root = null;
        if (extension_loaded('ddtrace')) {
            $root = \DDTrace\start_span();
            $root->name = 'checkout.create_order';
            $root->type = 'custom';
            $root->meta['span.kind'] = 'internal';
            $root->meta['component'] = 'checkout';
        }
        $t = microtime(true);

        try {
            $this->pdo->beginTransaction();

            // 1. Random customer
            $sql  = 'SELECT id FROM customers ORDER BY RAND() LIMIT 1';
            $span = $this->spanOpen($sql, 'SELECT');
            $customerId = (int) $this->run($sql)[0]['id'];
            $this->spanClose($span, $t, 1);

            // 2. Random products
            $n    = rand(2, 5);
            $sql  = "SELECT id, price_cents FROM products WHERE stock > 0 ORDER BY RAND() LIMIT {$n}";
            $span = $this->spanOpen($sql, 'SELECT');
            $products = $this->run($sql);
            $this->spanClose($span, microtime(true), count($products));

            // 3. Insert order header
            $total = (int) array_sum(array_column($products, 'price_cents'));
            $sql   = "INSERT INTO orders (customer_id, status, total_cents) VALUES (?, 'pending', ?)";
            $span  = $this->spanOpen($sql, 'INSERT');
            $this->pdo->prepare($sql)->execute([$customerId, $total]);
            $orderId = (int) $this->pdo->lastInsertId();
            if ($span) $span->meta['db.order_id'] = (string) $orderId;
            $this->spanClose($span, microtime(true), 1);

            // 4. Insert order items
            $sql = 'INSERT INTO order_items (order_id, product_id, quantity, unit_price_cents) VALUES (?, ?, ?, ?)';
            foreach ($products as $p) {
                $qty  = rand(1, 3);
                $span = $this->spanOpen($sql, 'INSERT');
                $this->pdo->prepare($sql)->execute([$orderId, $p['id'], $qty, $p['price_cents']]);
                $this->spanClose($span, microtime(true), 1);
            }

            $this->pdo->commit();

            if ($root) {
                $root->meta['order.id']    = (string) $orderId;
                $root->meta['order.items'] = (string) count($products);
                $root->meta['order.total'] = (string) round($total / 100, 2);
                $root->meta['db.elapsed_ms'] = (string) round((microtime(true) - $t) * 1000, 2);
            }

            Logger::info('Random order created', [
                'order_id'    => $orderId,
                'customer_id' => $customerId,
                'items'       => count($products),
                'total'       => round($total / 100, 2),
            ]);

            return ['order_id' => $orderId, 'customer_id' => $customerId, 'items' => count($products), 'total' => round($total / 100, 2)];

        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            if ($root) { $root->meta['error'] = 'true'; $root->meta['error.message'] = $e->getMessage(); }
            Logger::exception($e, 'Create order transaction failed');
            throw $e;
        } finally {
            if ($root) \DDTrace\close_span();
        }
    }
}
