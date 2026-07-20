<?php
/**
 * Test del nuevo header del index.php.
 * Asume que el app server esta corriendo en 127.0.0.1:8890.
 * Lo arranca el wrapper .ps1.
 */
declare(strict_types=1);

$appPort = 8890;
$url = "http://127.0.0.1:$appPort/";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$html = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200 || !is_string($html)) {
    fwrite(STDERR, "No se pudo obtener el HTML (HTTP $code)\n");
    exit(1);
}

$tests = [
    'header_presente'         => str_contains($html, '<header class="site-header">'),
    'brand_text_block'        => str_contains($html, 'site-header__brand-text'),
    'tagline_visible'         => str_contains($html, 'brand-tagline'),
    'tagline_text_ok'         => str_contains($html, 'Reservas online para negocios de Uruguay'),
    'instagram_icon'          => str_contains($html, 'bxl-instagram'),
    'whatsapp_icon'           => str_contains($html, 'bxl-whatsapp'),
    'theme_toggle_ok'         => str_contains($html, 'id="theme-toggle"'),
    'login_btn_ok'            => str_contains($html, 'id="site-login-toggle"'),
    'login_label'             => str_contains($html, 'Iniciar sesion'),
    'dropdown_hidden'         => str_contains($html, 'id="site-login-dropdown"') && str_contains($html, 'hidden'),
    'dropdown_email'          => str_contains($html, 'name="email"'),
    'dropdown_password'       => str_contains($html, 'name="password"'),
    'dropdown_csrf'           => str_contains($html, 'name="_csrf"'),
    'dropdown_action_admin'   => str_contains($html, 'admin/login.php'),
    'acceso_completo_link'    => str_contains($html, 'Acceso completo'),
    'site_login_js'           => str_contains($html, 'site-login.js'),
    'aria_expanded_false'     => str_contains($html, 'aria-expanded="false"'),
    'css_link_ok'             => str_contains($html, 'src/css/styles.css'),
];

echo "=== Test header v2 ===" . PHP_EOL;
$pass = 0;
$total = count($tests);
foreach ($tests as $name => $ok) {
    if ($ok) $pass++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
}
echo PHP_EOL . "Total: $pass / $total" . PHP_EOL;
exit($pass === $total ? 0 : 1);
