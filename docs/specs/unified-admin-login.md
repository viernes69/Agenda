# Spec: Acceso administrativo unificado

## Objective
Usar `admin/login.php` como único formulario de acceso para superadministradores y administradores de comercio. El rol autenticado determina el destino sin pedir al usuario que elija un tipo de cuenta.

## Tech Stack
- PHP 8 con sesiones de servidor
- SQLite mediante `Agenduy\Core\Database`
- Contraseñas bcrypt mediante `password_verify`

## Commands
- Tests: `C:\xampp\php\php.exe tests\auth_login_test.php`
- Suite: `C:\xampp\php\php.exe tests\run.php`
- PHP lint: `C:\xampp\php\php.exe -l <archivo.php>`
- Runtime: requests locales y navegador sobre `http://localhost/agenduy.uy/admin/login.php`

## Project Structure
- `admin/login.php`: formulario y orquestación del acceso unificado
- `src/Core/Auth.php`: autenticación, sesión y resolución segura del destino
- `template/private/session_guard.php`: compatibilidad del dashboard tenant con la sesión central
- `template/src/API/AdminConfig.php`: autorización de Config para admin de comercio
- `tests/auth_login_test.php`: integración de roles, destino y aislamiento

## Code Style
```php
$destination = Auth::dashboardUrl($result['user']);
if ($destination === null) {
    Auth::logout();
    $error = 'No se pudo iniciar sesión.';
}
```

- Consultas parametrizadas.
- Comparaciones de rol con las constantes de `Auth`.
- Mensajes de error genéricos para no enumerar usuarios o roles.

## Testing Strategy
- RED: demostrar que `commerce_admin` todavía no obtiene el dashboard de su tenant.
- GREEN: verificar destinos para `super_admin` y `commerce_admin`.
- Casos negativos: comercio inexistente, tenant ausente y rol no permitido.
- Runtime: formulario único redirige según el rol y una sesión de comercio no accede al panel global.

## Boundaries
- Always: regenerar el ID de sesión tras autenticar; validar rol, `id_commerce`, slug y existencia del tenant.
- Ask first: cambiar roles, esquema de base de datos o destino aprobado.
- Never: confiar en un rol enviado por el cliente, revelar si el email existe, compartir acceso entre tenants o guardar contraseñas en sesión.

## Success Criteria
- El mismo formulario autentica ambos roles.
- `super_admin` llega a `/admin/index.php`.
- `commerce_admin` llega a `/{slug}/private/dashboard/admin/index.php`.
- El dashboard tenant reconoce la sesión central como administrador.
- `commerce_login.php` conserva compatibilidad redirigiendo al login único.
- No hay escalada de privilegios ni acceso cruzado entre comercios.

## Approved Decision
El usuario aprobó que los administradores de comercio sean enviados directamente al dashboard dentro de su carpeta tenant.
