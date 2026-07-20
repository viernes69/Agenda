<?php
/**
 * Dlocal Go - helper para suscripciones recurrentes.
 *
 * API docs: https://docs.dlocalgo.com/integration-api
 *
 * Cubre lo necesario para:
 *   - Crear / listar / obtener planes de suscripcion
 *   - Generar la URL de checkout (subscribe URL) para un plan
 *   - Cancelar suscripciones
 *   - Validar firma HMAC-SHA256 de webhooks
 *
 * Patrón de uso:
 *   $dlocal = new Dlocal($apiKey, $secretKey, $sandbox);
 *   $plan = $dlocal->createPlan([...]);
 *   $url  = $dlocal->subscribeUrl($plan['plan_token'], 'a@b.com', 'ext-1');
 *   $ok   = $dlocal->verifyWebhookSignature($rawBody, $header);
 *
 * Las credenciales se leen del tenant (`database.php` -> `dlocal.api_key`)
 * o del global (`payment_provider_config`). No commitees keys al repo.
 */
declare(strict_types=1);

namespace Agenduy\Core;

use RuntimeException;

final class Dlocal
{
    public const SANDBOX_BASE = 'https://api-sbx.dlocalgo.com';
    public const LIVE_BASE    = 'https://api.dlocalgo.com';
    public const SANDBOX_CHECKOUT = 'https://checkout-sbx.dlocalgo.com';
    public const LIVE_CHECKOUT    = 'https://checkout.dlocalgo.com';

    public const PLAN_FREQ_DAILY   = 'DAILY';
    public const PLAN_FREQ_WEEKLY  = 'WEEKLY';
    public const PLAN_FREQ_MONTHLY = 'MONTHLY';
    public const PLAN_FREQ_YEARLY  = 'YEARLY';

    private string $apiKey;
    private string $secretKey;
    private bool   $sandbox;
    private ?string $baseUrlOverride = null;
    private ?string $checkoutBaseOverride = null;

