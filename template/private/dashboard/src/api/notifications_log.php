<?php
declare(strict_types=1);

date_default_timezone_set('America/Montevideo');

$projectRoot = dirname(__DIR__, 5);
require_once $projectRoot . '/src/Core/bootstrap.php';

use Agenduy\Core\Database;
use Agenduy\Core\TenantApiGuard;

header('Content-Type: application/json; charset=utf-8');

$respond = static function (int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$tenantStaff = TenantApiGuard::requireStaff(dirname(__DIR__, 4));
$idCommerce = (int)($tenantStaff['session']['id_commerce'] ?? 0);

if ($idCommerce <= 0) {
    $respond(403, ['ok' => false, 'error' => 'Comercio no válido']);
}

$escape = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$db = Database::getInstance();

// --- Filters ---
$statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
$channelFilter = strtolower(trim((string)($_GET['channel'] ?? '')));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(50, max(5, (int)($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $perPage;

$validStatuses = ['queued', 'sent', 'failed', 'cancelled'];
$validChannels = ['email', 'whatsapp', 'sms', 'push'];

if ($statusFilter !== '' && !in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}
if ($channelFilter !== '' && !in_array($channelFilter, $validChannels, true)) {
    $channelFilter = '';
}
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

// --- Build query ---
$where = ['id_commerce = :cid'];
$params = [':cid' => $idCommerce];

if ($statusFilter !== '') {
    $where[] = 'status = :status';
    $params[':status'] = $statusFilter;
}
if ($channelFilter !== '') {
    $where[] = 'channel = :channel';
    $params[':channel'] = $channelFilter;
}
if ($dateFrom !== '') {
    $where[] = 'created_at >= :date_from';
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'created_at <= :date_to';
    $params[':date_to'] = $dateTo . ' 23:59:59';
}

$whereSql = implode(' AND ', $where);

// Count total
$countRow = $db->fetchOne(
    "SELECT COUNT(*) AS total FROM notifications_log WHERE {$whereSql}",
    $params
);
$total = (int)($countRow['total'] ?? 0);
$totalPages = max(1, (int)ceil($total / $perPage));

// Fetch rows
$rows = $db->fetchAll(
    "SELECT id_notification, channel, recipient, subject, status, error_message, sent_at, created_at
     FROM notifications_log
     WHERE {$whereSql}
     ORDER BY created_at DESC
     LIMIT :lim OFFSET :off",
    array_merge($params, [':lim' => (int)$perPage, ':off' => (int)$offset])
);

// --- Stats ---
$statsParams = [':cid' => $idCommerce];
$statsWhere = 'id_commerce = :cid';
if ($dateFrom !== '') {
    $statsWhere .= ' AND created_at >= :df';
    $statsParams[':df'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $statsWhere .= ' AND created_at <= :dt';
    $statsParams[':dt'] = $dateTo . ' 23:59:59';
}

$statsRow = $db->fetchOne(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
        SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued,
        SUM(CASE WHEN channel = 'email' THEN 1 ELSE 0 END) AS email_count,
        SUM(CASE WHEN channel = 'whatsapp' THEN 1 ELSE 0 END) AS wa_count
     FROM notifications_log
     WHERE {$statsWhere}",
    $statsParams
);

// --- Render rows ---
$rowHtml = '';
foreach ($rows as $row) {
    $id = (int)$row['id_notification'];
    $channel = (string)$row['channel'];
    $recipient = (string)$row['recipient'];
    $subject = (string)($row['subject'] ?? '');
    $status = (string)$row['status'];
    $errorMsg = (string)($row['error_message'] ?? '');
    $sentAt = (string)($row['sent_at'] ?? '');
    $createdAt = (string)($row['created_at'] ?? '');

    $channelIcon = $channel === 'email' ? '✉️' : ($channel === 'whatsapp' ? '💬' : '📱');
    $channelLabel = ucfirst($channel);

    $statusClass = 'st-' . $status;
    $statusLabels = [
        'queued' => 'En cola',
        'sent' => 'Enviado',
        'failed' => 'Fallido',
        'cancelled' => 'Cancelado',
    ];
    $statusLabel = $statusLabels[$status] ?? ucfirst($status);

    $timeDisplay = $sentAt !== '' ? $sentAt : $createdAt;
    $errorTooltip = $errorMsg !== '' ? ' title="' . $escape($errorMsg) . '"' : '';

    $rowHtml .= '<tr data-notif-status="' . $escape($status) . '" data-notif-channel="' . $escape($channel) . '">';
    $rowHtml .= '<td><span class="notif-channel-icon">' . $channelIcon . '</span> ' . $escape($channelLabel) . '</td>';
    $rowHtml .= '<td class="notif-recipient">' . $escape($recipient) . '</td>';
    $rowHtml .= '<td class="notif-subject">' . $escape($subject ?: '—') . '</td>';
    $rowHtml .= '<td><span class="status-pill ' . $escape($statusClass) . '"' . $errorTooltip . '>' . $escape($statusLabel) . '</span></td>';
    $rowHtml .= '<td class="notif-time">' . $escape($timeDisplay) . '</td>';
    if ($errorMsg !== '') {
        $rowHtml .= '<td class="notif-error" title="' . $escape($errorMsg) . '">⚠️</td>';
    } else {
        $rowHtml .= '<td></td>';
    }
    $rowHtml .= '</tr>';
}

$respond(200, [
    'ok' => true,
    'total' => $total,
    'page' => $page,
    'totalPages' => $totalPages,
    'perPage' => $perPage,
    'stats' => [
        'total' => (int)($statsRow['total'] ?? 0),
        'sent' => (int)($statsRow['sent'] ?? 0),
        'failed' => (int)($statsRow['failed'] ?? 0),
        'queued' => (int)($statsRow['queued'] ?? 0),
        'email' => (int)($statsRow['email_count'] ?? 0),
        'whatsapp' => (int)($statsRow['wa_count'] ?? 0),
    ],
    'html' => $rowHtml,
    'emptyMessage' => 'No hay notificaciones para los filtros seleccionados.',
]);
