<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
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
            \Illuminate\Support\Facades\Log::warning('SasPay checkout 4xx/5xx response', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 1000)]);
            $code = $response->json('error.code') ?? $response->json('code');
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
