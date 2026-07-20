<?php
/**
 * Agenduy - API: Subir comprobante de transferencia (membresía SaaS)
 *
 * POST /admin/api/transfer_upload.php (multipart/form-data)
 *   campos: monto, moneda, referencia, fecha_transferencia,
 *           banco_origen, comprobante, _csrf,
 *           id_membership?, billing_period?, slug? (legacy público)
 *
 * Crea un payment_transfers con status=pending. El super admin
 * lo aprueba desde /admin/payments.php.
 *
 * La suscripción NO usa status=pending (CHECK solo permite
 * trial|active|past_due|cancelled). Se mantiene trial/active hasta
 * que el admin apruebe el comprobante.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/Core/bootstrap.php';

use Agenduy\Core\Auth;
use Agenduy\Core\Database;
use Agenduy\Core\CSRF;
use Agenduy\Core\Mail;
use Agenduy\Core\MembershipPlan;
use Agenduy\Core\ProviderConfig;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

Auth::start();

/**
 * Normaliza fecha a Y-m-d. Acepta Y-m-d, dd/mm/yyyy, dd-mm-yyyy, dd.mm.yyyy.
 * Cadena vacía → null. Formato inválido → InvalidArgumentException (mensaje ES).
 */
function agenduy_parse_transfer_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
        $y = (int)$m[1];
        $mo = (int)$m[2];
        $d = (int)$m[3];
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        throw new InvalidArgumentException('Fecha inválida. Usá el formato dd/mm/aaaa.');
    }

    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $raw, $m)) {
        $d = (int)$m[1];
        $mo = (int)$m[2];
        $y = (int)$m[3];
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        throw new InvalidArgumentException('Fecha inválida. Usá el formato dd/mm/aaaa.');
    }

    throw new InvalidArgumentException('Fecha inválida. Usá el formato dd/mm/aaaa (ej. 18/07/2026).');
}

