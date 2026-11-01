<?php
declare(strict_types=1);

final class HttpClient
{
    public array $evidence = [];

    public function __construct(public readonly string $base, public string $token = '') {}

    public function request(string $method, string $path, mixed $body = null, array $headers = []): array
    {
        $handle = curl_init($this->base . $path);
        $requestHeaders = $headers;
        if ($this->token !== '') {
            $requestHeaders[] = 'Authorization: Bearer ' . $this->token;
        }
        if ($body !== null && !is_array($body)) {
            $requestHeaders[] = 'Content-Type: application/json';
        } elseif (is_array($body) && !isset($body['file'])) {
            $body = json_encode($body, JSON_THROW_ON_ERROR);
            $requestHeaders[] = 'Content-Type: application/json';
        }
        $responseHeaders = [];
        curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower($key)] = trim($value);
                }
                return strlen($line);
            }]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $text = curl_exec($handle);
        if ($text === false) {
            throw new RuntimeException(curl_error($handle));
        }
        $result = ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => json_decode($text, true), 'text' => $text, 'headers' => $responseHeaders];
        curl_close($handle);
        $safeBody = $result['body'];
        if (is_array($safeBody) && isset($safeBody['access_token'])) {
            $safeBody['access_token'] = '[redacted]';
        }
        // Keep rendered HTML and benign upload-marker responses as well as JSON.
        $this->evidence[] = ['request' => "$method $path", 'status' => $result['status'], 'body' => $safeBody ?? $text, 'headers' => $responseHeaders];
        return $result;
    }

    public function upload(string $filename, string $content, string $mime, string $folder = ''): array
    {
        $temporary = tempnam(sys_get_temp_dir(), 'lab');
        try {
            file_put_contents($temporary, $content);
            return $this->request('POST', '/api/avatar', ['file' => new CURLFile($temporary, $mime, $filename), 'folder' => $folder]);
        } finally {
            unlink($temporary);
        }
    }
}
