<?php
declare(strict_types=1);

namespace Lab;

final class CustomerTools
{
    public static function gift(array $data, array $user): array
    {
        $template = Http::string($data, 'template', 1, 2000);
        $recipient = Http::string($data, 'recipient', 1, 120);
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader(), ['autoescape' => false, 'cache' => false]);
        // VULN-11: customer input is compiled as template source without a sandbox.
        $preview = $twig->createTemplate($template)->render([
            'recipient' => $recipient,
            'sender' => $user['full_name'] ?: $user['username'],
        ]);
        return ['preview' => $preview];
    }

    public static function tracking(string $url): array
    {
        if (!in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?: ''), ['http', 'https'], true)) {
            throw new HttpError(422, 'Invalid tracking URL');
        }
        // VULN-12: arbitrary hosts, internal endpoints and redirects are accepted.
        $context = stream_context_create(['http' => [
            'timeout' => 3, 'follow_location' => 1, 'max_redirects' => 4,
            'header' => "Accept: application/json\r\nConnection: close\r\n",
        ]]);
        $content = @file_get_contents($url, false, $context, 0, 65537);
        if ($content === false || strlen($content) > 65536) {
            throw new HttpError(502, 'Tracking provider unavailable');
        }
        return ['content' => $content];
    }

    public static function help(string $article): array
    {
        // VULN-05 chain: traversal reaches uploaded content; include executes its PHP.
        $target = dirname(__DIR__) . '/content/help/' . $article;
        if (!is_file($target)) {
            throw new HttpError(404, 'Article not found');
        }
        ob_start();
        try {
            include $target;
            $content = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        return ['content' => $content];
    }
}
