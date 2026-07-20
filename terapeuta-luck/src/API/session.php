<?php
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => false,
    'error' => 'Endpoint deprecated. Use session_client.php or session_barber.php',
    'client_endpoint' => 'src/API/session_client.php',
    'barber_endpoint' => 'src/API/session_barber.php'
], JSON_UNESCAPED_UNICODE);


