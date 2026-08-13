<?php return array (
  'info_barberia' => 
  array (
    'ID_Negocio' => 1,
    'ID_Rubro' => 1,
    'nombre' => '',
    'email' => '',
    'logo_src' => 'src/img/logo.jpg',
    'slogan' => '',
    'descripcion' => '',
    'razon_social' => '',
    'rut_ruc' => '',
    'contacto' => 
    array (
      'telefono' => '',
      'whatsapp' => '',
      'website' => '',
      'email' => '',
    ),
    'direccion' => 
    array (
      'pais' => 'UY',
      'region' => '',
      'ciudad' => '',
      'calle' => '',
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
        'inicio' => '09:00',
        'fin' => '20:00',
        'descanso_inicio' => '12:00',
        'descanso_fin' => '14:00',
        'barber_ids' => 
        array (
          0 => 2,
          1 => 1,
        ),
      ),
      'martes' => 
      array (
        'abierto' => true,
        'inicio' => '09:00',
        'fin' => '20:00',
        'descanso_inicio' => '',
        'descanso_fin' => '',
        'barber_ids' => 
        array (
          0 => 2,
          1 => 1,
        ),
      ),
      'miercoles' => 
      array (
        'abierto' => true,
        'inicio' => '09:00',
        'fin' => '20:00',
        'descanso_inicio' => '',
        'descanso_fin' => '',
        'barber_ids' => 
        array (
          0 => 2,
          1 => 1,
        ),
      ),
      'jueves' => 
      array (
        'abierto' => true,
        'inicio' => '09:00',
        'fin' => '20:00',
        'descanso_inicio' => '',
        'descanso_fin' => '',
        'barber_ids' => 
        array (
          0 => 3,
          1 => 1,
        ),
      ),
      'viernes' => 
      array (
        'abierto' => true,
        'inicio' => '20:00',
        'fin' => '20:00',
        'descanso_inicio' => '',
        'descanso_fin' => '',
        'barber_ids' => 
        array (
          0 => 3,
          1 => 1,
        ),
      ),
      'sabado' => 
      array (
        'abierto' => true,
        'inicio' => '09:00',
        'fin' => '18:00',
        'descanso_inicio' => '',
        'descanso_fin' => '',
        'barber_ids' => 
        array (
          0 => 3,
          1 => 1,
        ),
      ),
      'domingo' => 
      array (
        'abierto' => true,
        'inicio' => '09:00',
        'fin' => '18:00',
        'descanso_inicio' => '',
        'descanso_fin' => '',
        'barber_ids' => 
        array (
          0 => 1,
        ),
      ),
      'feriados' => 
      array (
        0 => '2025-10-30',
        1 => '2025-10-30',
        2 => '2025-10-30',
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
      'statement_descriptor' => 'TU NEGOCIO',
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
      'title' => 'Agenda online',
      'description' => 'Reservá turnos, descubrí servicios y comprá productos. Todo en un solo lugar.',
      'keywords' => 
      array (
        0 => 'agendas',
        1 => 'reservas',
        2 => 'barberias',
        3 => 'uruguay',
        4 => 'nailsaloon',
      ),
      'og_image' => 'src/img/og-image.jpg',
      'robots' => 'index,follow',
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
      'publico' => 'oscuro',
      'privado' => 'oscuro',
    ),
    'rubro' => 'barberia',
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
      'Img_Gallery' => '',
      'Imagenes' => '',
      'Descuento_Porcentaje' => '',
      'Etiqueta_Venta' => '',
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
      'Precio' => NULL,
      'ID_Appointment' => NULL,
      'Metodo_Pago' => '',
      'Payment_Status' => '',
      'MP_Preference_ID' => '',
      'MP_Payment_ID' => '',
      'MP_External_Reference' => '',
      'MP_Status_Detail' => '',
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
      'Metodo_Pago' => '',
      'Payment_Status' => '',
      'MP_Preference_ID' => '',
      'MP_Payment_ID' => '',
      'MP_External_Reference' => '',
      'MP_Status_Detail' => '',
      'Total' => '',
      'Detalle_Items' => '',
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
