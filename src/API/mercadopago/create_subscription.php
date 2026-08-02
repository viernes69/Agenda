<?php
declare(strict_types=1);

use App\API\Autoload;

require_once __DIR__ . '/../Autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string)$_SERVER['REQUEST_METHOD']) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido. Usa POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $raw = (string)file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Solicitud invalida.');
    }

    $planId   = (string)($payload['planId'] ?? '');
    $rubroId  = (string)($payload['rubroId'] ?? '');
    $owner    = isset($payload['owner']) && is_array($payload['owner']) ? $payload['owner'] : [];
    $business = isset($payload['negocio']) && is_array($payload['negocio']) ? $payload['negocio'] : [];
    $payer    = isset($payload['payer']) && is_array($payload['payer']) ? $payload['payer'] : [];
    $cardData = isset($payload['card']) && is_array($payload['card']) ? $payload['card'] : [];

    $payerEmail = (string)($payer['email'] ?? '');
    if ($payerEmail === '' || !filter_var($payerEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Correo de Mercado Pago invalido.');
    }
    $cardToken = trim((string)($cardData['token'] ?? ''));
    $paymentMethodId = trim((string)($cardData['paymentMethodId'] ?? ''));
    $issuerId = trim((string)($cardData['issuerId'] ?? ''));
    $installments = (int)($cardData['installments'] ?? 0);
    $cardholderName = trim((string)($cardData['cardholderName'] ?? ''));
    $cardholderEmail = trim((string)($cardData['cardholderEmail'] ?? ''));
    $identificationType = trim((string)($cardData['identificationType'] ?? ''));
    $identificationNumber = trim((string)($cardData['identificationNumber'] ?? ''));

    $mpConfig = Autoload::get('mercado_pago');
    if (!is_array($mpConfig)) {
        throw new RuntimeException('Configura las credenciales de Mercado Pago.');
    }
    $accessToken = trim((string)($mpConfig['ACCESS_TOKEN'] ?? ''));
    if ($accessToken === '') {
        throw new RuntimeException('ACCESS_TOKEN de Mercado Pago faltante.');
    }
    $mode = strtolower(trim((string)($mpConfig['modo'] ?? $mpConfig['MODO'] ?? $mpConfig['MODE'] ?? '')));
    $token = (string)($mpConfig['access_token'] ?? '');
    $sandbox = array_key_exists('sandbox', $mpConfig)
        ? filter_var($mpConfig['sandbox'], FILTER_VALIDATE_BOOLEAN)
        : (!str_starts_with($token, 'APP_USR-') && strtolower((string)($mpConfig['modo'] ?? '')) !== 'live' && $mode !== 'prod' && $mode !== 'production');

    $planesRaw = Autoload::get('planes');
    $planes = [];
    if (is_array($planesRaw)) {
        $isAssoc = array_keys($planesRaw) !== range(0, count($planesRaw) - 1);
        if ($isAssoc && isset($planesRaw['ID_Plan'])) {
            $planes = [$planesRaw];
        } else {
            $planes = $planesRaw;
        }
    }

    $plan = null;
    foreach ($planes as $row) {
        if (!is_array($row)) {
            continue;
        }
        $pid = (string)($row['ID_Plan'] ?? '');
        if ($pid === '' && $plan === null) {
            $plan = $row;
        }
        if ($planId !== '' && $pid === $planId) {
            $plan = $row;
            break;
        }
    }

    if (!$plan) {
        throw new RuntimeException('Plan seleccionado no disponible.');
    }

    $planNombre    = (string)($plan['Nombre'] ?? 'Plan');
    $planCurrency  = (string)($plan['MP_Currency'] ?? ($plan['Moneda'] ?? 'UYU'));
    $planAmount    = (float)($plan['MP_Amount'] ?? ($plan['Precio'] ?? 0));
    $frequency     = max(1, (int)($plan['MP_Frequency'] ?? 1));
    $frequencyType = (string)($plan['MP_Frequency_Type'] ?? 'months');

    $freeTrial = isset($payload['freeTrialMonths']) ? (int)$payload['freeTrialMonths'] : (int)($mpConfig['FREE_TRIAL_MONTHS'] ?? 1);
    if ($freeTrial < 0) {
        $freeTrial = 0;
    }

    $reasonParts = array_filter([
        $planNombre,
        isset($business['nombre']) ? (string)$business['nombre'] : null,
    ]);
    $reason = $reasonParts ? implode(' - ', $reasonParts) : 'Suscripcion Agenduy';

    $autoRecurring = [
        'frequency'          => $frequency,
        'frequency_type'     => $frequencyType ?: 'months',
        'transaction_amount' => $planAmount,
        'currency_id'        => $planCurrency ?: 'UYU',
    ];

    if ($freeTrial > 0 && strtolower($autoRecurring['frequency_type']) === 'months') {
        $autoRecurring['free_trial'] = [
            'frequency'      => $freeTrial,
            'frequency_type' => 'months',
        ];
    }

    $ownerName    = (string)($owner['nombre'] ?? '');
    $ownerSurname = (string)($owner['apellido'] ?? '');
    $ownerId      = trim((string)($owner['cedula'] ?? ''));
    $resolvedPayerEmail = $cardholderEmail !== '' ? $cardholderEmail : $payerEmail;
    $resolvedIdNumber = $identificationNumber !== '' ? $identificationNumber : $ownerId;
    $resolvedIdType = $identificationType !== '' ? $identificationType : ($resolvedIdNumber !== '' ? 'CI' : '');

    $payerPayload = [
        'name'    => $cardholderName !== '' ? $cardholderName : $ownerName,
        'surname' => $ownerSurname,
        'email'   => $resolvedPayerEmail,
    ];
    if ($resolvedIdNumber !== '') {
        $payerPayload['identification'] = [
            'type'   => $resolvedIdType !== '' ? $resolvedIdType : 'CI',
            'number' => $resolvedIdNumber,
        ];
    }

    $request = [
        'reason'             => $reason,
        'external_reference' => sprintf(
            'plan_%s_rubro_%s_%s',
            $planId !== '' ? $planId : 'default',
            $rubroId !== '' ? $rubroId : 'na',
            uniqid()
        ),
        'payer_email'        => $resolvedPayerEmail,
        'auto_recurring'     => $autoRecurring,
        'status'             => $cardToken !== '' ? 'authorized' : 'pending',
        'payer'              => $payerPayload,
    ];

    if (!empty($mpConfig['BACK_URL'])) {
        $request['back_url'] = (string)$mpConfig['BACK_URL'];
    }
    if (!empty($mpConfig['NOTIFICATION_URL'])) {
        $request['notification_url'] = (string)$mpConfig['NOTIFICATION_URL'];
    }
    if (!empty($mpConfig['AUTO_RETURN'])) {
        $request['auto_return'] = (string)$mpConfig['AUTO_RETURN'];
    }
    if (!empty($plan['MP_Preapproval_Plan_ID'])) {
        $request['preapproval_plan_id'] = (string)$plan['MP_Preapproval_Plan_ID'];
    }
    if ($cardToken !== '') {
        $request['card_token_id'] = $cardToken;
        if ($paymentMethodId !== '') {
            $request['payment_method_id'] = $paymentMethodId;
        }
        if ($issuerId !== '') {
            $request['issuer_id'] = $issuerId;
        }
        if ($installments > 0) {
            $request['installments'] = $installments;
        }
    }


    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken,
    ];
    if (!empty($mpConfig['INTEGRATOR_ID'])) {
        $headers[] = 'X-Integrator-Id: ' . (string)$mpConfig['INTEGRATOR_ID'];
    }

    $endpoint = 'https://api.mercadopago.com/preapproval';
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Error al contactar Mercado Pago: ' . $error);
    }

    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string)$response, true);
    if ($statusCode >= 400) {
        $message = is_array($decoded) && isset($decoded['message'])
            ? (string)$decoded['message']
            : 'Mercado Pago rechazo la solicitud.';
        $lowerMessage = strtolower($message);
        if (str_contains($lowerMessage, 'both payer and collector')
            || str_contains($lowerMessage, 'real or test users')) {
            $message = 'Mercado Pago esta en modo prueba: usa credenciales de vendedor de prueba y una cuenta compradora de prueba del mismo pais, o desactiva sandbox y usa credenciales reales.';
        }
        throw new RuntimeException($message);
    }
    if (!is_array($decoded)) {
        throw new RuntimeException('Respuesta inesperada de Mercado Pago.');
    }

    $nextPayment = null;
    if (isset($decoded['next_payment_date'])) {
        $nextPayment = (string)$decoded['next_payment_date'];
    } elseif (isset($decoded['auto_recurring']) && is_array($decoded['auto_recurring']) && isset($decoded['auto_recurring']['next_payment_date'])) {
        $nextPayment = (string)$decoded['auto_recurring']['next_payment_date'];
    }
    $sandboxLink = trim((string)($decoded['sandbox_init_point'] ?? ''));
    $liveLink = trim((string)($decoded['init_point'] ?? ''));
    $checkoutUrl = ($sandbox && $sandboxLink !== '') ? $sandboxLink : ($liveLink !== '' ? $liveLink : $sandboxLink);

    echo json_encode([
        'ok'                 => true,
        'preapproval_id'     => $decoded['id'] ?? null,
        'status'             => $decoded['status'] ?? null,
        'status_detail'      => $decoded['status_detail'] ?? null,
        'next_payment_date'  => $nextPayment,
        'payer_email'        => $decoded['payer_email'] ?? $resolvedPayerEmail,
        'sandbox'            => $sandbox,
        'checkout_url'       => $checkoutUrl,
        'init_point'         => $decoded['init_point'] ?? null,
        'sandbox_init_point' => $decoded['sandbox_init_point'] ?? null,
        'requires_redirect'  => $checkoutUrl !== '',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $status = $e instanceof InvalidArgumentException ? 400 : 422;
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
