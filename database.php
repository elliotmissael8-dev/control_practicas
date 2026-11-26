<?php
declare(strict_types=1);

// Busca una variable de entorno con varios nombres compatibles.
function env(string ...$nombres): string {
    foreach ($nombres as $nombre) {
        $valor = $_ENV[$nombre] ?? getenv($nombre);
        if ($valor !== false && $valor !== '' && $valor !== null) {
            return (string) $valor;
        }
    }
    return '';
}

$mysqlUrl = env('MYSQL_URL', 'DATABASE_URL', 'MYSQL_PUBLIC_URL');

if ($mysqlUrl !== '') {
    $partes = parse_url($mysqlUrl);

    if ($partes === false) {
        http_response_code(500);
        exit('La configuración de la base de datos no es válida.');
    }

    $host = $partes['host'] ?? '';
    $port = (int) ($partes['port'] ?? 3306);
    $database = ltrim($partes['path'] ?? '', '/');
    $usuario = isset($partes['user']) ? rawurldecode($partes['user']) : '';
    $password = isset($partes['pass']) ? rawurldecode($partes['pass']) : '';
} else {
    $host = env('MYSQLHOST', 'MYSQL_HOST', 'DB_HOST');
    $port = (int) (env('MYSQLPORT', 'MYSQL_PORT', 'DB_PORT') ?: 3306);
    $database = env('MYSQLDATABASE', 'MYSQL_DATABASE', 'DB_NAME');
    $usuario = env('MYSQLUSER', 'MYSQL_USER', 'DB_USER');
    $password = env('MYSQLPASSWORD', 'MYSQL_PASSWORD', 'DB_PASS');
}

// Permite únicamente el entorno local sin variables:
// usuario root y sin contraseña, como una instalación XAMPP típica.
// En producción se rechazan valores incompletos para evitar credenciales accidentales.
$entorno = strtolower(env('APP_ENV') ?: '');
$esLocalSinConfiguracion = $entorno === 'local' || (!isset($_SERVER['RAILWAY_ENVIRONMENT']) && $host === '' && $database === '' && $usuario === '');

if ($esLocalSinConfiguracion) {
    $host = $host ?: '127.0.0.1';
    $port = $port ?: 3306;
    $database = $database ?: 'control_practicas';
    $usuario = $usuario ?: 'root';
    $password = $password ?: '';
}

if ($host === '' || $database === '' || $usuario === '') {
    http_response_code(500);
    exit('La configuración de la base de datos está incompleta.');
}

$charset = 'utf8mb4';
$dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $usuario, $password, $options);
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('No se pudo conectar con la base de datos.');
}
