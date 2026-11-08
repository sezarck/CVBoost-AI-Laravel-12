<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cv;
use App\Models\Payment;
use App\Services\SasPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function store(Request $request, SasPayService $saspay)
    {
        $data = $request->validate([
            'cv_id' => 'required|integer',
            'phone_number' => 'nullable|string|min:8|max:25',
            'operator' => 'nullable|string|in:MTN,ORANGE,WAVE,MOOV,MOBICASH,AIRTEL,VODACOM',
        ]);

        $cv = $request->user()->cvs()->findOrFail($data['cv_id']);

        if ($cv->export_paid_at && $cv->print_count < 2 && $cv->modification_count < 1) {
            return response()->json([
                'message' => 'Le paiement actuel permet encore d’exporter et de modifier ce CV.',
                'code' => 'quota_not_exhausted',
            ], 409);
        }

        if ($cv->payments()->where('status', 'pending')->exists()) {
            return response()->json([
                'message' => 'Un paiement est déjà en attente pour ce CV.',
                'code' => 'payment_pending',
            ], 409);
        }

        $order = 'CVB-'.Str::upper(Str::random(18));
        $amount = (int) config('services.cv.export_price', 500);
        $currency = strtoupper(config('services.cv.currency', 'XAF'));

        $payment = Payment::create([
            'user_id' => $request->user()->id,
            'cv_id' => $cv->id,
            'order_id' => $order,
            'provider' => 'saspay',
            'amount' => $amount,
            'currency' => $currency,
            'operator' => $data['operator'] ?? null,
            'phone_number' => $data['phone_number'] ?? null,
            'status' => 'pending',
        ]);

        $returnUrl = URL::temporarySignedRoute(
            'payments.return',
            now()->addHours(2),
            ['payment' => $payment->id],
        );

        try {
            $checkoutPayload = [
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => $currency,
                'description' => 'Exportation du CV '.$cv->title,
                'country' => config('services.saspay.country', 'CM'),
                'customer_email' => $request->user()->email,
                'customer_name' => $request->user()->name,
                'return_url' => $returnUrl,
                'metadata' => [
                    'local_payment_id' => (string) $payment->id,
                    'order_id' => $order,
                    'cv_id' => (string) $cv->id,
                ],
            ];
            if (!empty($data['phone_number'])) {
                $checkoutPayload['customer_phone'] = $data['phone_number'];
            }

            $session = $saspay->createCheckoutSession($checkoutPayload);

            if (empty($session['id']) || empty($session['checkout_url'])) {
                throw new \RuntimeException('SasPay returned an incomplete checkout session.');
            }

            $payment->update([
                'provider_reference' => $session['id'],
                'payment_link' => $session['checkout_url'],
                'provider_payload' => $session,
            ]);

            return response()->json([
                'payment' => $payment->fresh(),
                'payment_link' => $payment->payment_link,
                'message' => 'Session de paiement créée. Le statut sera vérifié auprès de SasPay au retour du client.',
            ], 201);
        } catch (\Throwable $exception) {
            $payment->update(['status' => 'failed']);
            Log::error('SasPay checkout creation failed', [
                'payment_id' => $payment->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Impossible de créer la session de paiement SasPay.'], 502);
        }
    }

    public function returnFromCheckout(Payment $payment, SasPayService $saspay)
    {
        abort_unless($payment->provider === 'saspay', 404);
        $this->refreshStatus($payment, $saspay);

        $status = $payment->fresh()->status;
        $returnUrl = rtrim(config('services.saspay.return_frontend_url'), '/')
            .'/resultat/'.$payment->cv_id
            .'?'.http_build_query([
                'payment_status' => $status,
                'payment_id' => $payment->id,
            ]);

        return redirect()->away($returnUrl);
    }

    public function show(Request $request, Payment $payment, SasPayService $saspay)
    {
        abort_unless($payment->user_id === $request->user()->id, 404);
        $this->refreshStatus($payment, $saspay);

        return response()->json(['payment' => $payment->fresh()]);
    }

    public function index(Request $request)
    {
        return response()->json($request->user()->payments()->latest()->paginate(20));
    }

    public function exportPermission(Request $request, Cv $cv)
    {
        abort_unless($cv->user_id === $request->user()->id, 404);
        $paid = (bool) $cv->export_paid_at;

        return response()->json([
            'allowed' => $paid && $cv->print_count < 2,
            'paid' => $paid,
            'paid_at' => $cv->export_paid_at,
            'prints_remaining' => max(0, 2 - $cv->print_count),
            'modifications_remaining' => max(0, 1 - $cv->modification_count),
            'modification_allowed' => $paid && $cv->modification_count < 1,
            'price' => config('services.cv.export_price'),
            'currency' => config('services.cv.currency'),
        ]);
    }

    public function consumeExport(Request $request, Cv $cv)
    {
        return DB::transaction(function () use ($request, $cv) {
            $cv = Cv::query()->lockForUpdate()->findOrFail($cv->id);
            abort_unless($cv->user_id === $request->user()->id, 404);

            if (!$cv->export_paid_at || $cv->print_count >= 2) {
                return response()->json([
                    'message' => 'La limite d’export de ce paiement est atteinte. Effectuez un nouvel export payant.',
                    'code' => 'export_limit_reached',
                ], 402);
            }

            $cv->increment('print_count');

            return response()->json([
                'allowed' => true,
                'prints_remaining' => max(0, 2 - $cv->fresh()->print_count),
            ]);
        });
    }

    private function refreshStatus(Payment $payment, SasPayService $saspay): void
    {
        if ($payment->provider !== 'saspay' || $payment->status !== 'pending' || !$payment->provider_reference) {
            return;
        }

        try {
            $session = $saspay->checkoutSessionStatus($payment->provider_reference);
            $sessionStatus = strtoupper($session['status'] ?? '');
            $transactionStatus = strtoupper($session['transaction_status'] ?? '');

            if (in_array($sessionStatus, ['EXPIRED', 'CANCELLED', 'CANCELED', 'FAILED'], true)
                || in_array($transactionStatus, ['FAILED', 'CANCELLED', 'CANCELED'], true)) {
                $payment->update(['status' => 'failed', 'provider_payload' => $session]);

                return;
            }

            if ($sessionStatus !== 'PAID' || $transactionStatus !== 'SUCCESS' || empty($session['transaction_id'])) {
                return;
            }

            $transaction = $saspay->verifyPayment((string) $session['transaction_id']);
            $verifiedStatus = strtoupper($transaction['status'] ?? '');
            $verifiedCurrency = strtoupper($transaction['currency'] ?? '');
            $verifiedAmount = (float) ($transaction['requested_amount'] ?? -1);

            if ($verifiedStatus !== 'SUCCESS'
                || $verifiedCurrency !== strtoupper($payment->currency)
                || $verifiedAmount !== (float) $payment->amount) {
                Log::warning('SasPay transaction did not match the local payment', [
                    'payment_id' => $payment->id,
                    'provider_status' => $verifiedStatus,
                    'provider_currency' => $verifiedCurrency,
                ]);

                return;
            }

            DB::transaction(function () use ($payment, $session, $transaction) {
                $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
                if ($lockedPayment->status !== 'pending') {
                    return;
                }

                $lockedPayment->update([
                    'status' => 'completed',
                    'provider_payload' => ['session' => $session, 'transaction' => $transaction],
                    'verified_at' => now(),
                ]);
                $cv = Cv::query()->lockForUpdate()->findOrFail($lockedPayment->cv_id);
                $cv->update([
                    'export_paid_at' => now(),
                    'print_count' => 0,
                    'modification_count' => 0,
                    'modification_session_id' => null,
                    'modification_session_expires_at' => null,
                ]);
            });
        } catch (\Throwable $exception) {
            Log::warning('SasPay checkout status remains pending', [
                'payment_id' => $payment->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
