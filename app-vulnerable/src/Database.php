<?php
declare(strict_types=1);

namespace Lab;

use PDO;

final class Database
{
    public static function connect(): PDO
    {
        return new PDO(
            getenv('DB_DSN') ?: 'mysql:host=db-vulnerable;dbname=lab;charset=utf8mb4',
            getenv('DB_USER') ?: 'lab',
            getenv('DB_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false,
             PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]
        );
    }

    public static function query(PDO $db, string $sql, array $params = []): \PDOStatement
    {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        return $statement;
    }
}
