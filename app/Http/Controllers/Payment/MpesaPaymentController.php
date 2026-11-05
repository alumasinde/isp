<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Services\MpesaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public Safaricom Daraja endpoints.
 *
 * These routes are CSRF-exempt and unauthenticated, exactly as Safaricom
 * requires. M-Pesa callbacks are not signed, so MpesaService verifies the
 * result against the STK Query endpoint before crediting a customer.
 */
class MpesaPaymentController extends Controller
{
    public function __construct(private MpesaService $mpesa)
    {
    }

    public function callback(Request $request): JsonResponse
    {
        $payload = $request->json()->all() ?: $request->all();

        try {
            $this->mpesa->handleStkCallback($payload);
        } catch (\Throwable $e) {
            Log::error('M-Pesa STK callback handling failed: '.$e->getMessage(), ['payload' => $payload]);
        }

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    public function c2bValidation(Request $request): JsonResponse
    {
        $reference = trim((string) $request->input('BillRefNumber', ''));

        if (! $this->mpesa->isKnownAccount($reference)) {
            Log::info('M-Pesa C2B validation rejected an unknown account reference.', ['reference' => $reference]);

            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Unknown account reference']);
        }

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    public function c2bConfirmation(Request $request): JsonResponse
    {
        $payload = $request->json()->all() ?: $request->all();

        try {
            $this->mpesa->handleC2bConfirmation($payload);
        } catch (\Throwable $e) {
            Log::error('M-Pesa C2B confirmation handling failed: '.$e->getMessage(), ['payload' => $payload]);
        }

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
