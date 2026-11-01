<?php
declare(strict_types=1);

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/HttpClient.php';

final class FixedSecurityTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO(getenv('FIXED_DB_DSN'), 'lab', getenv('FIXED_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('DELETE FROM login_limits');
    }

    private function client(): HttpClient
    {
        return new HttpClient(getenv('FIXED_URL') ?: 'http://127.0.0.1:8001');
    }

    public function testJwtClaimsAndAlgorithms(): void
    {
        $c = $this->client();
        $claims = ['sub' => '1', 'role' => 'user', 'iat' => time(), 'exp' => time() + 600, 'iss' => 'mini-bbl-fixed', 'aud' => 'mini-bbl'];
        $secret = getenv('JWT_SECRET');
        $c->token = JWT::encode($claims, $secret, 'HS256');
        self::assertSame(200, $c->request('GET', '/api/me')['status']);
        foreach ([['exp', time() - 1], ['iat', time() + 600], ['role', 'admin'], ['iss', 'wrong'], ['aud', 'wrong'], ['sub', 1], ['exp', time() + 9000]] as [$key, $value]) {
            $c->token = JWT::encode(array_replace($claims, [$key => $value]), $secret, 'HS256');
            self::assertSame(401, $c->request('GET', '/api/me')['status'], "Invalid $key must be rejected");
        }
        foreach (['exp', 'iat', 'sub', 'role'] as $key) {
            $missing = $claims;
            unset($missing[$key]);
            $c->token = JWT::encode($missing, $secret, 'HS256');
            self::assertSame(401, $c->request('GET', '/api/me')['status']);
        }
        $c->token = JWT::encode($claims, $secret, 'HS384');
        self::assertSame(401, $c->request('GET', '/api/me')['status']);
        $c->token = 'eyJhbGciOiJub25lIn0.eyJzdWIiOiI0In0.';
        self::assertSame(401, $c->request('GET', '/api/me')['status']);
    }

    public function testConcurrentThrottleAndExpiry(): void
    {
        $multi = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < 12; $i++) {
            $h = curl_init((getenv('FIXED_URL') ?: 'http://127.0.0.1:8001') . '/api/login');
            curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['username' => "rotated_$i", 'password' => 'wrong'])]);
            curl_multi_add_handle($multi, $h);
            $handles[] = $h;
        }
        do {
            $status = curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi, 0.2);
            }
        } while ($active && $status === CURLM_OK);
        $codes = [];
        foreach ($handles as $handle) {
            $codes[] = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);
        self::assertSame(5, count(array_filter($codes, static fn ($code) => $code === 401)));
        self::assertSame(7, count(array_filter($codes, static fn ($code) => $code === 429)));
        // Simulate the window expiring without sleeping a minute.
        $this->db->exec('UPDATE login_limits SET reset_at = UNIX_TIMESTAMP() - 1');
        $c = $this->client();
        self::assertSame(200, $c->request('POST', '/api/login', ['username' => 'alice', 'password' => 'alice12345'])['status']);
    }

    public function testPasswordHashingAndValidation(): void
    {
        require_once dirname(__DIR__) . '/app-fixed/src/Http.php';
        require_once dirname(__DIR__) . '/app-fixed/src/Auth.php';
        $first = Lab\Auth::hashPassword('a secure password');
        $second = Lab\Auth::hashPassword('a secure password');
        self::assertNotSame($first, $second);
        self::assertTrue(Lab\Auth::verifyPassword('a secure password', $first));
        self::assertFalse(Lab\Auth::verifyPassword('wrong', $first));
        $longPassword = str_repeat('a', 72) . 'original';
        $longHash = Lab\Auth::hashPassword($longPassword);
        self::assertTrue(Lab\Auth::verifyPassword($longPassword, $longHash));
        self::assertFalse(Lab\Auth::verifyPassword(str_repeat('a', 72) . 'changed', $longHash));
        $saved = getenv('JWT_SECRET');
        try {
            putenv('JWT_SECRET=secret');
            $this->expectException(RuntimeException::class);
            new Lab\Auth($this->db);
        } finally {
            putenv('JWT_SECRET=' . $saved);
        }
    }

    public function testMalformedRequestsAndPrivilegeInput(): void
    {
        $c = $this->client();
        self::assertSame(422, $c->request('POST', '/api/login', '{broken')['status']);
        self::assertSame(422, $c->request('POST', '/api/register', ['username' => 'xx', 'password' => 'short', 'email' => 'invalid'])['status']);
        $login = $c->request('POST', '/api/login', ['username' => 'alice', 'password' => 'alice12345']);
        $c->token = $login['body']['access_token'];
        self::assertSame(422, $c->request('PUT', '/api/me', ['email' => 'invalid'])['status']);
        self::assertSame(200, $c->request('PUT', '/api/me', ['role' => 'admin'])['status']);
        self::assertSame('user', $c->request('GET', '/api/me')['body']['role']);
        self::assertSame(422, $c->request('POST', '/api/checkout', ['product_id' => 1, 'quantity' => 1.5])['status']);
        self::assertSame(400, $c->request('POST', '/api/avatar')['status']);
        self::assertSame(404, $c->request('GET', '/src/Auth.php')['status']);
        self::assertSame(404, $c->request('GET', '/.env')['status']);
    }

    public function testUnexpectedExceptionBoundary(): void
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/failing-bootstrap.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $body = stream_get_contents($pipes[1]);
        $diagnostic = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $diagnostic);
        self::assertSame(['detail' => 'Internal server error'], json_decode($body, true));
        self::assertStringNotContainsString('private-configuration-marker', $body);
        self::assertStringContainsString('PDOException', $diagnostic);
    }
}
