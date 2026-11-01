<?php
declare(strict_types=1);

namespace Lab;

use PDO;

/** Intentionally insecure training implementation. Never imported by app-fixed. */
final class Auth
{
    // VULN-06: a known dictionary secret allows arbitrary subject impersonation.
    private string $secret = 'secret';

    public function __construct(private PDO $db) {}

    public static function hashPassword(string $password): string
    {
        return hash('sha256', $password);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return hash('sha256', $password) === $hash;
    }

    public function token(array $user): string
    {
        $header = self::encodeSegment(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = self::encodeSegment(['sub' => (string) $user['id'], 'username' => $user['username'],
            'role' => $user['role'], 'exp' => time() + 28800]);
        return "$header.$payload." . self::base64Url(hash_hmac('sha256', "$header.$payload", $this->secret, true));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function encodeSegment(array $data): string
    {
        return self::base64Url(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function user(): array
    {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer (\S+)$/i', $authorization, $matches)) {
            throw new HttpError(401, 'Missing bearer token');
        }
        try {
            // Minimal HS256 codec solely to retain the deliberately weak key.
            // The fixed application uses the maintained JWT library instead.
            $parts = explode('.', $matches[1], 4);
            if (count($parts) !== 3) {
                throw new \UnexpectedValueException('Invalid segments');
            }
            [$head, $body, $signature] = $parts;
            $header = json_decode(base64_decode(strtr($head, '-_', '+/'), true), true, 32, JSON_THROW_ON_ERROR);
            $claims = json_decode(base64_decode(strtr($body, '-_', '+/'), true), false, 32, JSON_THROW_ON_ERROR);
            $expected = self::base64Url(hash_hmac('sha256', "$head.$body", $this->secret, true));
            if (($header['alg'] ?? '') !== 'HS256' || !hash_equals($expected, $signature)
                || !isset($claims->exp) || $claims->exp <= time()) {
                throw new \UnexpectedValueException('Invalid signature or expiry');
            }
        } catch (\Throwable $error) {
            throw new HttpError(401, 'Invalid token: ' . $error->getMessage());
        }
        $user = Database::query($this->db, 'SELECT * FROM users WHERE id = ?', [$claims->sub ?? 0])->fetch();
        if (!$user) {
            throw new HttpError(401, 'User not found');
        }
        return $user;
    }

    public function login(string $username, string $password): array
    {
        // VULN-09: no server-side throttling at all.
        $user = Database::query($this->db, 'SELECT * FROM users WHERE username = ?', [$username])->fetch();
        if (!$user || !self::verifyPassword($password, $user['password_hash'])) {
            throw new HttpError(401, 'Invalid username or password');
        }
        return ['access_token' => $this->token($user), 'token_type' => 'bearer', 'role' => $user['role']];
    }
}
