<?php
declare(strict_types=1);

putenv('DB_DSN=invalid:private-configuration-marker');
require dirname(__DIR__, 2) . '/app-fixed/public/index.php';
