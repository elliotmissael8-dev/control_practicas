-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 15-10-2026 a las 21:48:52
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `control_practicas`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `bitacora_horas`
--

CREATE TABLE `bitacora_horas` (
  `id` int(11) NOT NULL,
  `estudiante_id` int(10) UNSIGNED DEFAULT NULL,
  `fecha` date NOT NULL,
  `hora_entrada` time NOT NULL,
  `hora_salida` time NOT NULL,
  `actividades` text NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `documentos_entregados`
--

CREATE TABLE `documentos_entregados` (
  `id` int(11) NOT NULL,
  `estudiante_id` int(10) UNSIGNED NOT NULL,
  `tipo_documento` varchar(120) NOT NULL,
  `fecha_entrega` date NOT NULL,
  `observaciones` text DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `archivo_nombre` varchar(255) DEFAULT NULL,
  `archivo_ruta` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `estudiantes_practicas`
--

CREATE TABLE `estudiantes_practicas` (
  `id` int(10) UNSIGNED NOT NULL,
  `matricula` varchar(30) NOT NULL,
  `apellidos` varchar(100) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `carrera` varchar(120) NOT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date DEFAULT NULL,
  `estado` enum('Pendiente','En curso','Finalizado','Baja') NOT NULL DEFAULT 'Pendiente',
  `observaciones` text DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `bitacora_horas`
--
ALTER TABLE `bitacora_horas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_bitacora_estudiante` (`estudiante_id`);

--
-- Indices de la tabla `documentos_entregados`
--
ALTER TABLE `documentos_entregados`
  ADD PRIMARY KEY (`id`),
  ADD KEY `estudiante_id` (`estudiante_id`);

--
-- Indices de la tabla `estudiantes_practicas`
--
ALTER TABLE `estudiantes_practicas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_estudiantes_matricula` (`matricula`),
  ADD KEY `idx_estudiantes_apellidos` (`apellidos`),
  ADD KEY `idx_estudiantes_estado` (`estado`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `bitacora_horas`
--
ALTER TABLE `bitacora_horas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `documentos_entregados`
--
ALTER TABLE `documentos_entregados`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `estudiantes_practicas`
--
ALTER TABLE `estudiantes_practicas`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `documentos_entregados`
--
ALTER TABLE `documentos_entregados`
  ADD CONSTRAINT `documentos_entregados_ibfk_1` FOREIGN KEY (`estudiante_id`) REFERENCES `estudiantes_practicas` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
