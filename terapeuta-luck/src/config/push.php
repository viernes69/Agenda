<?php
/**
 * Configuracion del servicio Web Push para el panel privado.
 * Completa las claves con los valores generados por `VAPID::createVapidKeys()`.
 */
return [
    'subject' => 'mailto:soporte@agenduy.uy',
    'publicKey' => getenv('ADMIN_PUSH_PUBLIC_KEY') ?: 'REEMPLAZAR_CON_TU_PUBLIC_KEY',
    'privateKey' => getenv('ADMIN_PUSH_PRIVATE_KEY') ?: 'REEMPLAZAR_CON_TU_PRIVATE_KEY',
];
