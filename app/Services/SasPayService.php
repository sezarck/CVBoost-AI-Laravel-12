<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        $response = $this->client()->post('/checkout-sessions/', $payload);

        if (!$response->successful()) {
            $body = $response->json();
            $body = is_array($body) ? $body : [];
            $error = is_array($body['error'] ?? null) ? $body['error'] : [];
            $code = $error['code'] ?? $body['code'] ?? null;
            $message = $body['message'] ?? $error['message'] ?? null;
            $errors = $body['errors'] ?? $error['errors'] ?? [];

            Log::warning('SasPay rejected checkout session request', [
                'http_status' => $response->status(),
                'provider_code' => is_scalar($code) ? (string) $code : null,
                'provider_message' => is_string($message) ? mb_substr($message, 0, 300) : null,
                'validation_fields' => is_array($errors) ? array_slice(array_map('strval', array_keys($errors)), 0, 20) : [],
            ]);

            throw new RuntimeException('SasPay checkout session failed: HTTP '.$response->status().($code ? ' ('.$code.')' : ''));
        }

        return $response->json('data') ?? $response->json() ?? [];
    }

    public function checkoutSessionStatus(string $sessionId): array
    {
        $response = $this->client()->get('/checkout-sessions/'.rawurlencode($sessionId).'/status/');

        if (!$response->successful()) {
            throw new RuntimeException('SasPay checkout status failed: HTTP '.$response->status());
        }

        return $response->json('data') ?? $response->json() ?? [];
    }

    public function verifyPayment(string $paymentId): array
    {
        $response = $this->client()->get('/payments/'.rawurlencode($paymentId).'/verify/');

        if (!$response->successful()) {
            throw new RuntimeException('SasPay payment verification failed: HTTP '.$response->status());
        }

        return $response->json('data') ?? $response->json() ?? [];
    }
}
