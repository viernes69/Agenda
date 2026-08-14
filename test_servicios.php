<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_POST["action"] = "update";
$_POST["ID_Servicio"] = "1"; // Assuming a service with ID 1 exists
$_POST["Nombre"] = "test";
$_POST["Duracion"] = "30";
$_POST["Precio"] = "100";
$_POST["Estado"] = "Activo";
require "template/private/dashboard/src/API/servicios.php";
