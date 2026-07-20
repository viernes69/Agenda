<?php
/**
 * Agenduy - Componente público: Lista de planes recurrentes (dLocal)
 *
 * Incluir desde la web del salón con:
 *   require_once __DIR__ . '/../../components/dlocal/plans.php';
 *   AgenduyDlocalPlans::render($slug);
 *
 * O usar el shortcode desde la web pública renderizada por commerce_view.php.
 *
 * Variables esperadas (opcional):
 *   $agenduy_dlocal_slug  string  Slug del tenant (si no, se autodetecta)
 */
declare(strict_types=1);

use Agenduy\Core\TenantLocalDb;

if (!class_exists('AgenduyDlocalPlans')) {
    final class AgenduyDlocalPlans
    {
        /**
         * Renderiza la lista de planes recurrentes del tenant.
         * Devuelve HTML (string). No imprime nada.
         */
        public static function render(?string $slug = null): string
        {
            $slug = $slug ?: (function_exists('current_slug') ? current_slug() : null);
            if (!is_string($slug) || $slug === '') {
                return '<div class="dlocal-plans-empty">No se pudo detectar el comercio.</div>';
            }
            $slug = trim($slug, '/');
            if (!TenantLocalDb::exists($slug)) {
                return '';
            }
            $db = TenantLocalDb::read($slug);
            $dlocalCfg = is_array($db) && isset($db['dlocal']) && is_array($db['dlocal'])
                ? $db['dlocal']
                : null;
            if ($dlocalCfg === null) {
                return '';
            }
            $planes = is_array($db) && isset($db['planes_cliente']) && is_array($db['planes_cliente'])
                ? $db['planes_cliente']
                : [];

            $planes = array_values(array_filter($planes, static function ($p) {
                return is_array($p) && !empty($p['active']);
            }));
            if ($planes === []) {
                return '';
            }

            $csrfField = (function () {
                if (!class_exists('Agenduy\\Core\\CSRF')) {
                    return '';
                }
                $token = Agenduy\Core\CSRF::generate('public_booking');
                return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
            })();

            $html = '<section class="dlocal-plans" id="dlocal-plans">';
            $html .= '<header class="dlocal-plans__head">';
            $html .= '<h2 class="dlocal-plans__title">Suscripciones</h2>';
            $html .= '<p class="dlocal-plans__sub">Pagá con dLocal y accedé a tus servicios cada mes.</p>';
            $html .= '</header>';
            $html .= '<div class="dlocal-plans__grid">';

            foreach ($planes as $plan) {
                $planId = htmlspecialchars((string)($plan['id'] ?? ''), ENT_QUOTES, 'UTF-8');
                $name   = htmlspecialchars((string)($plan['name'] ?? 'Plan'), ENT_QUOTES, 'UTF-8');
                $desc   = htmlspecialchars((string)($plan['description'] ?? ''), ENT_QUOTES, 'UTF-8');
                $amount = number_format((float)($plan['amount'] ?? 0), 2, ',', '.');
                $cur    = htmlspecialchars((string)($plan['currency'] ?? 'UYU'), ENT_QUOTES, 'UTF-8');
                $freq   = htmlspecialchars(self::frequencyLabel((string)($plan['frequency_type'] ?? 'MONTHLY')), ENT_QUOTES, 'UTF-8');
                $trial  = (int)($plan['free_trial_days'] ?? 0) > 0
                    ? '<span class="dlocal-plan__badge">' . (int)$plan['free_trial_days'] . ' días gratis</span>'
                    : '';

                $html .= '<article class="dlocal-plan" data-plan-id="' . $planId . '">';
                $html .= $trial;
                $html .= '<h3 class="dlocal-plan__name">' . $name . '</h3>';
                if ($desc !== '') {
                    $html .= '<p class="dlocal-plan__desc">' . $desc . '</p>';
                }
                $html .= '<div class="dlocal-plan__price">';
                $html .= '<span class="dlocal-plan__amount">' . $cur . ' ' . $amount . '</span>';
                $html .= '<span class="dlocal-plan__freq">/ ' . $freq . '</span>';
                $html .= '</div>';
                $html .= '<form class="dlocal-plan__form" data-dlocal-plan="' . $planId . '">';
                $html .= $csrfField;
                $html .= '<input type="hidden" name="plan_internal_id" value="' . $planId . '">';
                $html .= '<input type="hidden" name="slug" value="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">';
                $html .= '<label class="dlocal-plan__field">';
                $html .= '<span>Email</span>';
                $html .= '<input type="email" name="customer_email" required placeholder="tu@email.com">';
                $html .= '</label>';
                $html .= '<label class="dlocal-plan__field">';
                $html .= '<span>Nombre</span>';
                $html .= '<input type="text" name="customer_name" placeholder="Tu nombre">';
                $html .= '</label>';
                $html .= '<button type="submit" class="dlocal-plan__cta">Suscribirme</button>';
                $html .= '<p class="dlocal-plan__msg" role="status"></p>';
                $html .= '</form>';
                $html .= '</article>';
            }

            $html .= '</div></section>';
            return $html;
        }

        private static function frequencyLabel(string $freq): string
        {
            return match (strtoupper($freq)) {
                'DAILY'   => 'día',
                'WEEKLY'  => 'semana',
                'MONTHLY' => 'mes',
                'YEARLY'  => 'año',
                default   => 'mes',
            };
        }
    }
}
