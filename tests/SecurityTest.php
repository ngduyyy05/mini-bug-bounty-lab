<?php
declare(strict_types=1);

use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/HttpClient.php';

final class SecurityTest extends TestCase
{
    private ?HttpClient $client = null;
    private string $mode = '';

    public static function apps(): array
    {
        return ['vulnerable' => [true], 'fixed' => [false]];
    }

    protected function setUp(): void
    {
        // Test infrastructure only: deterministic throttle state without an application reset endpoint.
        $db = new PDO(getenv('FIXED_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=lab;charset=utf8mb4', 'lab', getenv('FIXED_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('DELETE FROM login_limits');
    }

    private function lab(bool $vulnerable, string $actor = 'alice'): HttpClient
    {
        $this->mode = $vulnerable ? 'vulnerable' : 'fixed';
        $base = getenv($vulnerable ? 'VULNERABLE_URL' : 'FIXED_URL') ?: ($vulnerable ? 'http://127.0.0.1:8000' : 'http://127.0.0.1:8001');
        $previousEvidence = $this->client?->evidence ?? [];
        $this->client = new HttpClient($base);
        $this->client->evidence = $previousEvidence;
        $response = $this->client->request('POST', '/api/login', ['username' => $actor, 'password' => $actor . ($vulnerable ? '123' : '12345')]);
        self::assertSame(200, $response['status'], 'Authentication positive control');
        $this->client->token = $response['body']['access_token'];
        return $this->client;
    }

    protected function tearDown(): void
    {
        if ($this->client) {
            $directory = dirname(__DIR__) . '/artifacts';
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory . '/' . $this->name() . '-' . $this->mode . '.json', json_encode([
                'test' => $this->name(), 'mode' => $this->mode, 'captured_at' => gmdate(DATE_ATOM),
                'requests' => $this->client->evidence,
                'note' => 'HTTP observations; use JUnit for assertion outcomes.'
            ], JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }

    #[DataProvider('apps')]
    public function test01ObjectAuthorization(bool $vulnerable): void
    {
        $c = $this->lab($vulnerable);
        self::assertSame(1, $c->request('GET', '/api/orders/1')['body']['user_id']);
        self::assertSame(404, $c->request('GET', '/api/orders/999999')['status']);
        self::assertSame($vulnerable ? 200 : 404, $c->request('GET', '/api/orders/3')['status']);
        self::assertSame(200, $c->request('GET', '/api/profile/1')['status']);
        self::assertSame($vulnerable ? 200 : 403, $c->request('GET', '/api/profile/2')['status']);
        self::assertSame($vulnerable ? 200 : 403, $c->request('PUT', '/api/profile/2', ['full_name' => 'Bob Tran'])['status']);
        $c->token = '';
        self::assertSame(401, $c->request('GET', '/api/orders/1')['status']);
        $c = $this->lab($vulnerable, 'admin');
        self::assertSame(200, $c->request('GET', '/api/orders/3')['status']);
        self::assertSame(200, $c->request('GET', '/api/profile/2')['status']);
    }

    #[DataProvider('apps')]
    public function test02RoleAuthorization(bool $vulnerable): void
    {
        $c = $this->lab($vulnerable);
        self::assertSame($vulnerable ? 200 : 403, $c->request('GET', '/api/admin/users')['status']);
        $c = $this->lab($vulnerable, 'staff');
        self::assertSame($vulnerable ? 200 : 403, $c->request('GET', '/api/admin/users')['status']);
        $c = $this->lab($vulnerable, 'admin');
        $response = $c->request('GET', '/api/admin/users?search=alice');
        self::assertSame(200, $response['status']);
        self::assertSame('alice', $response['body'][0]['username']);
        self::assertArrayNotHasKey('password_hash', $response['body'][0]);
    }

    #[DataProvider('apps')]
    public function test03SqlInjection(bool $vulnerable): void
    {
        $c = $this->lab($vulnerable);
        self::assertNotEmpty($c->request('GET', '/api/feedback?search=delivery')['body']);
        self::assertSame([], $c->request('GET', '/api/feedback?search=unmatched_lab_marker')['body']);
        $payload = rawurlencode("unmatched_lab_marker' OR 1=1 -- ");
        $response = $c->request('GET', '/api/feedback?search=' . $payload);
        self::assertSame(200, $response['status']);
        self::assertSame($vulnerable, count($response['body']) > 0);
        $c = $this->lab($vulnerable, 'admin');
        $response = $c->request('GET', '/api/admin/users?search=' . $payload);
        self::assertSame(200, $response['status']);
        self::assertSame($vulnerable, count($response['body']) > 0);
    }

    #[DataProvider('apps')]
    public function test04StoredXss(bool $vulnerable): void
    {
        $c = $this->lab($vulnerable);
        $marker = '<script>window.labXssMarker=1</script>';
        self::assertSame(200, $c->request('POST', '/api/feedback', ['message' => $marker])['status']);
        $wall = $c->request('GET', '/feedback-wall');
        self::assertSame(200, $wall['status']);
        self::assertSame($vulnerable, str_contains($wall['text'], $marker));
        if (!$vulnerable) {
            self::assertStringContainsString('&lt;script&gt;', $wall['text']);
        }
    }

    #[DataProvider('apps')]
    public function test05UploadValidation(bool $vulnerable): void
    {
        $c = $this->lab($vulnerable);
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $valid = $c->upload('avatar.png', $image, 'image/png');
        self::assertSame(200, $valid['status'], 'Valid image positive control');
        $download = $c->request('GET', $valid['body']['avatar_url']);
        self::assertSame(200, $download['status']);
        self::assertSame('image/png', $download['headers']['content-type']);
        $name = 'marker-' . bin2hex(random_bytes(4)) . '.php';
        $response = $c->upload($name, '<?php echo "LAB_UPLOAD_EXECUTED"; ?>', 'image/png');
        self::assertSame($vulnerable ? 200 : 400, $response['status']);
        if ($vulnerable) {
            self::assertSame('LAB_UPLOAD_EXECUTED', $c->request('GET', $response['body']['avatar_url'])['text']);
        }
        self::assertSame($vulnerable ? 200 : 400, $c->upload('fake.png', 'not an image', 'image/png')['status']);
        self::assertSame($vulnerable ? 200 : 413, $c->upload('large.png', $image . str_repeat('x', 1000001), 'image/png')['status']);
        $polyglot = $c->upload('polyglot.png', $image . '<?php echo "REMOVEME"; ?>', 'image/png');
        self::assertSame(200, $polyglot['status']);
        if (!$vulnerable) {
            $stored = $c->request('GET', $polyglot['body']['avatar_url']);
            self::assertStringNotContainsString('REMOVEME', $stored['text']);
            self::assertSame("default-src 'none'; sandbox", $stored['headers']['content-security-policy']);
        }
    }

    #[DataProvider('apps')]
    public function test06WeakJwtSecret(bool $vulnerable): void
    {
        $c = $this->lab($vulnerable);
        self::assertSame('alice', $c->request('GET', '/api/me')['body']['username']);
        // Forge a different subject; changing a role alone is not proof of impersonation.
        $encode = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $head = $encode('{"alg":"HS256","typ":"JWT"}');
        $body = $encode(json_encode(['sub' => '4', 'role' => 'admin', 'iat' => time(), 'exp' => time() + 600,
            'iss' => 'mini-bbl-fixed', 'aud' => 'mini-bbl']));
        $c->token = "$head.$body." . $encode(hash_hmac('sha256', "$head.$body", 'secret', true));
        $response = $c->request('GET', '/api/me');
        self::assertSame($vulnerable ? 200 : 401, $response['status']);
        if ($vulnerable) {
            self::assertSame('admin', $response['body']['username']);
        }
    }

    #[DataProvider('apps')]
    public function test07BusinessRules(bool $vulnerable): void
    {
        $c = $this->lab($vulnerable);
        $normal = $c->request('POST', '/api/checkout', ['product_id' => 1, 'quantity' => 2, 'amount' => 39.98]);
        self::assertSame(200, $normal['status']);
        self::assertEquals(39.98, $normal['body']['charged_amount']);
        $response = $c->request('POST', '/api/checkout', ['product_id' => 1, 'quantity' => 1, 'amount' => 0.01]);
        self::assertSame(200, $response['status']);
        self::assertEquals($vulnerable ? 0.01 : 19.99, $response['body']['charged_amount']);
        foreach ([0, -1, 21] as $quantity) {
            self::assertSame($vulnerable ? 200 : 422, $c->request('POST', '/api/checkout', ['product_id' => 1, 'quantity' => $quantity, 'amount' => 0.01])['status']);
        }
        self::assertSame(404, $c->request('POST', '/api/checkout', ['product_id' => 999999, 'amount' => 1])['status']);
    }

    #[DataProvider('apps')]
    public function test08CorsOrigins(bool $vulnerable): void
    {
        $c = $this->lab($vulnerable);
        $hostile = $c->request('OPTIONS', '/api/me', null, ['Origin: https://untrusted.invalid', 'Access-Control-Request-Method: GET', 'Access-Control-Request-Headers: Authorization']);
        self::assertSame($vulnerable ? 200 : 400, $hostile['status']);
        self::assertSame($vulnerable, isset($hostile['headers']['access-control-allow-origin']));
        $trusted = $c->request('OPTIONS', '/api/me', null, ['Origin: http://localhost:8001', 'Access-Control-Request-Method: GET']);
        self::assertSame(200, $trusted['status']);
        self::assertSame('http://localhost:8001', $trusted['headers']['access-control-allow-origin']);
        $actual = $c->request('GET', '/api/me', null, ['Origin: https://untrusted.invalid']);
        self::assertSame(200, $actual['status']); // CORS is browser policy, not authorization.
        self::assertSame($vulnerable, isset($actual['headers']['access-control-allow-origin']));
    }

    #[DataProvider('apps')]
    public function test09LoginThrottling(bool $vulnerable): void
    {
        $c = $this->lab($vulnerable);
        // The successful positive control consumed one of the five reservations.
        for ($i = 0; $i < 4; $i++) {
            self::assertSame(401, $c->request('POST', '/api/login', ['username' => 'missing-user', 'password' => 'wrong'])['status']);
        }
        $response = $c->request('POST', '/api/login', ['username' => 'rotated-username', 'password' => 'wrong']);
        self::assertSame($vulnerable ? 401 : 429, $response['status']);
        if (!$vulnerable) {
            self::assertSame('60', $response['headers']['retry-after']);
        }
    }

    #[DataProvider('apps')]
    public function test10InformationDisclosure(bool $vulnerable): void
    {
        $c = $this->lab($vulnerable);
        $response = $c->request('POST', '/api/register', ['username' => 'alice', 'password' => 'validpassword', 'email' => 'alice@example.local']);
        self::assertSame($vulnerable ? 500 : 409, $response['status']);
        self::assertSame($vulnerable, isset($response['body']['trace']));
        self::assertSame($vulnerable, isset($response['body']['db_dsn']));
        $error = $c->request('GET', '/api/feedback?search=' . rawurlencode("'"));
        self::assertSame($vulnerable ? 500 : 200, $error['status']);
        self::assertSame($vulnerable, str_contains($error['text'], 'SQLSTATE'));
    }

    #[DataProvider('apps')]
    public function test05UploadTraversalLfi(bool $vulnerable): void
    {
        $c = $this->lab($vulnerable);
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $name = 'include-' . bin2hex(random_bytes(6)) . '.png';
        $content = $image . '<?php echo "LAB_LFI_" . (6 * 7); ?>';
        $normal = $c->upload($name, $content, 'image/png', 'profile');
        self::assertSame(200, $normal['status'], 'Named folder positive control');
        $stored = $c->request('GET', $normal['body']['avatar_url']);
        self::assertSame(200, $stored['status']);
        self::assertStringNotContainsString('LAB_LFI_42', $stored['text'], 'Static image delivery does not interpret PHP');
        if (!$vulnerable) {
            self::assertStringNotContainsString('<?php', $stored['text']);
        }
        foreach (['shipping.php', 'returns.php'] as $article) {
            $help = $c->request('GET', '/api/help?article=' . $article);
            self::assertSame(200, $help['status']);
            self::assertNotEmpty($help['body']['content']);
        }
        self::assertSame(404, $c->request('GET', '/api/help?article=missing.php')['status']);
        $upload = $c->upload($name, $content, 'image/png', '../drafts');
        self::assertSame($vulnerable ? 200 : 400, $upload['status'], 'Traversal write outside uploads');
        $include = $c->request('GET', '/api/help?article=' . rawurlencode('../../public/drafts/' . $name));
        self::assertSame($vulnerable ? 200 : 404, $include['status']);
        if ($vulnerable) {
            self::assertStringContainsString('LAB_LFI_42', $include['body']['content']);
            self::assertStringNotContainsString('<?php', $include['body']['content']);
        }
        // A separate include check proves the fixed article allowlist blocks even
        // an ordinary accepted upload, independent of the traversal-write fix.
        $includeStored = $c->request('GET', '/api/help?article=' . rawurlencode('../../public' . $normal['body']['avatar_url']));
        self::assertSame($vulnerable ? 200 : 404, $includeStored['status']);
        if ($vulnerable) {
            self::assertStringContainsString('LAB_LFI_42', $includeStored['body']['content']);
        }
    }

    #[DataProvider('apps')]
    public function test11ServerSideTemplateInjection(bool $vulnerable): void
    {
        $c = $this->lab($vulnerable);
        $normal = $c->request('POST', '/api/gift-card/preview', ['recipient' => 'Bob', 'template' => 'Hello {{ recipient }} from {{ sender }}']);
        self::assertSame(200, $normal['status']);
        self::assertStringStartsWith('Hello Bob from ', $normal['body']['preview']);
        $arithmetic = $c->request('POST', '/api/gift-card/preview', ['recipient' => 'Bob', 'template' => '{{ 7 * 7 }}']);
        self::assertSame(200, $arithmetic['status']);
        self::assertSame($vulnerable ? '49' : '{{ 7 * 7 }}', $arithmetic['body']['preview']);
        $loop = '{% for i in 1..3 %}{{ i }}{% endfor %}';
        $result = $c->request('POST', '/api/gift-card/preview', ['recipient' => 'Bob', 'template' => $loop]);
        self::assertSame(200, $result['status']);
        self::assertSame($vulnerable ? '123' : $loop, $result['body']['preview']);
        $context = $c->request('POST', '/api/gift-card/preview', ['recipient' => '{{ 7 * 7 }}', 'template' => '{{recipient}}']);
        self::assertSame('{{ 7 * 7 }}', $context['body']['preview'], 'Values must not be recompiled');
        self::assertSame(422, $c->request('POST', '/api/gift-card/preview', ['recipient' => [], 'template' => 'Hello'])['status']);
        $c->token = '';
        self::assertSame(401, $c->request('POST', '/api/gift-card/preview', ['recipient' => 'Bob', 'template' => 'Hello'])['status']);
    }

    #[DataProvider('apps')]
    public function test12ServerSideRequestForgery(bool $vulnerable): void
    {
        $c = $this->lab($vulnerable);
        $normal = $c->request('POST', '/api/shipping/track', ['url' => 'http://delivery-service/tracking/DEMO1001']);
        self::assertSame(200, $normal['status']);
        self::assertSame('DEMO1001', json_decode($normal['body']['content'], true)['tracking_id']);
        foreach (['http://delivery-service/internal/dispatch', 'http://delivery-service/tracking/REDIRECT1', 'http://127.0.0.1/health'] as $url) {
            $response = $c->request('POST', '/api/shipping/track', ['url' => $url]);
            $expectedFixed = str_ends_with($url, 'REDIRECT1') ? 502 : 422;
            self::assertSame($vulnerable ? 200 : $expectedFixed, $response['status'], $url);
            if ($vulnerable) {
                $data = json_decode($response['body']['content'], true);
                if (str_ends_with($url, '/health')) {
                    self::assertSame('ok', $data['status']);
                } else {
                    self::assertSame('local-dispatch-demo-token', $data['internal_token']);
                }
            }
        }
        self::assertSame(422, $c->request('POST', '/api/shipping/track', ['url' => 'file:///etc/hosts'])['status']);
        if (!$vulnerable) {
            foreach ([
                'http://[::1]/health', 'http://2130706433/health',
                'http://delivery-service.evil.invalid/tracking/DEMO1001',
                'http://delivery-service@127.0.0.1/health',
                'http://delivery-service:80/tracking/DEMO1001',
                'http://delivery-service/tracking/../internal/dispatch',
                'http://delivery-service/tracking/%2e%2e/internal/dispatch',
                'http://delivery-service/tracking/DEMO1001?redirect=/internal/dispatch',
                "http://delivery-service/tracking/DEMO1001\n",
            ] as $url) {
                self::assertSame(422, $c->request('POST', '/api/shipping/track', ['url' => $url])['status'], $url);
            }
        }
        $c->token = '';
        self::assertSame(401, $c->request('POST', '/api/shipping/track', ['url' => 'http://delivery-service/tracking/DEMO1001'])['status']);
        self::assertSame(401, $c->request('GET', '/api/help?article=shipping.php')['status']);
    }

    #[DataProvider('apps')]
    public function testLegitimateFlowsAndPages(bool $vulnerable): void
    {
        $c = $this->lab($vulnerable);
        foreach (['/', '/login', '/register', '/dashboard', '/profile', '/orders', '/orders/1/view', '/avatar', '/feedback', '/admin', '/checkout', '/gift-card', '/shipping', '/help', '/fixes', '/docs'] as $path) {
            $response = $c->request('GET', $path);
            self::assertSame(200, $response['status'], $path);
            self::assertStringContainsString('<!doctype html>', strtolower($response['text']));
            self::assertStringNotContainsString('<?php', $response['text']);
        }
        self::assertGreaterThanOrEqual(2, count($c->request('GET', '/api/orders')['body']));
        self::assertSame(200, $c->request('PUT', '/api/me', ['full_name' => 'Alice Nguyen', 'email' => 'alice@example.local'])['status']);
        $username = 'test_' . bin2hex(random_bytes(6));
        $registration = $c->request('POST', '/api/register', ['username' => $username, 'password' => 'testpassword123', 'email' => "$username@example.local", 'role' => 'admin']);
        self::assertSame(200, $registration['status']);
        self::assertSame('user', $registration['body']['role']);
        $login = $c->request('POST', '/api/login', ['username' => $username, 'password' => 'testpassword123']);
        self::assertSame(200, $login['status']);
        $c->token = $login['body']['access_token'];
        self::assertSame([], $c->request('GET', '/api/orders')['body']);
        self::assertSame(200, $c->request('POST', '/api/logout')['status']);
    }
}
