<?php
declare(strict_types=1);

// PHPUnit is the executable local proof of concept: identical payloads, positive
// controls, paired assertions and JSON evidence for every scenario.
$id = $argv[1] ?? 'all';
if ($id !== 'all' && !preg_match('/^(0[1-9]|1[0-2])$/D', $id)) {
    fwrite(STDERR, "Usage: php scripts/poc.php [01..12|all]\n");
    exit(2);
}
$command = [PHP_BINARY, dirname(__DIR__) . '/vendor/bin/phpunit', '--testdox'];
if ($id !== 'all') {
    $command[] = '--filter';
    $command[] = 'test' . $id;
}
$process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, dirname(__DIR__));
exit(is_resource($process) ? proc_close($process) : 2);
