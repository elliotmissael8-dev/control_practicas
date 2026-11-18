
CREATE DATABASE IF NOT EXISTS control_practicas
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE control_practicas;

CREATE TABLE IF NOT EXISTS estudiantes_practicas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  matricula VARCHAR(30) NOT NULL,
  apellidos VARCHAR(100) NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  carrera VARCHAR(120) NOT NULL,
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE NOT NULL,
  estado ENUM('Pendiente','En curso','Finalizado','Baja') NOT NULL DEFAULT 'Pendiente',
  observaciones TEXT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_estudiantes_matricula (matricula),
  KEY idx_estudiantes_apellidos (apellidos),
  KEY idx_estudiantes_estado (estado),
  CONSTRAINT chk_fechas_practicas CHECK (fecha_fin >= fecha_inicio)
) ENGINE=InnoDB;
