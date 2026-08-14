<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_POST["action"] = "update";
$_POST["ID_Product"] = "1";
$_POST["Nombre"] = "test test";
$_POST["Precio"] = "150";
$_POST["Tipo"] = "Otros";
$_POST["Descripcion"] = "A product test";
require "template/private/dashboard/src/API/productos.php";
