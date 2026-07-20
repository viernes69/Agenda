<?php return array (
  'info_barberia' => 
  array (
    'ID_Negocio' => 16,
    'ID_Rubro' => 9,
    'nombre' => 'Terap',
    'email' => 'uimpo@gmail.com',
    'logo_src' => 'src/img/logo.jpg',
    'slogan' => 'Bienestar y belleza a tu medida.',
    'descripcion' => 'Tratamientos y servicios de belleza.',
    'razon_social' => 'Terap',
    'rut_ruc' => '',
    'contacto' => 
    array (
      'website' => 'http://localhost/agenduy.uy/terap',
      'telefono' => '+598 92917870',
      'whatsapp' => '+598 92917870',
      'email' => 'uimpo@gmail.com',
    ),
    'direccion' => 
    array (
      'pais' => 'UY',
      'region' => 'Durazno',
      'ciudad' => 'Durazno',
      'calle' => 'washington 991 Durazno uruguay',
      'numero' => '',
      'referencia' => '',
      'codigo_postal' => '',
    ),
    'horarios' => 
    array (
      'timezone' => 'America/Montevideo',
      'lunes' => 
      array (
        'abierto' => true,
        'inicio' => '17:00',
        'fin' => '20:00',
        'descanso_inicio' => '',
        'descanso_fin' => '',
      ),
      'martes' => 
      array (
        'abierto' => false,
        'inicio' => '',
        'fin' => '',
        'descanso_inicio' => '',
        'descanso_fin' => '',
      ),
      'miercoles' => 
      array (
        'abierto' => false,
        'inicio' => '',
        'fin' => '',
        'descanso_inicio' => '',
        'descanso_fin' => '',
      ),
      'jueves' => 
      array (
        'abierto' => false,
        'inicio' => '',
        'fin' => '',
        'descanso_inicio' => '',
        'descanso_fin' => '',
      ),
      'viernes' => 
      array (
        'abierto' => false,
        'inicio' => '',
        'fin' => '',
        'descanso_inicio' => '',
        'descanso_fin' => '',
      ),
      'sabado' => 
      array (
        'abierto' => false,
        'inicio' => '',
        'fin' => '',
        'descanso_inicio' => '',
        'descanso_fin' => '',
      ),
      'domingo' => 
      array (
        'abierto' => false,
        'inicio' => '',
        'fin' => '',
        'descanso_inicio' => '',
        'descanso_fin' => '',
      ),
      'feriados' => 
      array (
      ),
    ),
    'reservas' => 
    array (
      'anticipacion_minutos' => 60,
      'max_dias_adelante' => 30,
      'politica_cancelacion_horas' => 1,
      'requiere_login' => false,
      'max_reservas_por_dia_por_cliente' => 1,
    ),
    'moneda' => 
    array (
      'codigo' => 'UYU',
      'simbolo' => '$',
      'separador_decimal' => ',',
      'separador_miles' => '.',
    ),
    'locale' => 'es_UY',
    'formatos' => 
    array (
      'fecha' => 'Y-m-d',
      'hora' => 'H:i',
    ),
    'fiscal' => 
    array (
      'iva_porcentaje' => 22,
      'comprobante' => 'ticket',
      'enabled' => false,
    ),
    'metodos_pago' => 
    array (
      'MP' => false,
      'Efectivo' => true,
      'POS' => true,
    ),
    'mercado_pago' => 
    array (
      'enabled' => false,
      'modo' => 'test',
      'public_key' => '',
      'access_token' => '',
      'integrator_id' => '',
      'statement_descriptor' => 'TERAP',
      'country' => 'UY',
      'currency' => 'UYU',
      'auto_capture' => true,
      'success_url' => 'https://agendas.appsuy.net/template/pago/success',
      'failure_url' => 'https://agendas.appsuy.net/template/pago/failure',
      'pending_url' => 'https://agendas.appsuy.net/template/pago/pending',
      'notification_url' => 'https://agendas.appsuy.net/api/webhooks/mercadopago',
      'allowed_payment_methods' => 
      array (
      ),
    ),
    'redes' => 
    array (
      'visible' => true,
      'instagram' => '',
      'facebook' => '',
      'tiktok' => '',
      'twitter' => '',
      'youtube' => '',
      'whatsapp' => '',
    ),
    'seo' => 
    array (
      'title' => 'Belleza y estética',
      'description' => 'Reserva tratamientos online.',
      'keywords' => 
      array (
        0 => 'estetica',
        1 => 'belleza',
      ),
    ),
    'notificaciones' => 
    array (
      'whatsapp' => 
      array (
        'enabled' => true,
        'number' => '',
        'provider' => 'meta',
      ),
    ),
    'legales' => 
    array (
      'terminos' => '1. Introduccion
Al utilizar esta plataforma aceptas los presentes terminos y condiciones. El sistema permite reservar citas, contratar servicios, comprar productos y administrar la informacion asociada a tu cuenta.

2. Reservas y puntualidad
Las reservas se realizan sujeto a disponibilidad. Solicitamos presentarte unos minutos antes del horario asignado. Los retrasos prolongados pueden requerir reprogramacion para mantener el orden de atencion.

3. Pagos y promociones
Los precios publicados pueden modificarse sin previo aviso. Las promociones o descuentos aplican solo durante el periodo informado y hasta agotar stock.

4. Modificaciones
Nos reservamos el derecho de actualizar estos terminos cuando sea necesario. Las variaciones se comunicaran en la plataforma y comenzaran a regir desde su publicacion.',
      'privacidad' => '1. Datos recopilados
Registramos nombre, datos de contacto, historial de reservas y preferencias con el fin de prestar y mejorar nuestros servicios. Solo almacenamos la informacion necesaria para operar la agenda.

2. Uso de la informacion
Empleamos tus datos para confirmar citas, enviar recordatorios, notificar cambios, compartir novedades relevantes y generar reportes internos.

3. Proteccion de datos
Implementamos controles tecnicos y administrativos para resguardar la informacion. No vendemos ni cedemos datos personales a terceros salvo obligacion legal o consentimiento expreso.

4. Derechos del titular
Puedes solicitar acceso, actualizacion o eliminacion de tus datos comunicandote por los canales oficiales indicados en la plataforma.',
      'reembolsos' => '1. Servicios
Si no estas conforme con un servicio, contactanos dentro de las 24 horas para evaluar la situacion y coordinar un ajuste o correccion cuando corresponda.

2. Productos
Aceptamos cambios de productos sin uso y en su empaque original dentro de los 7 dias habiles posteriores a la compra, presentando comprobante o numero de pedido.

3. Procesamiento de reembolsos
Los reintegros se realizan por el mismo medio de pago utilizado. Dependiendo de la entidad emisora pueden demorar entre 3 y 10 dias habiles.',
    ),
    'features' => 
    array (
      'productos' => true,
      'servicios' => true,
      'barberos' => true,
    ),
    'temas' => 
    array (
      'publico' => 'claro',
      'privado' => 'claro',
    ),
    'rubro' => 'belleza',
    'ID_Admin' => 17,
    'rubro_nombre' => 'Belleza y estética',
  ),
  'barberos' => 
  array (
    0 => 
    array (
      'ID_Barber' => NULL,
      'Nombre' => NULL,
      'Apellido' => NULL,
      'Cedula' => NULL,
      'Psw' => NULL,
      'Disponibilidad' => NULL,
      'Habilidades' => NULL,
      'Rol' => NULL,
      'Perfil' => NULL,
      'Comision' => NULL,
      'Status' => NULL,
      'DiasTrabajo' => NULL,
    ),
    1 => 
    array (
      'ID_Barber' => 17,
      'Nombre' => 'lucas',
      'Apellido' => 'Iglesias De abreu',
      'Cedula' => '522678390',
      'Psw' => '$2y$10$53l.hlVh2gWBz5lahmFJkO3cDeK3VnGIjZ1ptnvYYSs6gmpNfInXK',
      'Disponibilidad' => 'Disponible',
      'Habilidades' => '',
      'Rol' => 'Admin',
      'Perfil' => '',
      'Comision' => NULL,
      'Status' => 'Online',
      'DiasTrabajo' => '',
    ),
  ),
  'clientes' => 
  array (
    0 => 
    array (
      'ID_Cliente' => NULL,
      'Nombre' => NULL,
      'Cedula' => NULL,
      'Telefono' => NULL,
      'Perfil' => NULL,
      'Email' => NULL,
    ),
    1 => 
    array (
      'ID_Cliente' => 4,
      'Nombre' => 'Badge Seed',
      'Cedula' => '',
      'Telefono' => '',
      'Perfil' => '',
      'Email' => 'seed@agenduy.test',
    ),
  ),
  'productos' => 
  array (
    0 => 
    array (
      'ID_Product' => NULL,
      'Nombre' => NULL,
      'Tipo' => NULL,
      'Precio' => NULL,
      'Descripcion' => NULL,
      'Puntos' => NULL,
      'Img_src' => NULL,
    ),
  ),
  'reservas' => 
  array (
    0 => 
    array (
      'ID_Reserva' => NULL,
      'ID_Cliente' => NULL,
      'ID_Barber' => NULL,
      'ID_Servicio' => NULL,
      'Hora_Reserva' => NULL,
      'Fecha_Reserva' => NULL,
      'Status' => NULL,
      'ID_Appointment' => NULL,
      'Precio' => NULL,
    ),
    1 => 
    array (
      'ID_Reserva' => 1,
      'ID_Cliente' => 2,
      'ID_Barber' => 17,
      'ID_Servicio' => 1,
      'Hora_Reserva' => '20:10:00',
      'Fecha_Reserva' => '2026-07-24',
      'Status' => 'Finalizado',
      'ID_Appointment' => 12,
      'Precio' => 350.0,
    ),
    2 => 
    array (
      'ID_Reserva' => 2,
      'ID_Cliente' => 2,
      'ID_Barber' => 17,
      'ID_Servicio' => 1,
      'Hora_Reserva' => '05:37:00',
      'Fecha_Reserva' => '2026-07-19',
      'Status' => 'Finalizado',
      'ID_Appointment' => 17,
      'Precio' => 350.0,
    ),
    3 => 
    array (
      'ID_Reserva' => 3,
      'ID_Cliente' => 2,
      'ID_Barber' => 17,
      'ID_Servicio' => 2,
      'Hora_Reserva' => '18:00:00',
      'Fecha_Reserva' => '2026-07-20',
      'Status' => 'Finalizado',
      'ID_Appointment' => 18,
      'Precio' => 888.0,
    ),
    4 => 
    array (
      'ID_Reserva' => 4,
      'ID_Cliente' => 3,
      'ID_Barber' => 17,
      'ID_Servicio' => 1,
      'Hora_Reserva' => '11:30:00',
      'Fecha_Reserva' => '2026-07-26',
      'Status' => 'Cancelado',
      'ID_Appointment' => 19,
      'Precio' => 350.0,
    ),
    5 => 
    array (
      'ID_Reserva' => 5,
      'ID_Cliente' => 2,
      'ID_Barber' => 17,
      'ID_Servicio' => 1,
      'Hora_Reserva' => '19:30:00',
      'Fecha_Reserva' => '2026-07-20',
      'Status' => 'Cancelado',
      'ID_Appointment' => 49,
      'Precio' => 350.0,
    ),
    6 => 
    array (
      'ID_Reserva' => 6,
      'ID_Cliente' => 2,
      'ID_Barber' => 17,
      'ID_Servicio' => 1,
      'Hora_Reserva' => '19:00:00',
      'Fecha_Reserva' => '2026-07-20',
      'Status' => 'Cancelado',
      'ID_Appointment' => 50,
      'Precio' => 350.0,
    ),
  ),
  'carrito' => 
  array (
    0 => 
    array (
      'ID_Carrito' => NULL,
      'ID_Cliente' => NULL,
      'Dirección' => NULL,
      'ID_Producto + Cantidad' => NULL,
      'Hora' => NULL,
      'Fecha' => NULL,
      'Status' => NULL,
    ),
    1 => 
    array (
      'ID_Carrito' => 1,
      'ID_Cliente' => 1,
      'Dirección' => 'Coordinar por WhatsApp · Pedido WhatsApp (tienda) · Cliente Test Pedido',
      'ID_Producto + Cantidad' => '(1 + 1)',
      'Hora' => '03:27:34',
      'Fecha' => '2026-07-18',
      'Status' => 'Cancelado',
    ),
    2 => 
    array (
      'ID_Carrito' => 2,
      'ID_Cliente' => 2,
      'Dirección' => 'Coordinar por WhatsApp · Pedido WhatsApp (tienda) · HTTP Test Cliente · 098765432',
      'ID_Producto + Cantidad' => '(1 + 2)',
      'Hora' => '03:27:48',
      'Fecha' => '2026-07-18',
      'Status' => 'Finalizado',
    ),
    3 => 
    array (
      'ID_Carrito' => 3,
      'ID_Cliente' => 1,
      'Dirección' => 'Coordinar por WhatsApp · Pedido WhatsApp (tienda)',
      'ID_Producto + Cantidad' => '(2 + 1)',
      'Hora' => '03:28:02',
      'Fecha' => '2026-07-18',
      'Status' => 'Cancelado',
    ),
    4 => 
    array (
      'ID_Carrito' => 4,
      'ID_Cliente' => NULL,
      'Dirección' => 'Coordinar por WhatsApp · Pedido WhatsApp (tienda)',
      'ID_Producto + Cantidad' => '(2 + 2)',
      'Hora' => '03:29:09',
      'Fecha' => '2026-07-18',
      'Status' => 'Finalizado',
    ),
    5 => 
    array (
      'ID_Carrito' => 5,
      'ID_Cliente' => 2,
      'Dirección' => 'Coordinar por WhatsApp · Post-reserva · Reserva #49 · lucas Iglesias De abreu · +59892917870',
      'ID_Producto + Cantidad' => '(3 + 1)',
      'Hora' => '03:45:44',
      'Fecha' => '2026-07-18',
      'Status' => 'Cancelado',
    ),
  ),
  'servicios' => 
  array (
    0 => 
    array (
      'ID_Servicio' => NULL,
      'Nombre' => NULL,
      'Duracion' => NULL,
      'Estado' => NULL,
      'Precio' => NULL,
      'Puntos' => NULL,
      'Img_Link' => NULL,
    ),
    1 => 
    array (
      'ID_Servicio' => 2,
      'Nombre' => 'iiuhh',
      'Duracion' => 60,
      'Estado' => 'Activo',
      'Precio' => 888.0,
      'Puntos' => 8,
      'Img_Link' => 'src/img/services/Servicio_20260718_031354_88ff3d51.jpg',
    ),
    2 => 
    array (
      'ID_Servicio' => 3,
      'Nombre' => 'hhhh',
      'Duracion' => 30,
      'Estado' => 'Activo',
      'Precio' => 88.0,
      'Puntos' => 8,
      'Img_Link' => 'src/img/services/Servicio_20260718_031407_156c9c37.png',
    ),
  ),
  'turnos' => 
  array (
    0 => 
    array (
      'ID_Turno' => NULL,
      'ID_Barbers' => NULL,
      'Tipo' => NULL,
      'Hora_Inicio' => NULL,
      'Hora_Cierre' => NULL,
      'Estado' => NULL,
    ),
  ),
  'puntos' => 
  array (
    0 => 
    array (
      'ID_Puntos' => NULL,
      'ID_Client' => NULL,
      'Total' => NULL,
      'Estado' => NULL,
    ),
  ),
);
