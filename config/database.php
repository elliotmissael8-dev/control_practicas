<?php
declare(strict_types=1);

// Railway inyecta MYSQL_URL o variables individuales (MYSQLHOST, MYSQL_HOST, etc.)
// En local (XAMPP) se usan los valores por defecto.

// Función para buscar una variable de entorno con varios nombres posibles
function env(string ...$nombres): string {
    foreach ($nombres as $n) {
        $v = $_ENV[$n] ?? getenv($n);
        if ($v !== false && $v !== '' && $v !== null) return (string)$v;
    }
    return '';
}

$mysql_url = env('MYSQL_URL', 'DATABASE_URL', 'MYSQL_PUBLIC_URL');

if ($mysql_url !== '') {
    // Railway da la URL completa: mysql://user:pass@host:port/db
    $p = parse_url($mysql_url);
    $host = $p['host'] ?? 'localhost';
    $port = $p['port'] ?? 3306;
    $db   = ltrim($p['path'] ?? '/railway', '/');
    $user = $p['user'] ?? 'root';
    $pass = $p['pass'] ?? 'hflRAyRIybReWxxVZFbafWnxcQBsTQHW';
} else {
    // Variables individuales (Railway usa MYSQLHOST o MYSQL_HOST)
    $host = env('MYSQLHOST', 'MYSQL_HOST', 'DB_HOST') ?: 'localhost';
    $port = (int)(env('MYSQLPORT', 'MYSQL_PORT', 'DB_PORT') ?: 3306);
    $db   = env('MYSQLDATABASE', 'MYSQL_DATABASE', 'DB_NAME') ?: 'control_practicas';
    $user = env('MYSQLUSER', 'MYSQL_USER', 'DB_USER') ?: 'root';
    $pass = env('MYSQLPASSWORD', 'MYSQL_PASSWORD', 'DB_PASS') ?: '';
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
    exit('No se pudo conectar con la base de datos. Host=' . $host . ' Port=' . $port . ' DB=' . $db . ' User=' . $user);
}
