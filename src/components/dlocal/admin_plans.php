<?php
/**
 * Agenduy - Componente admin: Configuracion dLocal + gestion de planes.
 *
 * Incluir desde el panel del salon con:
 *   require_once __DIR__ . '/../../components/dlocal/admin_plans.php';
 *   AgenduyDlocalAdmin::render();
 *
 * Asume que el admin esta logueado (Auth::check() === true con role commerce_admin)
 * y que la sesion expone Auth::commerceId().
 */
declare(strict_types=1);

use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\Database;
use Agenduy\Core\TenantLocalDb;

if (!class_exists('AgenduyDlocalAdmin')) {
    final class AgenduyDlocalAdmin
    {
        public static function render(): string
        {
            if (!class_exists('Agenduy\\Core\\Auth') || !Auth::check() || Auth::role() !== 'commerce_admin') {
                return '<div class="alert alert-warn">Necesitas iniciar sesion como administrador del comercio.</div>';
            }

            $idCommerce = (int)Auth::commerceId();
            if ($idCommerce <= 0) {
                return '<div class="alert alert-error">Cuenta sin comercio asignado.</div>';
            }

            $commerce = Database::getInstance()->fetchOne('SELECT slug FROM commerces WHERE id_commerce = :id', [':id' => $idCommerce]);
            if (!$commerce) {
                return '<div class="alert alert-error">Comercio no encontrado.</div>';
            }
            $slug = (string)$commerce['slug'];

            $cfg  = null;
            $planes = [];
            if (TenantLocalDb::exists($slug)) {
                $db = TenantLocalDb::read($slug);
                $cfg = is_array($db) && isset($db['dlocal']) && is_array($db['dlocal']) ? $db['dlocal'] : null;
                $planes = is_array($db) && isset($db['planes_cliente']) && is_array($db['planes_cliente'])
                    ? array_values($db['planes_cliente'])
                    : [];
            }

            $csrfConfig = CSRF::generate('dlocal_config');
            $csrfPlan   = CSRF::generate('dlocal_plan');

            $apiKeyPreview = $cfg && !empty($cfg['api_key'])
                ? htmlspecialchars(substr((string)$cfg['api_key'], 0, 4) . '…' . substr((string)$cfg['api_key'], -4), ENT_QUOTES, 'UTF-8')
                : '(no configurada)';
            $sandbox = $cfg && !empty($cfg['sandbox']);

            $html  = '<section class="dlocal-admin" id="dlocal-admin">';
            $html .= '<h2 class="dlocal-admin__title">dLocal Go - Suscripciones recurrentes</h2>';

            // --- Config ---
            $html .= '<form class="dlocal-admin__form" id="dlocal-config-form" autocomplete="off">';
            $html .= '<input type="hidden" name="_csrf" value="' . $csrfConfig . '">';
            $html .= '<h3>1. Credenciales</h3>';
            $html .= '<p class="dlocal-admin__help">Sacate las keys en el dashboard de dLocal Go (sandbox o live). El secret_key se conserva si no lo cambias.</p>';
            $html .= '<label><span>API Key</span>';
            $html .= '<input type="text" name="api_key" value="' . htmlspecialchars((string)($cfg['api_key'] ?? ''), ENT_QUOTES, 'UTF-8') . '" required></label>';
            $html .= '<label><span>Secret Key <small>(dejar vacio para conservar)</small></span>';
            $html .= '<input type="password" name="secret_key" placeholder="(sin cambios)" autocomplete="new-password"></label>';
            $html .= '<label class="dlocal-admin__check"><input type="checkbox" name="sandbox" value="1"' . ($sandbox ? ' checked' : '') . '> Modo sandbox (pruebas)</label>';
            $html .= '<p class="dlocal-admin__status">API key actual: <code>' . $apiKeyPreview . '</code> &middot; Modo: <code>' . ($sandbox ? 'sandbox' : 'live') . '</code></p>';
            $html .= '<button type="submit" class="dlocal-admin__btn">Guardar credenciales</button>';
            $html .= '<p class="dlocal-admin__msg" data-msg="config" role="status"></p>';
            $html .= '</form>';

            // --- Planes existentes ---
            $html .= '<div class="dlocal-admin__plans">';
            $html .= '<h3>2. Planes existentes (' . count($planes) . ')</h3>';
            if ($planes === []) {
                $html .= '<p class="dlocal-admin__empty">Todavia no hay planes. Crea el primero abajo.</p>';
            } else {
                $html .= '<table class="dlocal-admin__table">';
                $html .= '<thead><tr><th>Nombre</th><th>Precio</th><th>Frecuencia</th><th>Plan ID</th><th>Estado</th></tr></thead><tbody>';
                foreach ($planes as $p) {
                    $name  = htmlspecialchars((string)($p['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $cur   = htmlspecialchars((string)($p['currency'] ?? 'UYU'), ENT_QUOTES, 'UTF-8');
                    $amt   = number_format((float)($p['amount'] ?? 0), 2, ',', '.');
                    $freq  = htmlspecialchars((string)($p['frequency_type'] ?? 'MONTHLY'), ENT_QUOTES, 'UTF-8');
                    $pid   = (int)($p['dlocal_plan_id'] ?? 0);
                    $active = !empty($p['active']);
                    $html .= '<tr>';
                    $html .= '<td>' . $name . '</td>';
                    $html .= '<td>' . $cur . ' ' . $amt . '</td>';
                    $html .= '<td>' . $freq . '</td>';
                    $html .= '<td><code>' . $pid . '</code></td>';
                    $html .= '<td>' . ($active ? '<span style="color:#15803d">activo</span>' : '<span style="color:#dc2626">inactivo</span>') . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
            }
            $html .= '</div>';

            // --- Crear plan ---
            $html .= '<form class="dlocal-admin__form" id="dlocal-create-plan-form" autocomplete="off">';
            $html .= '<input type="hidden" name="_csrf" value="' . $csrfPlan . '">';
            $html .= '<h3>3. Crear nuevo plan</h3>';
            $html .= '<p class="dlocal-admin__help">Crea un plan en dLocal. Una vez creado, el link de checkout se publica automaticamente en tu web.</p>';
            $html .= '<div class="dlocal-admin__row">';
            $html .= '<label><span>Nombre</span><input type="text" name="name" required placeholder="Membresia Mensual"></label>';
            $html .= '<label><span>Descripcion</span><input type="text" name="description" required placeholder="Acceso a 4 cortes por mes"></label>';
            $html .= '</div>';
            $html .= '<div class="dlocal-admin__row">';
            $html .= '<label><span>Precio</span><input type="number" step="0.01" min="1" name="amount" required placeholder="1500"></label>';
            $html .= '<label><span>Moneda</span><select name="currency"><option>UYU</option><option>USD</option><option>BRL</option><option>ARS</option><option>MXN</option><option>CLP</option><option>COP</option></select></label>';
            $html .= '<label><span>Frecuencia</span><select name="frequency_type"><option value="DAILY">Diaria</option><option value="WEEKLY">Semanal</option><option value="MONTHLY" selected>Mensual</option><option value="YEARLY">Anual</option></select></label>';
            $html .= '<label><span>Cada cuanto</span><input type="number" min="1" name="frequency_value" value="1"></label>';
            $html .= '</div>';
            $html .= '<div class="dlocal-admin__row">';
            $html .= '<label><span>Pais (ISO 3166-1)</span><input type="text" name="country" value="UY" maxlength="2"></label>';
            $html .= '<label><span>Dias de prueba gratis</span><input type="number" min="0" name="free_trial_days" value="0"></label>';
            $html .= '<label><span>Max periodos (opcional)</span><input type="number" min="0" name="max_periods" value="0" placeholder="0 = sin limite"></label>';
            $html .= '</div>';
            $html .= '<button type="submit" class="dlocal-admin__btn">Crear plan en dLocal</button>';
            $html .= '<p class="dlocal-admin__msg" data-msg="plan" role="status"></p>';
            $html .= '</form>';

            // --- Webhook info ---
            $webhookUrl = rtrim((string)url_base(), '/') . '/admin/api/webhook_dlocal.php?slug=' . rawurlencode($slug) . '&source=plan';
            $html .= '<div class="dlocal-admin__info">';
            $html .= '<h3>Webhook URL</h3>';
            $html .= '<p>Esta es la URL que dLocal usa para notificar pagos. La podes ver en cualquier panel de dLocal.</p>';
            $html .= '<code class="dlocal-admin__webhook">' . htmlspecialchars($webhookUrl, ENT_QUOTES, 'UTF-8') . '</code>';
            $html .= '</div>';

            $html .= '</section>';

            return $html;
        }
    }
}
