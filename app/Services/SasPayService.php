<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class SasPayService
{
    private function client()
    {
        $key = config('services.saspay.secret_key');

        if (!$key) {
            throw new RuntimeException('SASPAY_SECRET_KEY is not configured.');
        }

        return Http::baseUrl(rtrim(config('services.saspay.base_url'), '/'))
            ->withToken($key)
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(60);
    }

    public function createCheckoutSession(array $payload): array
    {
        // CORRECTION : Utilisation de l'endpoint officiel /payments/softpay/ exigé par la doc SasPay
        // Et transmission d'une Idempotency-Key unique pour éviter les rejets
        $response = $this->client()
            ->withHeaders([
                'Idempotency-Key' => (string) Str::uuid(),
            ])
            ->post('/payments/softpay/', $payload);

        if (!$response->successful()) {
            $body = $response->json();
            $body = is_array($body) ? $body : [];
            $error = is_array($body['error'] ?? null) ? $body['error'] : [];
            $code = $error['code'] ?? $body['code'] ?? null;
            $message = $body['message'] ?? $body['detail'] ?? $error['message'] ?? null;
            if (!is_string($message) && is_string($body['error'] ?? null)) {
                $message = $body['error'];
            }
            $errors = $body['errors'] ?? $error['errors'] ?? [];
            $bodyExcerpt = $response->body();
            $safeMessage = is_string($message) ? $message : null;

            foreach (['customer_email', 'customer_name', 'customer_phone', 'description', 'return_url'] as $key) {
                $sensitiveValue = $payload[$key] ?? null;
                if (is_string($sensitiveValue) && $sensitiveValue !== '') {
                    $bodyExcerpt = str_replace($sensitiveValue, '[redacted]', $bodyExcerpt);
                    $safeMessage = $safeMessage ? str_replace($sensitiveValue, '[redacted]', $safeMessage) : null;
                }
            }

            $bodyExcerpt = preg_replace('/\bsk_(?:live|test)_[A-Za-z0-9_-]+\b/', '[redacted-secret]', $bodyExcerpt) ?? $bodyExcerpt;
            $bodyExcerpt = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $bodyExcerpt) ?? $bodyExcerpt;
            $bodyExcerpt = mb_substr(trim($bodyExcerpt), 0, 1000);
            if ($safeMessage !== null) {
                $safeMessage = preg_replace('/\bsk_(?:live|test)_[A-Za-z0-9_-]+\b/', '[redacted-secret]', $safeMessage) ?? $safeMessage;
                $safeMessage = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $safeMessage) ?? $safeMessage;
            }

            Log::warning('SasPay rejected checkout session request', [
                'http_status' => $response->status(),
                'provider_code' => is_scalar($code) ? (string) $code : null,
                'provider_message' => $safeMessage !== null ? mb_substr($safeMessage, 0, 300) : null,
                'validation_fields' => is_array($errors) ? array_slice(array_map('strval', array_keys($errors)), 0, 20) : [],
                'provider_body_excerpt' => $bodyExcerpt,
                'raw_response' => $response->body(),
            ]);

            throw new RuntimeException('SasPay checkout session failed: HTTP '.$response->status().($code ? ' ('.$code.')' : ''));
        }

        return $response->json('data') ?? $response->json() ?? [];
    }

    public function checkoutSessionStatus(string $sessionId): array
    {
        $response = $this->client()->get('/checkout-sessions/'.rawurlencode($sessionId).'/status');

        if (!$response->successful()) {
            throw new RuntimeException('SasPay checkout status failed: HTTP '.$response->status());
        }

        return $response->json('data') ?? $response->json() ?? [];
    }

    public function verifyPayment(string $paymentId): array
    {
        // Aligné sur la documentation : GET /payments/{payment_id}/verify/
        $response = $this->client()->get('/payments/'.rawurlencode($paymentId).'/verify/');

        if (!$response->successful()) {
            throw new RuntimeException('SasPay payment verification failed: HTTP '.$response->status());
        }

        return $response->json('data') ?? $response->json() ?? [];
    }
}