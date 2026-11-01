<?php
declare(strict_types=1);

namespace Lab;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PDO;

final class Auth
{
    private string $secret;

    public function __construct(private PDO $db)
    {
        $this->secret = getenv('JWT_SECRET') ?: '';
        if (strlen($this->secret) < 64 || count(array_unique(str_split($this->secret))) < 12) {
            throw new \RuntimeException('JWT_SECRET must be a randomly generated secret of at least 64 characters');
        }
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function token(array $user): string
    {
        return JWT::encode(['sub' => (string) $user['id'], 'username' => $user['username'],
            'role' => $user['role'], 'iss' => 'mini-bbl-fixed', 'aud' => 'mini-bbl',
            'iat' => time(), 'exp' => time() + 7200], $this->secret, 'HS256');
    }

    public function user(): array
    {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer (\S+)$/i', $authorization, $matches)) {
            throw new HttpError(401, 'Missing bearer token');
        }
        try {
            $claims = JWT::decode($matches[1], new Key($this->secret, 'HS256'));
            if (!isset($claims->exp, $claims->iat, $claims->sub, $claims->role)
                || !is_int($claims->exp) || !is_int($claims->iat)
                || $claims->iat > time() || $claims->exp <= $claims->iat
                || $claims->exp - $claims->iat > 7200
                || !is_string($claims->sub) || !ctype_digit($claims->sub)
                || ($claims->iss ?? '') !== 'mini-bbl-fixed' || ($claims->aud ?? '') !== 'mini-bbl') {
                throw new \UnexpectedValueException('Invalid claims');
            }
        } catch (\Throwable) {
            throw new HttpError(401, 'Invalid token');
        }
        $user = Database::query($this->db, 'SELECT * FROM users WHERE id = ?', [$claims->sub])->fetch();
        if (!$user || $user['role'] !== $claims->role) {
            throw new HttpError(401, 'Invalid token');
        }
        return $user;
    }

    public function login(string $username, string $password): array
    {
        // Shared by Apache workers. Atomic reservations prevent concurrent bypass.
        // Account keys stop distributed guessing; IP keys stop username rotation.
        $keys = [hash('sha256', 'account:' . strtolower($username)),
            hash('sha256', 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))];
        sort($keys);
        for ($retry = 0; ; $retry++) {
            $this->db->beginTransaction();
            try {
                foreach ($keys as $key) {
                    // Acquire an exclusive lock even when the bucket already exists.
                    // INSERT IGNORE would take shared locks and deadlock on upgrades.
                    Database::query($this->db, 'INSERT INTO login_limits (bucket, attempts, reset_at) VALUES (?, 0, UNIX_TIMESTAMP() + 60) ON DUPLICATE KEY UPDATE bucket = login_limits.bucket', [$key]);
                    $row = Database::query($this->db, 'SELECT attempts, reset_at FROM login_limits WHERE bucket = ? FOR UPDATE', [$key])->fetch();
                    if ((int) $row['reset_at'] <= time()) {
                        Database::query($this->db, 'UPDATE login_limits SET attempts = 0, reset_at = UNIX_TIMESTAMP() + 60 WHERE bucket = ?', [$key]);
                        $row['attempts'] = 0;
                    }
                    if ((int) $row['attempts'] >= 5) {
                        throw new HttpError(429, 'Too many login attempts. Try again later.');
                    }
                    Database::query($this->db, 'UPDATE login_limits SET attempts = attempts + 1 WHERE bucket = ?', [$key]);
                }
                $this->db->commit();
                break;
            } catch (\Throwable $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                if ($error instanceof \PDOException && in_array($error->errorInfo[1] ?? 0, [1205, 1213], true) && $retry < 3) {
                    usleep(random_int(1000, 10000));
                    continue;
                }
                if ($error instanceof HttpError && $error->status === 429) {
                    header('Retry-After: 60');
                }
                throw $error;
            }
        }
        $user = Database::query($this->db, 'SELECT * FROM users WHERE username = ?', [$username])->fetch();
        // Perform a password hash check even for nonexistent accounts.
        $valid = self::verifyPassword($password, $user['password_hash'] ?? '$argon2id$v=19$m=65536,t=4,p=1$L05qNzhQZ0xWNjBkVU52TQ$Ilv3tvo9gQSVIGWdIGJclUFpLLqQTKULGgnOjdijJFs');
        if (!$user || !$valid) {
            throw new HttpError(401, 'Invalid username or password');
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            Database::query($this->db, 'UPDATE users SET password_hash = ? WHERE id = ?', [self::hashPassword($password), $user['id']]);
        }
        return ['access_token' => $this->token($user), 'token_type' => 'bearer', 'role' => $user['role']];
    }
}
