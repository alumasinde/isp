<?php

namespace App\Services;

use App\Models\CollectionSummary;
use App\Models\CustomersInfo;
use App\Models\MpesaPayment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Safaricom M-Pesa (Daraja) client for Kenyan billing.
 *
 * Covers STK Push (customer pays from the portal) and C2B Paybill/Till
 * confirmations. Both paths credit the customer through PaymentService and are
 * idempotent on the M-Pesa receipt number.
 */
class MpesaService
{
    private const RESULT_MESSAGES = [
        1 => 'There was not enough money in the M-Pesa account to complete the payment.',
        1001 => 'Another M-Pesa request is already in progress for this number. Please wait a moment.',
        1019 => 'The payment request expired. Please try again.',
        1032 => 'The payment request was cancelled on the phone.',
        1037 => 'The payment request timed out before it was approved on the phone.',
        2001 => 'The M-Pesa PIN was entered incorrectly.',
    ];

    public function __construct(private PaymentService $payments)
    {
    }

    public function driver(): array
    {
        return (array) config('payment.drivers.mpesa', []);
    }

    public function isConfigured(): bool
    {
        $driver = $this->driver();

        return ! empty($driver['enabled'])
            && filled($driver['consumer_key'] ?? null)
            && filled($driver['consumer_secret'] ?? null)
            && filled($driver['shortcode'] ?? null)
            && filled($driver['passkey'] ?? null);
    }

    /**
     * Convert any Kenyan mobile number format to the 2547XXXXXXXX / 2541XXXXXXXX
     * form Daraja requires. Returns null when it is not a Kenyan mobile number.
     */
    public function normalizeMsisdn(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === '' || $digits === null) {
            return null;
        }

        if (str_starts_with($digits, '254')) {
            $msisdn = $digits;
        } elseif (str_starts_with($digits, '0')) {
            $msisdn = '254'.substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $msisdn = '254'.$digits;
        } else {
            $msisdn = $digits;
        }

