<?php
declare(strict_types=1);

namespace Lab;

final class HttpError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

final class Http
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function body(): array
    {
        try {
            $body = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpError(422, 'Invalid JSON');
        }
        if (!is_array($body) || array_is_list($body) && $body !== []) {
            throw new HttpError(422, 'Expected a JSON object');
        }
        return $body;
    }

    public static function string(array $data, string $key, int $min = 0, int $max = 1000): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || strlen($value) < $min || strlen($value) > $max) {
            throw new HttpError(422, "Invalid $key");
        }
        return $value;
    }

    public static function integer(array $data, string $key, ?int $default = null): int
    {
        $value = $data[$key] ?? $default;
        if (!is_int($value)) {
            throw new HttpError(422, "Invalid $key");
        }
        return $value;
    }

    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