try {
    $db = Database::getInstance();
    $transferCfg = ProviderConfig::get('transfer');
    if (!$transferCfg['is_enabled']) {
        throw new RuntimeException('Transferencia no está habilitada.');
    }

    $commerce = null;
    $isCommerceAdmin = Auth::check() && Auth::role() === 'commerce_admin' && (int)Auth::commerceId() > 0;

    if ($isCommerceAdmin) {
        $csrf = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!CSRF::check('commerce_plan_select', is_string($csrf) ? $csrf : null, false)) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'CSRF inválido. Recargá la página.']);
            exit;
        }
        $commerce = $db->fetchOne(
            'SELECT * FROM commerces WHERE id_commerce = :id',
            [':id' => (int)Auth::commerceId()]
        );
    } else {
        $csrf = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!CSRF::validate(is_string($csrf) ? $csrf : null, 'public_booking', false)) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'CSRF inválido. Recargá la página.']);
            exit;
        }
        $slug = trim((string)($_POST['slug'] ?? ''));
        if ($slug === '') {
            throw new InvalidArgumentException('Falta slug.');
        }
        $commerce = $db->fetchOne('SELECT * FROM commerces WHERE slug = :s', [':s' => $slug]);
    }

    if (!$commerce) {
        throw new RuntimeException('Comercio no encontrado.');
    }

    $idMembership = (int)($_POST['id_membership'] ?? 0);
    $billingPeriod = strtolower(trim((string)($_POST['billing_period'] ?? 'monthly')));
    if ($billingPeriod !== 'yearly') {
        $billingPeriod = 'monthly';
    }

    $membership = null;
    if ($idMembership > 0) {
        $membership = $db->fetchOne(
            'SELECT * FROM memberships WHERE id_membership = :id AND activo = 1',
            [':id' => $idMembership]
        );
        if (!$membership) {
            throw new InvalidArgumentException('Plan inválido.');
        }
        if ($billingPeriod === 'yearly' && !MembershipPlan::isAnnualEnabled($membership)) {
            $billingPeriod = 'monthly';
        }
    }

    $monto = (float)($_POST['monto'] ?? 0);
    if ($monto <= 0 && $membership) {
        $monto = $billingPeriod === 'yearly'
            ? (float)(MembershipPlan::yearlyPrice($membership) ?? $membership['precio'])
            : (float)$membership['precio'];
    }
    $moneda = strtoupper(trim((string)($_POST['moneda'] ?? ($membership['moneda'] ?? ($transferCfg['config']['moneda'] ?? 'UYU')))));
    $ref = trim((string)($_POST['referencia'] ?? ''));
    $fechaTransf = agenduy_parse_transfer_date((string)($_POST['fecha_transferencia'] ?? ''));
    $banco = trim((string)($_POST['banco_origen'] ?? ''));

    if ($monto <= 0) {
        throw new InvalidArgumentException('Monto inválido.');
    }

    if (!isset($_FILES['comprobante']) || !is_uploaded_file($_FILES['comprobante']['tmp_name'])) {
        throw new InvalidArgumentException('Subí el comprobante (imagen o PDF).');
    }
    $file = $_FILES['comprobante'];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Error al subir el archivo.');
    }
    $cfg = $db->config();
    $maxBytes = (int)$cfg['uploads']['max_size_mb'] * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        throw new InvalidArgumentException('El archivo supera el máximo permitido.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    if (!isset($allowed[$mime])) {
        throw new InvalidArgumentException('Formato no soportado (JPG, PNG, WebP, PDF).');
    }
    $ext = $allowed[$mime];

    $storageDir = rtrim((string)$cfg['uploads']['base_dir'], DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . trim((string)$cfg['uploads']['receipts_dir'], DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . $commerce['id_commerce'];
    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        throw new RuntimeException('No se pudo crear el directorio de uploads.');
    }
    $fname = 'receipt_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $absPath = $storageDir . DIRECTORY_SEPARATOR . $fname;
    if (!move_uploaded_file($file['tmp_name'], $absPath)) {
        throw new RuntimeException('No se pudo guardar el archivo.');
    }
    $relPath = 'storage/uploads/' . trim((string)$cfg['uploads']['receipts_dir'], '/\\') . '/' . $commerce['id_commerce'] . '/' . $fname;

    $idCommerce = (int)$commerce['id_commerce'];
    $subId = null;
    if ($membership) {
        $now = date('Y-m-d H:i:s');
        $existing = $db->fetchOne(
            'SELECT id_subscription, status, id_membership FROM subscriptions WHERE id_commerce = :c ORDER BY id_subscription DESC LIMIT 1',
            [':c' => $idCommerce]
        );
        // Keep trial/active/past_due/cancelled — never write 'pending' (CHECK constraint).
        $allowedSubStatuses = ['trial', 'active', 'past_due', 'cancelled'];
        $keepStatus = 'trial';
        if ($existing && in_array((string)($existing['status'] ?? ''), $allowedSubStatuses, true)) {
            $keepStatus = (string)$existing['status'];
        }

        $effectiveMembershipId = (int)($commerce['id_membership'] ?? 0);
        if ($effectiveMembershipId <= 0 && $existing) {
            $effectiveMembershipId = (int)($existing['id_membership'] ?? 0);
        }
        if ($effectiveMembershipId <= 0) {
            $effectiveMembershipId = $idMembership;
        }

        $subData = [
            // Keep effective membership; target paid plan lives in notes until admin approves.
            'id_membership' => $effectiveMembershipId,
            'status' => $keepStatus,
            'gateway' => 'transfer',
            'billing_period' => $billingPeriod,
            'notes' => MembershipPlan::encodePendingMembershipNote(
                $idMembership,
                $effectiveMembershipId,
                'Transferencia pendiente de aprobación.'
            ),
            'updated_at' => $now,
        ];
        if ($existing) {
            $db->update('subscriptions', $subData, 'id_subscription = :id', [':id' => $existing['id_subscription']]);
            $subId = (int)$existing['id_subscription'];
        } else {
            unset($subData['updated_at']);
            $subData['id_commerce'] = $idCommerce;
            $subData['started_at'] = $now;
            $subId = (int)$db->insert('subscriptions', $subData);
        }
        // Do NOT change commerces.id_membership until Super Admin approves the receipt.
        $db->update('commerces', [
            'updated_at' => $now,
        ], 'id_commerce = :id', [':id' => $idCommerce]);
    }

    $id = (int)$db->insert('payment_transfers', [
        'id_commerce'         => $idCommerce,
        'id_subscription'     => $subId,
        'monto'               => $monto,
        'moneda'              => $moneda,
        'referencia'          => $ref,
        'banco_origen'        => $banco,
        'fecha_transferencia' => $fechaTransf,
        'comprobante_path'    => $relPath,
        'status'              => 'pending',
    ]);

    $admin = $db->fetchOne("SELECT email FROM users WHERE role='super_admin' LIMIT 1");
    if ($admin && !empty($admin['email'])) {
        $subject = '[Agenduy] Nueva transferencia pendiente de aprobación';
        $body = '<p>Hay un comprobante de transferencia esperando aprobación.</p>'
              . '<ul><li>Comercio: <strong>' . htmlspecialchars((string)$commerce['nombre']) . '</strong></li>'
              . '<li>Monto: <strong>' . htmlspecialchars($moneda) . ' ' . number_format($monto, 2) . '</strong></li>'
              . '<li>Referencia: ' . htmlspecialchars($ref) . '</li></ul>'
              . '<p><a href="' . htmlspecialchars($cfg['app']['url_base'] . '/admin/payments.php', ENT_QUOTES) . '">Revisar ahora</a></p>';
        Mail::send($admin['email'], $subject, $body, null, $idCommerce);
    }

    echo json_encode([
        'ok' => true,
        'id_transfer' => $id,
        'comprobante' => $relPath,
        'message' => 'Recibimos tu comprobante. Lo revisaremos a la brevedad.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $code = $e instanceof InvalidArgumentException ? 400 : 422;
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