    public function __construct(
        string $apiKey,
        string $secretKey,
        bool $sandbox = true,
        ?string $baseUrlOverride = null,
        ?string $checkoutBaseOverride = null
    ) {
        $this->apiKey    = trim($apiKey);
        $this->secretKey = trim($secretKey);
        $this->sandbox   = $sandbox;
        $this->baseUrlOverride = $baseUrlOverride !== null ? rtrim($baseUrlOverride, '/') : null;
        $this->checkoutBaseOverride = $checkoutBaseOverride !== null ? rtrim($checkoutBaseOverride, '/') : null;
        if ($this->apiKey === '' || $this->secretKey === '') {
            throw new RuntimeException('dLocal Go: faltan credenciales (api_key / secret_key).');
        }
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    public function baseUrl(): string
    {
        return $this->baseUrlOverride ?? ($this->sandbox ? self::SANDBOX_BASE : self::LIVE_BASE);
    }

    public function checkoutBase(): string
    {
        return $this->checkoutBaseOverride ?? ($this->sandbox ? self::SANDBOX_CHECKOUT : self::LIVE_CHECKOUT);
    }

    /**
     * Header de autorización: "Bearer api_key:secret_key"
     */
    public function authHeader(): string
    {
        return 'Bearer ' . $this->apiKey . ':' . $this->secretKey;
    }

    /**
     * Crea un plan de suscripción en dLocal Go.
     *
     * @param array<string,mixed> $payload Campos: name, description, currency, amount, frequency_type,
     *                                     frequency_value, country (opcional), max_periods (opcional),
     *                                     day_of_month (opcional), free_trial_days (opcional),
     *                                     notification_url, success_url, back_url, error_url
     * @return array<string,mixed> Plan creado (incluye plan_token y subscribe_url)
     */
    public function createPlan(array $payload): array
    {
        return $this->request('POST', '/v1/subscription/plan', $payload);
    }

    /**
     * Lista planes del merchant. Devuelve un array (puede estar vacío).
     *
     * @return list<array<string,mixed>>
     */
    public function listPlans(): array
    {
        $res = $this->request('GET', '/v1/subscription/plan');
        if (isset($res['plans']) && is_array($res['plans'])) {
            return array_values(array_filter($res['plans'], 'is_array'));
        }
        if (is_array($res) && array_is_list($res)) {
            return $res;
        }
        if ($res === [] || $res === null) {
            return [];
        }
        return [$res];
    }

    /**
     * Recupera un plan específico.
     *
     * @return array<string,mixed>
     */
    public function getPlan(int $planId): array
    {
        return $this->request('GET', '/v1/subscription/plan/' . $planId);
    }

    /**
     * Cancela (desactiva) una suscripción.
     *
     * @return array<string,mixed>
     */
    public function cancelSubscription(int $planId, int $subscriptionId): array
    {
        return $this->request(
            'PATCH',
            '/v1/subscription/plan/' . $planId . '/subscription/' . $subscriptionId . '/deactivate'
        );
    }

    /**
     * Construye la URL de checkout a la que se redirige al cliente.
     *
     * @param string $planToken Token del plan devuelto por dLocal.
     * @param string|null $email Pre-llena el email en el checkout (opcional).
     * @param string|null $externalId Identificador interno (llega al success_url como query string).
     */
    public function subscribeUrl(string $planToken, ?string $email = null, ?string $externalId = null): string
    {
        $base = $this->checkoutBase() . '/validate/subscription/' . $planToken;
        $query = [];
        if ($email !== null && $email !== '') {
            $query['email'] = $email;
        }
        if ($externalId !== null && $externalId !== '') {
            $query['external_id'] = $externalId;
        }
        if ($query !== []) {
            $base .= '?' . http_build_query($query);
        }
        return $base;
    }

    /**
     * Genera la firma HMAC-SHA256 que espera dLocal en el header del webhook.
     *
     * Regla: Signature = HMAC-SHA256(api_key + raw_body, secret_key) en hex.
     */
    public function signWebhookPayload(string $rawBody): string
    {
        $message = $this->apiKey . $rawBody;
        return hash_hmac('sha256', $message, $this->secretKey);
    }

    /**
     * Valida la firma recibida en el header del webhook.
     *
     * Acepta:
     *   - "V2-HMAC-SHA256, Signature: <hex>"
     *   - "V2-HMAC-SHA256 Signature=<hex>"
     *   - "<hex>" suelto
     *
     * Devuelve true si la firma coincide.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $authHeader): bool
    {
        if ($authHeader === null || $authHeader === '') {
            return false;
        }
        $sig = $this->extractSignature($authHeader);
        if ($sig === null) {
            return false;
        }
        $expected = $this->signWebhookPayload($rawBody);
        return hash_equals($expected, strtolower($sig));
    }

    /**
     * Extrae el valor Signature del header Authorization.
     */
    private function extractSignature(string $header): ?string
    {
        $header = trim($header);
        // Caso 1: "V2-HMAC-SHA256, Signature: <hex>"
        if (preg_match('/Signature\s*[:=]\s*([a-f0-9]+)/i', $header, $m) === 1) {
            return $m[1];
        }
        // Caso 2: hex suelto
        if (preg_match('/^[a-f0-9]{32,128}$/i', $header) === 1) {
            return $header;
        }
        return null;
    }

    /**
     * Llamada genérica a la API.
     *
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl() . $path;
        $headers = [
            'Authorization: ' . $this->authHeader(),
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('dLocal: no se pudo inicializar cURL.');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('dLocal: error de red: ' . $err);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            if ($status >= 400) {
                throw new RuntimeException('dLocal: HTTP ' . $status . ' (respuesta no JSON).');
            }
            return [];
        }

        if ($status >= 400) {
            throw new RuntimeException(self::userErrorMessage($decoded, $status));
        }
        return $decoded;
    }

    /**
     * Traduce la respuesta de error de dLocal a algo legible para el admin.
     */
    public static function userErrorMessage(?array $decoded, int $statusCode): string
    {
        if (!is_array($decoded)) {
            return 'dLocal: error HTTP ' . $statusCode . '.';
        }
        $code = isset($decoded['code']) ? (int)$decoded['code'] : 0;
        $msg  = isset($decoded['message']) ? (string)$decoded['message'] : '';
        $detail = '';
        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            $parts = [];
            foreach ($decoded['errors'] as $err) {
                if (is_array($err) && isset($err['message'])) {
                    $parts[] = (string)$err['message'];
                } elseif (is_string($err)) {
                    $parts[] = $err;
                }
            }
            if ($parts !== []) {
                $detail = implode('; ', $parts);
            }
        }

        switch ($code) {
            case 3001:
                return 'dLocal: credenciales inválidas. Verificá API Key y Secret Key en Config.';
            case 7000:
                return 'dLocal: error interno del proveedor. Reintentá en unos minutos.';
        }

        if ($statusCode === 401 || $statusCode === 403) {
            return 'dLocal: no autorizado (' . ($msg !== '' ? $msg : 'sin detalle') . ').';
        }
        if ($statusCode === 404) {
            return 'dLocal: recurso no encontrado.';
        }
        if ($statusCode === 429) {
            return 'dLocal: rate limit alcanzado. Esperá unos segundos y reintentá.';
        }
        if ($statusCode >= 500) {
            return 'dLocal: error del servidor (' . ($msg !== '' ? $msg : 'sin detalle') . ').';
        }
        if ($detail !== '') {
            return 'dLocal: ' . $detail;
        }
        if ($msg !== '') {
            return 'dLocal: ' . $msg;
        }
        return 'dLocal: error HTTP ' . $statusCode . '.';
    }

    /**
     * Construye una instancia desde el array de config de un tenant.
     *
     * Acepta cualquiera de las dos formas:
     *   ['dlocal' => ['api_key' => '...', 'secret_key' => '...', 'sandbox' => true]]
     *   ['api_key' => '...', 'secret_key' => '...', 'sandbox' => true]  (legacy)
     *
     * Acepta opcionalmente base_url y checkout_base para tests (apuntar a un mock server).
     *
     * @param array<string,mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        if (isset($config['dlocal']) && is_array($config['dlocal'])) {
            $config = $config['dlocal'];
        }
        $apiKey    = trim((string)($config['api_key'] ?? ''));
        $secretKey = trim((string)($config['secret_key'] ?? ''));
        $sandbox   = (bool)($config['sandbox'] ?? true);
        $baseUrl      = isset($config['base_url']) && is_string($config['base_url']) && $config['base_url'] !== ''
            ? (string)$config['base_url']
            : null;
        $checkoutBase = isset($config['checkout_base']) && is_string($config['checkout_base']) && $config['checkout_base'] !== ''
            ? (string)$config['checkout_base']
            : null;
        if ($apiKey === '' || $secretKey === '') {
            throw new RuntimeException('dLocal no configurado: cargá API Key y Secret Key en el panel del comercio.');
        }
        return new self($apiKey, $secretKey, $sandbox, $baseUrl, $checkoutBase);
    }
}
