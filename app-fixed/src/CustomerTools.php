<?php
declare(strict_types=1);

namespace Lab;

final class CustomerTools
{
    public static function gift(array $data, array $user): array
    {
        $template = Http::string($data, 'template', 1, 2000);
        $recipient = Http::string($data, 'recipient', 1, 120);
        $values = ['recipient' => $recipient, 'sender' => $user['full_name'] ?: $user['username']];
        // Never compile user-authored text. Replace only the supported placeholders,
        // once, so template syntax within a value remains ordinary text as well.
        $preview = preg_replace_callback('/{{\s*(recipient|sender)\s*}}/',
            static fn (array $match): string => $values[$match[1]], $template);
        return ['preview' => $preview];
    }

    public static function tracking(string $url): array
    {
        // One trusted provider, one path format, no credentials, ports, queries,
        // fragments or encoded paths. Docker owns this service's DNS record.
        if (!preg_match('~^http://delivery-service/tracking/[A-Z0-9-]{1,40}$~D', $url)) {
            throw new HttpError(422, 'Unsupported tracking link');
        }
        $context = stream_context_create(['http' => [
            'timeout' => 3, 'follow_location' => 0, 'ignore_errors' => true,
            'header' => "Accept: application/json\r\nConnection: close\r\n",
        ]]);
        $content = @file_get_contents($url, false, $context, 0, 65537);
        $statusLine = $http_response_header[0] ?? '';
        if ($content === false || strlen($content) > 65536 || !preg_match('~^HTTP/\S+ 200\b~', $statusLine)) {
            throw new HttpError(502, 'Tracking provider unavailable');
        }
        return ['content' => $content];
    }

    public static function help(string $article): array
    {
        // Request values select a known file; they never become a filesystem path.
        $articles = [
            'shipping.php' => dirname(__DIR__) . '/content/help/shipping.php',
            'returns.php' => dirname(__DIR__) . '/content/help/returns.php',
        ];
        if (!isset($articles[$article])) {
            throw new HttpError(404, 'Article not found');
        }
        ob_start();
        try {
            include $articles[$article];
            $content = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        return ['content' => $content];
    }
}
