<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$db = Lab\Database::connect();
// Execute trusted schema statements individually: application PDO disables multi-statements.
foreach (explode(';', file_get_contents(dirname(__DIR__) . '/database/schema.sql')) as $statement) {
    if (trim($statement) !== '') {
        $db->exec($statement);
    }
}
// Startup is serialized by an advisory lock; never overwrite existing lab work.
$db->query("SELECT GET_LOCK('lab_seed', 60)");
try {
    if ((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $db->beginTransaction();
        foreach ([['alice', 'Alice Nguyen', 'user'], ['bob', 'Bob Tran', 'user'], ['staff', 'Support Staff', 'staff'], ['admin', 'Lab Admin', 'admin']] as [$name, $fullName, $role]) {
            Lab\Database::query($db, 'INSERT INTO users(username,password_hash,email,full_name,role) VALUES(?,?,?,?,?)', [$name, Lab\Auth::hashPassword($name . '12345'), "$name@example.local", $fullName, $role]);
        }
        foreach ([['Wireless Mouse', '19.99'], ['USB-C Hub', '39.99'], ['Pentest Notebook', '12.50']] as $product) {
            Lab\Database::query($db, 'INSERT INTO products(name,price) VALUES(?,?)', $product);
        }
        foreach ([[1, 'Wireless Mouse', '19.99', 'paid'], [1, 'Pentest Notebook', '12.50', 'shipped'], [2, 'USB-C Hub', '39.99', 'processing'], [2, 'Wireless Mouse', '19.99', 'paid']] as $order) {
            Lab\Database::query($db, 'INSERT INTO orders(user_id,item,amount,status) VALUES(?,?,?,?)', $order);
        }
        Lab\Database::query($db, 'INSERT INTO feedback(user_id,message,created_at) VALUES(1,?,UTC_TIMESTAMP())', ['Great shop, fast delivery.']);
        $db->commit();
    }
} finally {
    $db->query("SELECT RELEASE_LOCK('lab_seed')");
}