        return preg_match('/^254(7|1)\d{8}$/', $msisdn) ? $msisdn : null;
    }

    /**
     * M-Pesa AccountReference is limited to 12 characters.
     */
    public function accountReference(CustomersInfo $customer): string
    {
        $reference = preg_replace('/[^A-Za-z0-9]/', '', (string) $customer->customer_unique_id);

        return Str::limit($reference ?: 'ISP', 12, '');
    }

    /**
     * M-Pesa only accepts whole shillings and caps a request at KES 250,000.
     */
    public function payableAmount(float $amount): int
    {
        $amount = round($amount, 2);
        $max = (int) ($this->driver()['max_amount'] ?? 250000);

        if ($amount < 1) {
            throw new RuntimeException('The minimum M-Pesa payment is KES 1.');
        }

        if ($amount > $max) {
            throw new RuntimeException('M-Pesa payments are limited to KES '.number_format($max).' per request.');
        }

        return (int) ceil($amount);
    }

    public function accessToken(): string
    {
        $driver = $this->driver();
        $url = rtrim((string) $driver['base_url'], '/').'/oauth/v1/generate?grant_type=client_credentials';
        $cacheKey = 'mpesa:token:'.md5($url.'|'.($driver['consumer_key'] ?? ''));

        return Cache::remember($cacheKey, 3300, function () use ($driver, $url) {
            $response = Http::timeout(15)
                ->withBasicAuth((string) $driver['consumer_key'], (string) $driver['consumer_secret'])
                ->acceptJson()
                ->get($url);

            $token = $response->json('access_token');

            if (! $response->successful() || blank($token)) {
                throw new RuntimeException('Unable to authenticate with M-Pesa (Daraja): '.$response->body());
            }

            return $token;
        });
    }

    /**
     * Send an STK Push prompt to the customer's phone.
     */
    public function stkPush(string $msisdn, float $amount, string $accountReference, string $description = 'Internet bill'): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('M-Pesa is not configured. Set the MPESA_* variables in the environment.');
        }

        $driver = $this->driver();
        $shortcode = (string) $driver['shortcode'];
        $timestamp = now('Africa/Nairobi')->format('YmdHis');
        $callbackUrl = $driver['callback_url'] ?: route('payment.mpesa.callback');

        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password' => base64_encode($shortcode.$driver['passkey'].$timestamp),
            'Timestamp' => $timestamp,
            'TransactionType' => $driver['transaction_type'] ?: 'CustomerPayBillOnline',
            'Amount' => $this->payableAmount($amount),
            'PartyA' => $msisdn,
            'PartyB' => $shortcode,
            'PhoneNumber' => $msisdn,
            'CallBackURL' => $callbackUrl,
            'AccountReference' => Str::limit(preg_replace('/[^A-Za-z0-9\-]/', '', $accountReference) ?: 'ISP', 12, ''),
            'TransactionDesc' => Str::limit($description, 13, ''),
        ];

        $response = Http::timeout(30)
            ->withToken($this->accessToken())
            ->acceptJson()
            ->post(rtrim((string) $driver['base_url'], '/').'/mpesa/stkpush/v1/processrequest', $payload);

        $body = $response->json() ?? [];

        if (! $response->successful() || (string) ($body['ResponseCode'] ?? '') !== '0') {
            $message = $body['errorMessage']
                ?? $body['ResponseDescription']
                ?? 'M-Pesa rejected the payment request.';

            throw new RuntimeException($message);
        }

        return $body;
    }

    public function queryStatus(string $checkoutRequestId): array
    {
        $driver = $this->driver();
        $shortcode = (string) $driver['shortcode'];
        $timestamp = now('Africa/Nairobi')->format('YmdHis');

        $response = Http::timeout(30)
            ->withToken($this->accessToken())
            ->acceptJson()
            ->post(rtrim((string) $driver['base_url'], '/').'/mpesa/stkpushquery/v1/query', [
                'BusinessShortCode' => $shortcode,
                'Password' => base64_encode($shortcode.$driver['passkey'].$timestamp),
                'Timestamp' => $timestamp,
                'CheckoutRequestID' => $checkoutRequestId,
            ]);

        return $response->json() ?? [];
    }

    /**
     * Register the C2B validation/confirmation URLs for the paybill.
     */
    public function registerC2bUrls(): array
    {
        $driver = $this->driver();

        $response = Http::timeout(30)
            ->withToken($this->accessToken())
            ->acceptJson()
            ->post(rtrim((string) $driver['base_url'], '/').'/mpesa/c2b/v1/registerurl', [
                'ShortCode' => (string) $driver['shortcode'],
                'ResponseType' => $driver['c2b_response_type'] ?: 'Completed',
                'ConfirmationURL' => $driver['c2b_confirmation_url'] ?: route('payment.mpesa.c2b.confirmation'),
                'ValidationURL' => $driver['c2b_validation_url'] ?: route('payment.mpesa.c2b.validation'),
            ]);

        $body = $response->json() ?? [];

        if (! $response->successful()) {
            throw new RuntimeException('Unable to register M-Pesa C2B URLs: '.$response->body());
        }

        return $body;
    }

    public function isKnownAccount(string $reference): bool
    {
        $reference = trim($reference);

        return $reference !== ''
            && CustomersInfo::where('customer_unique_id', $reference)->exists();
    }

    /**
     * Handle a Safaricom STK Push callback, then credit the customer.
     */
    public function handleStkCallback(array $payload): void
    {
        $callback = $payload['Body']['stkCallback'] ?? $payload;
        $checkoutId = $callback['CheckoutRequestID'] ?? null;

        if (blank($checkoutId)) {
            Log::warning('M-Pesa STK callback received without CheckoutRequestID.', $payload);

            return;
        }

        $payment = MpesaPayment::where('checkout_request_id', $checkoutId)->first();

        if (! $payment) {
            Log::warning("M-Pesa STK callback for unknown CheckoutRequestID {$checkoutId}.");

            return;
        }

        if ($payment->isCompleted()) {
            return;
        }

        $resultCode = (int) ($callback['ResultCode'] ?? -1);
        $resultDesc = (string) ($callback['ResultDesc'] ?? '');

        if ($resultCode !== 0) {
            $payment->update([
                'status' => in_array($resultCode, [1032, 1037], true)
                    ? MpesaPayment::STATUS_CANCELLED
                    : MpesaPayment::STATUS_FAILED,
                'result_code' => (string) $resultCode,
                'result_desc' => $resultDesc,
                'raw_callback' => $payload,
            ]);

            return;
        }

        $metadata = collect($callback['CallbackMetadata']['Item'] ?? [])->pluck('Value', 'Name');
        $receipt = trim((string) ($metadata['MpesaReceiptNumber'] ?? ''));
        $paidAmount = (float) ($metadata['Amount'] ?? 0);

        if ($receipt === '') {
            $this->markFailed($payment, $payload, 'Callback did not include an M-Pesa receipt number.');

            return;
        }

        if ($paidAmount + 0.01 < (float) $payment->amount) {
            $this->markFailed(
                $payment,
                $payload,
                "Amount mismatch: expected KES {$payment->amount}, received KES {$paidAmount}."
            );

            return;
        }

        if (MpesaPayment::where('mpesa_receipt', $receipt)->whereKeyNot($payment->getKey())->exists()) {
            Log::warning("M-Pesa receipt {$receipt} was already recorded against another payment.");

            return;
        }

        if (($this->driver()['verify_with_query'] ?? false) && ! $this->queryConfirmsSuccess($checkoutId)) {
            $this->markFailed($payment, $payload, 'STK query did not confirm a successful payment.');

            return;
        }

        $payment->update([
            'status' => MpesaPayment::STATUS_COMPLETED,
            'mpesa_receipt' => $receipt,
            'amount' => $paidAmount > 0 ? $paidAmount : $payment->amount,
            'result_code' => '0',
            'result_desc' => 'STK payment completed',
            'raw_callback' => $payload,
            'paid_at' => now(),
        ]);

        $this->creditCustomer($payment, $paidAmount > 0 ? $paidAmount : (float) $payment->amount, $receipt);
    }

    /**
     * Handle a C2B (customer entered paybill number) confirmation.
     */
    public function handleC2bConfirmation(array $payload): void
    {
        $receipt = trim((string) ($payload['TransID'] ?? ''));
        $reference = trim((string) ($payload['BillRefNumber'] ?? ''));
        $amount = (float) ($payload['TransAmount'] ?? 0);
        $msisdn = $this->normalizeMsisdn($payload['MSISDN'] ?? null)
            ?? (string) ($payload['MSISDN'] ?? '');

        if ($receipt === '' || $reference === '' || $amount <= 0) {
            throw new RuntimeException('Incomplete M-Pesa C2B confirmation payload.');
        }

        $alreadyCredited = CollectionSummary::where('transaction_id', $receipt)->exists()
            || MpesaPayment::where('mpesa_receipt', $receipt)
                ->where('status', MpesaPayment::STATUS_COMPLETED)
                ->exists();

        if ($alreadyCredited) {
            Log::info("M-Pesa C2B duplicate receipt {$receipt} ignored.");

            return;
        }

        $customer = CustomersInfo::where('customer_unique_id', $reference)->first();

        if (! $customer) {
            MpesaPayment::updateOrCreate(['mpesa_receipt' => $receipt], [
                'customer_unique_id' => '',
                'purpose' => 'c2b',
                'phone' => $msisdn,
                'amount' => $amount,
                'currency' => config('payment.currency', 'KES'),
                'account_reference' => Str::limit($reference, 32, ''),
                'status' => MpesaPayment::STATUS_FAILED,
                'result_code' => '0',
                'result_desc' => "Unknown account reference: {$reference}",
                'raw_callback' => $payload,
                'paid_at' => now(),
            ]);

            Log::warning("M-Pesa C2B payment {$receipt} for unknown account reference {$reference}.");

            return;
        }

        $payment = MpesaPayment::updateOrCreate(['mpesa_receipt' => $receipt], [
            'customer_unique_id' => $customer->customer_unique_id,
            'purpose' => 'c2b',
            'phone' => $msisdn,
            'amount' => $amount,
            'currency' => config('payment.currency', 'KES'),
            'account_reference' => Str::limit($reference, 32, ''),
            'status' => MpesaPayment::STATUS_COMPLETED,
            'result_code' => '0',
            'result_desc' => 'C2B confirmation received',
            'raw_callback' => $payload,
            'paid_at' => now(),
        ]);

        $this->creditCustomer($payment, $amount, $receipt);
    }

    public function failureMessage(?string $resultCode, ?string $fallback = null): string
    {
        return self::RESULT_MESSAGES[(int) $resultCode]
            ?? $fallback
            ?? 'The M-Pesa payment did not complete. Please try again.';
    }

    private function creditCustomer(MpesaPayment $payment, float $amount, string $receipt): void
    {
        $customer = CustomersInfo::where('customer_unique_id', $payment->customer_unique_id)->first();

        if (! $customer) {
            Log::error("M-Pesa receipt {$receipt} received but customer {$payment->customer_unique_id} no longer exists.");

            return;
        }

        try {
            $this->payments->processSuccessPayment($customer, $amount, 'mpesa', $receipt);
        } catch (\Throwable $e) {
            $payment->update([
                'status' => MpesaPayment::STATUS_FAILED,
                'result_desc' => 'Payment received but crediting failed: '.$e->getMessage(),
            ]);

            Log::error("M-Pesa receipt {$receipt} received but crediting failed: ".$e->getMessage());
        }
    }

    private function markFailed(MpesaPayment $payment, array $payload, string $reason): void
    {
        $payment->update([
            'status' => MpesaPayment::STATUS_FAILED,
            'result_desc' => $reason,
            'raw_callback' => $payload,
        ]);

        Log::warning("M-Pesa payment {$payment->id} failed: {$reason}");
    }

    private function queryConfirmsSuccess(string $checkoutId): bool
    {
        try {
            $result = $this->queryStatus($checkoutId);
            $code = (string) ($result['ResultCode'] ?? '');

            // 0 = completed; 1037 = still processing at Safaricom.
            return $code === '0' || $code === '1037';
        } catch (\Throwable $e) {
            Log::warning('M-Pesa STK query verification failed; trusting the callback: '.$e->getMessage());

            return true;
        }
    }
}
