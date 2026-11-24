<?php
declare(strict_types=1);

// Cambios de base de datos que necesitan las funciones nuevas (teléfono, correo, revisión de documentos y recuperación
// de contraseña). Se aplican solos, una vez, solo si faltan. Si el usuario de MySQL no tiene permiso ALTER/CREATE,
// ejecuta a mano el SQL que viene en las instrucciones de instalación.
function migrar(PDO $pdo): void {
    // Incrementar esta versión obliga a sesiones existentes a ejecutar las migraciones nuevas tras un despliegue.
    if (!empty($_SESSION['migraciones_v3'])) {
        return;
    }
    try {
        // database.sql no siempre incluye esta tabla; créala antes de intentar agregarle las columnas nuevas.
        $pdo->exec('CREATE TABLE IF NOT EXISTS documentos_entregados (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            estudiante_id INT UNSIGNED NOT NULL,
            tipo_documento VARCHAR(120) NOT NULL,
            fecha_entrega DATE NOT NULL,
            observaciones TEXT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            archivo_nombre VARCHAR(255) NULL,
            archivo_ruta VARCHAR(255) NULL,
            INDEX idx_documentos_estudiante (estudiante_id),
            CONSTRAINT fk_documentos_estudiante FOREIGN KEY (estudiante_id)
                REFERENCES estudiantes_practicas (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $agregar = static function (string $tabla, string $columna, string $definicion) use ($pdo): void {
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $st->execute([$tabla, $columna]);
            if ((int)$st->fetchColumn() === 0) {
                $pdo->exec('ALTER TABLE `' . $tabla . '` ADD COLUMN `' . $columna . '` ' . $definicion);
            }
        };
        $agregar('estudiantes_practicas', 'telefono', 'VARCHAR(20) NULL');
        $agregar('estudiantes_practicas', 'correo', 'VARCHAR(120) NULL');
        $agregar('documentos_entregados', 'revision', "VARCHAR(12) NOT NULL DEFAULT 'Aprobado'");
        $agregar('documentos_entregados', 'origen', "VARCHAR(10) NOT NULL DEFAULT 'admin'");
        $agregar('documentos_entregados', 'revisado_por', 'VARCHAR(80) NULL');
        $agregar('documentos_entregados', 'revisado_en', 'DATETIME NULL');
        $agregar('documentos_entregados', 'motivo_revision', 'VARCHAR(255) NULL');
        $agregar('administradores', 'correo', 'VARCHAR(120) NULL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS recuperacion_password (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expira DATETIME NOT NULL,
            usado TINYINT(1) NOT NULL DEFAULT 0,
            creado_en DATETIME NOT NULL,
            INDEX idx_recuperacion_token (token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec('CREATE TABLE IF NOT EXISTS recordatorios_reporte (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            estudiante_id INT NOT NULL,
            periodo INT NOT NULL,
            enviado_en DATETIME NOT NULL,
            UNIQUE KEY uq_recordatorio (estudiante_id, periodo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $_SESSION['migraciones_v3'] = 1;
    } catch (Throwable $e) {
        error_log('Error al ejecutar migraciones de la aplicación: ' . $e->getMessage());
        // Sin permisos o con un esquema incompatible, las funciones afectadas no estarán disponibles.
    }
}
