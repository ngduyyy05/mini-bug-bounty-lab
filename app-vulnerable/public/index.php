<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

set_exception_handler(static function (Throwable $error): void {
    if ($error instanceof Lab\HttpError) {
        Lab\Http::json(['detail' => $error->getMessage()], $error->status);
    }
    error_log((string) $error);
    // VULN-10: expose internal diagnostics to the caller.
    Lab\Http::json(['error' => $error->getMessage(), 'trace' => $error->getTraceAsString(), 'db_dsn' => getenv('DB_DSN')], 500);
});

(new Lab\Application(Lab\Database::connect()))->run();
