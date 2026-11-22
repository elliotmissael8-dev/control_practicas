<?php
declare(strict_types=1);

// Railway inyecta MYSQL_URL con todo: mysql://user:pass@host:port/db
// En local (XAMPP) se usan los valores por defecto.
if (!empty($_ENV['MYSQL_URL']) || !empty(getenv('MYSQL_URL'))) {
    $url = $_ENV['MYSQL_URL'] ?? getenv('MYSQL_URL');
    $p = parse_url($url);
    $host    = $p['host'] ?? 'localhost';
    $port    = $p['port'] ?? 3306;
    $db      = ltrim($p['path'] ?? '/control_practicas', '/');
    $user    = $p['user'] ?? 'root';
    $pass    = $p['pass'] ?? '';
} else {
    $host = $_ENV['DB_HOST'] ?? 'localhost';
    $port = (int)($_ENV['DB_PORT'] ?? 3306);
    $db   = $_ENV['DB_NAME'] ?? 'control_practicas';
    $user = $_ENV['DB_USER'] ?? 'root';
    $pass = $_ENV['DB_PASS'] ?? '';
}
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    exit('No se pudo conectar con la base de datos. Verifica config/database.php e importa database.sql en phpMyAdmin.');
}
