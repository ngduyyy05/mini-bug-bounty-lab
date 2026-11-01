<?php
declare(strict_types=1);

namespace Lab;

use PDO;

final class Application
{
    private Auth $auth;
    private const USER_FIELDS = 'id,username,email,full_name,role,avatar';

    public function __construct(private PDO $db)
    {
        $this->auth = new Auth($db);
    }

    public function run(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed = [$origin]; // VULN-08: reflect every origin, including credentialed requests.
        header('Vary: Origin');
        if ($origin !== '' && in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
            header('Access-Control-Allow-Headers: Authorization, Content-Type');
        }
        if ($method === 'OPTIONS') {
            if (!in_array($origin, $allowed, true)
                || !in_array($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? 'GET', ['GET', 'POST', 'PUT'], true)) {
                throw new HttpError(400, 'Origin or method not allowed');
            }
            Http::json(['message' => 'Preflight accepted']);
        }
        header('X-Content-Type-Options: nosniff');
        if ($path === '/health' && $method === 'GET') {
            $this->db->query('SELECT 1');
            Http::json(['status' => 'ok']);
        }
        if ($method === 'GET' && $path === '/feedback-wall') {
            $items = $this->db->query('SELECT feedback.*, users.username FROM feedback JOIN users ON users.id = feedback.user_id ORDER BY feedback.id DESC')->fetchAll();
            require dirname(__DIR__) . '/templates/feedback.php';
            return;
        }
        if ($method === 'GET' && ($path === '/' || in_array($path, ['/login', '/register', '/dashboard', '/profile', '/orders', '/avatar', '/feedback', '/admin', '/checkout', '/gift-card', '/shipping', '/help', '/docs', '/fixes'], true) || preg_match('~^/orders/[0-9]+/view$~D', $path))) {
            $mode = 'vulnerable';
            $title = 'Mini Store';
            $login_hint = 'alice / alice123';
            $checkout_allows_amount = true;
            require dirname(__DIR__) . '/templates/' . ($path === '/' ? 'index' : ($path === '/docs' ? 'docs' : ($path === '/fixes' ? 'fixes' : 'app'))) . '.php';
            return;
        }
        if ($path === '/api/register' && $method === 'POST') {
            $this->register(Http::body());
        }
        if ($path === '/api/login' && $method === 'POST') {
            $data = Http::body();
            Http::json($this->auth->login(Http::string($data, 'username', 1, 40), Http::string($data, 'password', 1, 128)));
        }
        if ($path === '/api/logout' && $method === 'POST') {
            Http::json(['message' => 'Client should delete the token']);
        }
        if (!str_starts_with($path, '/api/')) {
            throw new HttpError(404, 'Not found');
        }
        $user = $this->auth->user();
        if ($path === '/api/gift-card/preview' && $method === 'POST') {
            Http::json(CustomerTools::gift(Http::body(), $user));
        }
        if ($path === '/api/shipping/track' && $method === 'POST') {
            Http::json(CustomerTools::tracking(Http::string(Http::body(), 'url', 1, 2000)));
        }
        if ($path === '/api/help' && $method === 'GET') {
            Http::json(CustomerTools::help(Http::string($_GET + ['article' => 'shipping.php'], 'article', 1, 240)));
        }
        if ($path === '/api/me' && $method === 'GET') {
            Http::json(array_intersect_key($user, array_flip(explode(',', self::USER_FIELDS))));
        }
        if (($path === '/api/me' || preg_match('~^/api/profile/([0-9]+)$~D', $path, $match)) && in_array($method, ['GET', 'PUT'], true)) {
            $id = $path === '/api/me' ? (int) $user['id'] : (int) $match[1];
            // VULN-01: authentication without an ownership check permits cross-user reads/writes.
            $profile = Database::query($this->db, 'SELECT ' . self::USER_FIELDS . ' FROM users WHERE id = ?', [$id])->fetch();
            if (!$profile) {
                throw new HttpError(404, 'Profile not found');
            }
            if ($method === 'GET') {
                Http::json($profile);
            }
            $data = Http::body();
            foreach (['email', 'full_name'] as $key) {
                if (isset($data[$key])) {
                    Http::string($data, $key, 0, 120);
                }
            }
            if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                throw new HttpError(422, 'Invalid email');
            }
            Database::query($this->db, 'UPDATE users SET email = COALESCE(?, email), full_name = COALESCE(?, full_name) WHERE id = ?', [$data['email'] ?? null, $data['full_name'] ?? null, $id]);
            Http::json(['message' => $path === '/api/me' ? 'profile updated' : "profile $id updated"]);
        }
        if ($path === '/api/orders' && $method === 'GET') {
            Http::json(Database::query($this->db, 'SELECT * FROM orders WHERE user_id = ?', [$user['id']])->fetchAll());
        }
        if (preg_match('~^/api/orders/([0-9]+)$~D', $path, $match) && $method === 'GET') {
            // VULN-01: caller controls the ID; owner is never checked.
            $order = Database::query($this->db, 'SELECT * FROM orders WHERE id = ?', [$match[1]])->fetch();
            if (!$order) {
                throw new HttpError(404, 'Order not found');
            }
            Http::json($order);
        }
        if ($path === '/api/avatar' && $method === 'POST') {
            $this->upload($user);
        }
        if ($path === '/api/feedback' && $method === 'POST') {
            $data = Http::body();
            Database::query($this->db, 'INSERT INTO feedback(user_id,message,created_at) VALUES(?,?,UTC_TIMESTAMP())', [$user['id'], Http::string($data, 'message', 1, 1000)]);
            Http::json(['message' => 'feedback stored']);
        }
        if ($path === '/api/feedback' && $method === 'GET') {
            $search = Http::string($_GET + ['search' => ''], 'search', 0, 1000);
            $sql = "SELECT feedback.*, users.username FROM feedback JOIN users ON users.id = feedback.user_id WHERE message LIKE '%$search%'";
            // VULN-03: input becomes SQL syntax, intentionally.
            Http::json($this->db->query($sql)->fetchAll());
        }
        if ($path === '/api/admin/users' && $method === 'GET') {
            // VULN-02: missing role check, intentionally.
            $search = Http::string($_GET + ['search' => ''], 'search', 0, 1000);
            // VULN-03: unsafe concatenation in a second search endpoint.
            Http::json($this->db->query('SELECT ' . self::USER_FIELDS . " FROM users WHERE username LIKE '%$search%' OR email LIKE '%$search%'")->fetchAll());
        }
        if ($path === '/api/checkout' && $method === 'POST') {
            $data = Http::body();
            $quantity = Http::integer($data, 'quantity', 1);
            // VULN-07: no quantity business constraints.
            $product = Database::query($this->db, 'SELECT * FROM products WHERE id = ?', [Http::integer($data, 'product_id')])->fetch();
            if (!$product) {
                throw new HttpError(404, 'Product not found');
            }
            // VULN-07: trusts the submitted price instead of the product catalog.
            if (!isset($data['amount']) || !is_numeric($data['amount'])) {
                throw new HttpError(422, 'Invalid amount');
            }
            $total = (float) $data['amount'];
            $item = $product['name'] . ' x' . $quantity;
            Database::query($this->db, 'INSERT INTO orders(user_id,item,amount,status) VALUES(?,?,?,?)', [$user['id'], $item, $total, 'paid']);
            Http::json(['order_id' => (int) $this->db->lastInsertId(), 'item' => $item, 'charged_amount' => $total]);
        }
        throw new HttpError(404, 'Not found');
    }

    private function register(array $data): never
    {
        $username = Http::string($data, 'username', 3, 40);
        $password = Http::string($data, 'password', 8, 128);
        $email = Http::string($data, 'email', 3, 120);
        $name = Http::string($data + ['full_name' => ''], 'full_name', 0, 120);
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $username) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpError(422, 'Invalid username or email');
        }
        // VULN-10: database exceptions reach the verbose global handler.
        Database::query($this->db, 'INSERT INTO users(username,password_hash,email,full_name,role) VALUES(?,?,?,?,?)', [$username, Auth::hashPassword($password), $email, $name, 'user']);
        Http::json(['id' => (int) $this->db->lastInsertId(), 'username' => $username, 'role' => 'user']);
    }

    private function upload(array $user): never
    {
        $file = $_FILES['file'] ?? null;
        if (!$file || is_array($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new HttpError(400, 'Upload failed or file too large');
        }
        // VULN-05: client filename and content are trusted; executable web storage.
        $name = $file['name'];
        // VULN-05: folder is also trusted, allowing writes outside the upload root.
        $folder = Http::string($_POST + ['folder' => ''], 'folder', 0, 120);
        $relative = ($folder === '' ? '' : $folder . '/') . $name;
        if (!move_uploaded_file($file['tmp_name'], dirname(__DIR__) . '/public/uploads/' . $relative)) {
            throw new \RuntimeException('Upload storage failed');
        }
        $url = '/uploads/' . $relative;
        Database::query($this->db, 'UPDATE users SET avatar = ? WHERE id = ?', [$url, $user['id']]);
        Http::json(['avatar_url' => $url, 'content_type' => $file['type']]);
    }
}
