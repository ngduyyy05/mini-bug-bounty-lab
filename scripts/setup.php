<?php
declare(strict_types=1);

$target = dirname(__DIR__) . '/.env';
if (is_file($target)) {
    fwrite(STDERR, ".env already exists; preserving existing configuration.\n");
    exit(0);
}
$contents = 'JWT_SECRET=' . bin2hex(random_bytes(48)) . "\n"
    . 'FIXED_DB_PASSWORD=' . bin2hex(random_bytes(24)) . "\n"
    . 'FIXED_DB_ROOT_PASSWORD=' . bin2hex(random_bytes(24)) . "\n";
file_put_contents($target, $contents, LOCK_EX);
chmod($target, 0600);
echo "Created .env with random local secrets.\n";
