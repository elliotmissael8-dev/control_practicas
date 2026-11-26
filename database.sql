-- Esquema único de Control de Prácticas Profesionales.
-- Importa este archivo dentro de la base de datos ya seleccionada (phpMyAdmin: crea/selecciona `control_practicas` y usa Importar).
-- En Docker se importa solo la primera vez que se crea el volumen db_data.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS estudiantes_practicas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  matricula VARCHAR(30) NOT NULL,
  apellidos VARCHAR(100) NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  carrera VARCHAR(120) NOT NULL,
  escuela_procedencia VARCHAR(150) NOT NULL DEFAULT '',
  tutor VARCHAR(150) NOT NULL DEFAULT '',
  nivel_academico VARCHAR(50) NOT NULL DEFAULT 'Universidad',
  turno VARCHAR(30) NOT NULL DEFAULT 'Matutino',
  modalidad VARCHAR(30) NOT NULL DEFAULT 'Presencial',
  tipo_programa VARCHAR(50) NOT NULL DEFAULT 'Prácticas profesionales',
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE NULL,
  estado ENUM('Pendiente','En curso','Finalizado','Baja') NOT NULL DEFAULT 'Pendiente',
  observaciones TEXT NULL,
  telefono VARCHAR(20) NULL,
  correo VARCHAR(120) NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_estudiantes_matricula (matricula),
  KEY idx_estudiantes_apellidos (apellidos),
  KEY idx_estudiantes_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La salida y las actividades quedan en NULL mientras el alumno no cierra su jornada.
CREATE TABLE IF NOT EXISTS bitacora_horas (
  id INT NOT NULL AUTO_INCREMENT,
  estudiante_id INT UNSIGNED NULL,
  fecha DATE NOT NULL,
  hora_entrada TIME NOT NULL,
  hora_salida TIME NULL,
  actividades TEXT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bitacora_estudiante (estudiante_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documentos_entregados (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  estudiante_id INT UNSIGNED NOT NULL,
  tipo_documento VARCHAR(120) NOT NULL,
  fecha_entrega DATE NOT NULL,
  observaciones TEXT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archivo_nombre VARCHAR(255) NULL,
  archivo_ruta VARCHAR(255) NULL,
  revision VARCHAR(12) NOT NULL DEFAULT 'Aprobado',
  origen VARCHAR(10) NOT NULL DEFAULT 'admin',
  revisado_por VARCHAR(80) NULL,
  revisado_en DATETIME NULL,
  motivo_revision VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_documentos_estudiante (estudiante_id),
  CONSTRAINT fk_documentos_estudiante FOREIGN KEY (estudiante_id)
    REFERENCES estudiantes_practicas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS administradores (
  id INT NOT NULL AUTO_INCREMENT,
  usuario VARCHAR(50) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  correo VARCHAR(120) NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_administradores_usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recuperacion_password (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expira DATETIME NOT NULL,
  usado TINYINT(1) NOT NULL DEFAULT 0,
  creado_en DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_recuperacion_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recordatorios_reporte (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  estudiante_id INT NOT NULL,
  periodo INT NOT NULL,
  enviado_en DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recordatorio (estudiante_id, periodo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS historial (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  fecha DATETIME NOT NULL,
  actor VARCHAR(80) NOT NULL,
  actor_tipo VARCHAR(10) NOT NULL DEFAULT 'admin',
  modulo VARCHAR(30) NOT NULL,
  accion VARCHAR(30) NOT NULL,
  detalle VARCHAR(1000) NOT NULL,
  ip VARCHAR(45) NULL,
  PRIMARY KEY (id),
  KEY idx_historial_fecha (fecha),
  KEY idx_historial_actor (actor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
