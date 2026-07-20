-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 10-11-2025 a las 19:18:58
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `barberiadb`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `barberos`
--

CREATE TABLE `barberos` (
  `ID_Barber` int(11) NOT NULL,
  `Nombre` varchar(100) NOT NULL,
  `Apellido` varchar(100) NOT NULL,
  `Cedula` varchar(20) NOT NULL,
  `Psw` varchar(100) NOT NULL,
  `Disponibilidad` varchar(50) DEFAULT NULL,
  `Rol` varchar(20) DEFAULT NULL,
  `Status` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `barberos`
--

INSERT INTO `barberos` (`ID_Barber`, `Nombre`, `Apellido`, `Cedula`, `Psw`, `Disponibilidad`, `Rol`, `Status`) VALUES
(1, 'Juan', 'Perez', '48792678', '123', 'Disponible', 'Admin', 'En Linea');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `clientes`
--

CREATE TABLE `clientes` (
  `ID_Cliente` int(11) NOT NULL,
  `Nombre` varchar(100) NOT NULL,
  `Apellido` varchar(100) NOT NULL,
  `Cedula` varchar(20) NOT NULL,
  `Telefono` varchar(20) DEFAULT NULL,
  `Email` varchar(100) DEFAULT NULL,
  `avatar_src` varchar(255) DEFAULT NULL,
  `Psw` varchar(100) DEFAULT NULL,
  `tipo` enum('admin','cliente','barber') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `clientes`
--

INSERT INTO `clientes` (`ID_Cliente`, `Nombre`, `Apellido`, `Cedula`, `Telefono`, `Email`, `avatar_src`, `Psw`, `tipo`) VALUES
(40, 'Rober', 'Garcia', '54851551', '099485431', 'robert@gmail.com', NULL, '$2y$10$GvEsEKbshExjp.5La0xvS.ObeIfUvHrM.xOGMdIVVuItjHlbNN19G', 'admin'),
(41, 'pedro', 'pedro', '48351441', '099399411', 'pedro1@gmail.com', NULL, '$2y$10$b8VDw4fxgPLV85kLJT1TfubUpzBu3gVc4SVS4PSclEmdJJB4hPICe', 'cliente');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `historial`
--

CREATE TABLE `historial` (
  `ID_History` int(11) NOT NULL,
  `ID_Barber` int(11) NOT NULL,
  `ID_Cliente` int(11) NOT NULL,
  `ID_Servicio` int(11) NOT NULL,
  `Fecha` date NOT NULL,
  `Hora` time NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `productos`
--

CREATE TABLE `productos` (
  `ID_Product` int(11) NOT NULL,
  `Nombre` varchar(100) NOT NULL,
  `Tipo` enum('Cremas de tratamiento','Perfumes','Shampoo','Acondicionador') DEFAULT NULL,
  `Precio` decimal(10,2) NOT NULL,
  `Descripcion` varchar(255) DEFAULT NULL,
  `Img_src` varchar(255) DEFAULT NULL,
  `Estado` enum('Activo','Inactivo') NOT NULL DEFAULT 'Activo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `productos`
--

INSERT INTO `productos` (`ID_Product`, `Nombre`, `Tipo`, `Precio`, `Descripcion`, `Img_src`, `Estado`) VALUES
(2, 'Perfume', 'Cremas de tratamiento', 1200.00, 'Eau De Parfum Yara Rosa 100 ml de Lataffa es una fragancia diseñada para la mujer moderna que busca un perfume distintivo y duradero. Con una duración aproximada de 10 horas, Yara Rosa combina elegancia y sutileza, siendo la elección perfecta para cualqui', 'src\\products\\Producto3.jpg', 'Inactivo'),
(3, 'Crema capilar Elvive ', 'Cremas de tratamiento', 1000.00, 'Diseñado para fortalecer y revitalizar, esta crema ayuda a combatir la caída del cabello desde la raíz.', 'src\\products\\Producto4.jpg', 'Inactivo'),
(10, 'Crema de Enjuague X', 'Cremas de tratamiento', 500.00, 'Eau De Parfum Yara Rosa 200 ml de Lataffa es una f', 'src/products/Producto1758731315.jpg', 'Activo'),
(11, 'Acondicionador DOVE', 'Acondicionador', 500.00, 'Repara visiblemente los signos de daño del pelo.', 'src/products/Producto1759165747.jpg', 'Activo'),
(12, 'Shampoo', 'Shampoo', 14000.00, 'Shampoo Anti Caida', 'src/products/ShampooHaed&Shoulders.png', 'Inactivo');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `reservas`
--

CREATE TABLE `reservas` (
  `ID_Reserva` int(11) NOT NULL,
  `ID_Cliente` int(11) NOT NULL,
  `ID_Barber` int(11) NOT NULL,
  `ID_Servicio` int(11) NOT NULL,
  `Hora_Reserva` time NOT NULL,
  `Fecha_Reserva` date NOT NULL,
  `Status` enum('Pendiente','Atendido','Finalizado','Rechazado') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `servicios`
--

CREATE TABLE `servicios` (
  `ID_Servicio` int(11) NOT NULL,
  `Nombre` varchar(100) NOT NULL,
  `Duracion` int(11) NOT NULL,
  `Estado` enum('Activo','Inactivo') NOT NULL,
  `Precio` decimal(10,2) NOT NULL,
  `Img_Link` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `servicios`
--

INSERT INTO `servicios` (`ID_Servicio`, `Nombre`, `Duracion`, `Estado`, `Precio`, `Img_Link`) VALUES
(1, 'Laceado', 30, 'Activo', 300.00, '/src/services/Servicio2.jpg'),
(2, 'Laciado - Organico', 30, 'Activo', 1200.00, '/src/services/Laciado-Lelia.jpg');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `turnos`
--

CREATE TABLE `turnos` (
  `ID_Turno` int(11) NOT NULL,
  `ID_Barber` int(11) NOT NULL,
  `Fecha` date NOT NULL,
  `Hora` time NOT NULL,
  `Estado` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `barberos`
--
ALTER TABLE `barberos`
  ADD PRIMARY KEY (`ID_Barber`),
  ADD UNIQUE KEY `Cedula` (`Cedula`);

--
-- Indices de la tabla `clientes`
--
ALTER TABLE `clientes`
  ADD PRIMARY KEY (`ID_Cliente`),
  ADD UNIQUE KEY `Cedula` (`Cedula`);

--
-- Indices de la tabla `historial`
--
ALTER TABLE `historial`
  ADD PRIMARY KEY (`ID_History`),
  ADD KEY `ID_Barber` (`ID_Barber`),
  ADD KEY `ID_Cliente` (`ID_Cliente`),
  ADD KEY `ID_Servicio` (`ID_Servicio`);

--
-- Indices de la tabla `productos`
--
ALTER TABLE `productos`
  ADD PRIMARY KEY (`ID_Product`);

--
-- Indices de la tabla `reservas`
--
ALTER TABLE `reservas`
  ADD PRIMARY KEY (`ID_Reserva`),
  ADD KEY `ID_Cliente` (`ID_Cliente`),
  ADD KEY `ID_Barber` (`ID_Barber`),
  ADD KEY `ID_Servicio` (`ID_Servicio`);

--
-- Indices de la tabla `servicios`
--
ALTER TABLE `servicios`
  ADD PRIMARY KEY (`ID_Servicio`);

--
-- Indices de la tabla `turnos`
--
ALTER TABLE `turnos`
  ADD PRIMARY KEY (`ID_Turno`),
  ADD KEY `ID_Barber` (`ID_Barber`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `barberos`
--
ALTER TABLE `barberos`
  MODIFY `ID_Barber` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `clientes`
--
ALTER TABLE `clientes`
  MODIFY `ID_Cliente` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT de la tabla `historial`
--
ALTER TABLE `historial`
  MODIFY `ID_History` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `productos`
--
ALTER TABLE `productos`
  MODIFY `ID_Product` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT de la tabla `reservas`
--
ALTER TABLE `reservas`
  MODIFY `ID_Reserva` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `servicios`
--
ALTER TABLE `servicios`
  MODIFY `ID_Servicio` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `turnos`
--
ALTER TABLE `turnos`
  MODIFY `ID_Turno` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
