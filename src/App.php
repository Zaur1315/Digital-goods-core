<?php
declare(strict_types=1);

final class App
{
    private PDO $db;

    public function __construct()
    {
        $dsn = getenv('DATABASE_DSN') ?: 'sqlite:' . dirname(__DIR__) . '/var/store.sqlite';
        if (str_starts_with($dsn, 'sqlite:')) @mkdir(dirname(substr($dsn, 7)), 0775, true);
        $this->db = new PDO($dsn, getenv('DB_USER') ?: '', getenv('DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA busy_timeout=10000');
        $this->migrate();
    }

    public function handle(): void
    {
        header('Content-Type: application/json');
        try {
            $method = $_SERVER['REQUEST_METHOD'];
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            if ($method === 'POST' && $path === '/orders') $this->json($this->createOrder($body), 201);
            elseif ($method === 'POST' && $path === '/webhooks/payment') $this->json($this->payment($body));
            elseif ($method === 'POST' && $path === '/reconcile') $this->json($this->reconcile());
            elseif ($method === 'POST' && $path === '/worker') $this->json($this->worker());
            elseif ($method === 'GET' && $path === '/catalog') $this->json($this->catalog($_GET));
            elseif ($method === 'GET' && preg_match('#^/orders/([a-f0-9-]+)$#', $path, $m)) $this->json($this->order($m[1]));
            else $this->json(['error' => 'not_found'], 404);
        } catch (Throwable $e) {
            $this->log('error', ['message' => $e->getMessage()]);
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    private function migrate(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS catalog (id INTEGER PRIMARY KEY AUTOINCREMENT, sku TEXT UNIQUE NOT NULL, title TEXT NOT NULL, price_cents INTEGER NOT NULL, stock INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS catalog_hot ON catalog(active, stock DESC, id)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS orders (id TEXT PRIMARY KEY, sku TEXT NOT NULL, customer TEXT NOT NULL, amount_cents INTEGER NOT NULL, status TEXT NOT NULL, delivery_code TEXT, delivery_provider TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS payments (payment_id TEXT PRIMARY KEY, order_id TEXT UNIQUE NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS payment_events (event_id TEXT PRIMARY KEY, payment_id TEXT NOT NULL, order_id TEXT NOT NULL, created_at TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS supplier_reservations (provider TEXT NOT NULL, idempotency_key TEXT NOT NULL, code TEXT NOT NULL, created_at TEXT NOT NULL, PRIMARY KEY(provider,idempotency_key))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, event TEXT NOT NULL, order_id TEXT, data TEXT NOT NULL, created_at TEXT NOT NULL)');
        if ((int)$this->db->query('SELECT COUNT(*) FROM catalog')->fetchColumn() === 0) $this->db->exec("INSERT INTO catalog(sku,title,price_cents,stock) VALUES ('GAME-100','Demo Game 100',1999,100),('GAME-200','Demo Game 200',2999,100)");
    }

    private function createOrder(array $b): array
    {
        $sku = trim((string)($b['sku'] ?? ''));
        $customer = trim((string)($b['customer'] ?? ''));
        $s = $this->db->prepare('SELECT * FROM catalog WHERE sku=? AND active=1');
        $s->execute([$sku]);
        $item = $s->fetch();
        if (!$item || $customer === '') throw new InvalidArgumentException('invalid sku or customer');
        $id = $this->uuid();
        $now = gmdate('c');
        $q = $this->db->prepare('INSERT INTO orders VALUES(?,?,?,?,?,?,?,?,?)');
        $q->execute([$id, $sku, $customer, $item['price_cents'], 'pending_payment', null, null, $now, $now]);
        $this->log('order.created', ['sku' => $sku, 'amount_cents' => $item['price_cents']], $id);
        return $this->order($id);
    }

    private function payment(array $b): array
    {
        $id = (string)($b['order_id'] ?? '');
        $payment = (string)($b['payment_id'] ?? '');
        $status = (string)($b['status'] ?? '');
        if ($id === '' || $payment === '' || $status !== 'succeeded') throw new InvalidArgumentException('invalid payment webhook');
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q = $this->db->prepare('SELECT * FROM orders WHERE id=?');
            $q->execute([$id]);
            $o = $q->fetch();
            if (!$o) throw new InvalidArgumentException('order not found');
            $event = 'payment:' . $payment;
            $ins = $this->db->prepare('INSERT OR IGNORE INTO payment_events VALUES(?,?,?,?)');
            $ins->execute([$event, $payment, $id, gmdate('c')]);
            $p = $this->db->prepare('INSERT OR IGNORE INTO payments VALUES(?,?,?,?)');
            $p->execute([$payment, $id, 'succeeded', gmdate('c')]);
            if ($o['status'] === 'pending_payment') {
                $u = $this->db->prepare("UPDATE orders SET status='paid',updated_at=? WHERE id=?");
                $u->execute([gmdate('c'), $id]);
                $o['status'] = 'paid';
            }
            $this->db->exec('COMMIT');
        } catch (Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
        if ($o['status'] !== 'delivered') $this->deliver($id);
        return $this->order($id);
    }

    private function deliver(string $id): void
    {
        $this->db->exec('BEGIN IMMEDIATE');
        $q = $this->db->prepare('SELECT * FROM orders WHERE id=?');
        $q->execute([$id]);
        $o = $q->fetch();
        if (!$o || $o['status'] === 'delivered' || $o['status'] === 'pending_payment') {
            $this->db->exec('COMMIT');
            return;
        }
        $u = $this->db->prepare("UPDATE orders SET status='delivering',updated_at=? WHERE id=?");
        $u->execute([gmdate('c'), $id]);
        $this->db->exec('COMMIT');
        $result = null;
        $last = null;
        foreach (['A', 'B'] as $provider) {
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                try {
                    $result = $this->supplier($provider, 'delivery:' . $id, $o['sku']);
                    break 2;
                } catch (Throwable $e) {
                    $last = $e;
                    usleep(50000 * $attempt);
                }
            }
        }
        if (!$result) {
            $this->setStatus($id, 'paid');
            $this->log('delivery.failed', ['error' => $last?->getMessage()], $id);
            return;
        }
        $q = $this->db->prepare("UPDATE orders SET status='delivered',delivery_code=?,delivery_provider=?,updated_at=? WHERE id=? AND status='delivering'");
        $q->execute([$result['code'], $result['provider'], gmdate('c'), $id]);
        $this->log('delivery.completed', ['provider' => $result['provider']], $id);
    }

    private function supplier(string $provider, string $key, string $sku): array
    {
        $q = $this->db->prepare('SELECT code FROM supplier_reservations WHERE provider=? AND idempotency_key=?');
        $q->execute([$provider, $key]);
        if ($code = $q->fetchColumn()) return ['provider' => $provider, 'code' => $code];
        $mode = getenv('SUPPLIER_' . $provider . '_MODE') ?: (getenv('SUPPLIER_MODE') ?: 'normal');
        if ($mode === 'fail') throw new RuntimeException("supplier_$provider failed");
        $code = 'CODE-' . $provider . '-' . strtoupper(substr(hash('sha256', $key), 0, 12));
        $i = $this->db->prepare('INSERT INTO supplier_reservations VALUES(?,?,?,?)');
        $i->execute([$provider, $key, $code, gmdate('c')]);
        if ($mode === 'timeout') {
            usleep(300000);
            throw new RuntimeException("supplier_$provider timeout");
        }
        return ['provider' => $provider, 'code' => $code];
    }

    private function worker(): array
    {
        $n = 0;
        foreach ($this->db->query("SELECT id FROM orders WHERE status IN ('paid','delivering') ORDER BY updated_at LIMIT 100") as $o) {
            $this->deliver($o['id']);
            $n++;
        }
        return ['processed' => $n];
    }

    private function reconcile(): array
    {
        $a = $this->db->query("SELECT id FROM orders WHERE status IN ('paid','delivering')")->fetchAll(PDO::FETCH_COLUMN);
        $b = $this->db->query("SELECT id FROM orders WHERE status='delivered' AND id NOT IN (SELECT order_id FROM payments WHERE status='succeeded')")->fetchAll(PDO::FETCH_COLUMN);
        return ['paid_not_delivered' => $a, 'delivered_not_paid' => $b];
    }

    private function catalog(array $p): array
    {
        $limit = min(100, max(1, (int)($p['limit'] ?? 50)));
        $offset = max(0, (int)($p['offset'] ?? 0));
        $q = $this->db->prepare('SELECT sku,title,price_cents,stock FROM catalog WHERE active=1 ORDER BY stock DESC,id LIMIT ? OFFSET ?');
        $q->bindValue(1, $limit, PDO::PARAM_INT);
        $q->bindValue(2, $offset, PDO::PARAM_INT);
        $q->execute();
        return ['items' => $q->fetchAll()];
    }

    private function order(string $id): array
    {
        $q = $this->db->prepare('SELECT * FROM orders WHERE id=?');
        $q->execute([$id]);
        $o = $q->fetch();
        if (!$o) throw new InvalidArgumentException('order not found');
        return $o;
    }

    private function setStatus(string $id, string $s): void
    {
        $q = $this->db->prepare('UPDATE orders SET status=?,updated_at=? WHERE id=?');
        $q->execute([$s, gmdate('c'), $id]);
    }

    private function log(string $event, array $data = [], ?string $id = null): void
    {
        $q = $this->db->prepare('INSERT INTO audit_log(event,order_id,data,created_at) VALUES(?,?,?,?)');
        $q->execute([$event, $id, json_encode($data, JSON_UNESCAPED_UNICODE), gmdate('c')]);
    }

    private function json(mixed $v, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function uuid(): string
    {
        return sprintf('%s-%s-%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(6)));
    }
}
