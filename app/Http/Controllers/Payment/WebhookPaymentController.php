<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\CollectionSummary;
use App\Models\CustomersInfo;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookPaymentController extends Controller
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Handle incoming bKash Merchant IPN webhook.
     */
    public function handleBkashIpn(Request $request)
    {
        // ── Gate 1: bKash payment must be enabled in settings ──────────────────
        if (! siteUrlSettings('payment_bkash_enabled')) {
            Log::warning('bKash IPN received but bKash payment gateway is disabled.');
            return response()->json(['status' => 'error', 'message' => 'Gateway not enabled'], 403);
        }

        // Log the raw incoming payload for auditing (before any processing)
        Log::info('bKash Merchant IPN Webhook received: ' . json_encode($request->all()));

        // ── Gate 2: HMAC-SHA256 shared-secret signature verification ───────────
        // bKash (or your proxy) must send X-Bkash-Signature: HMAC-SHA256(secret, raw_body)
        // Configure payment_bkash_ipn_secret in your site settings / services config.
        $webhookSecret = siteUrlSettings('payment_bkash_ipn_secret')
            ?: config('services.bkash.ipn_secret');

        if (! empty($webhookSecret)) {
            $signature = $request->header('X-Bkash-Signature');
            $rawBody   = $request->getContent();
            $expected  = hash_hmac('sha256', $rawBody, $webhookSecret);

            if (! hash_equals($expected, (string) $signature)) {
                Log::warning('bKash IPN rejected: invalid HMAC signature.');
                return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
            }
        } else {
            Log::warning('bKash IPN: payment_bkash_ipn_secret is not configured — signature check skipped (set it ASAP).');
        }

        // ── Parse fields ───────────────────────────────────────────────────────
        $trxId          = $request->input('trxID') ?? $request->input('transactionId') ?? $request->input('trxId');
        $amount         = $request->input('amount');
        $payerReference = $request->input('payerReference') ?? $request->input('reference') ?? $request->input('payerRef');
        $status         = strtolower($request->input('transactionStatus') ?? $request->input('status') ?? '');

        if (! $trxId || ! $amount || ! $payerReference) {
            Log::warning('bKash IPN missing required fields: trxID, amount, or payerReference.');
            return response()->json(['status' => 'error', 'message' => 'Missing required fields'], 400);
        }

        // ── Gate 3: IPN-reported status must be a success value ────────────────
        if (! in_array($status, ['completed', 'successful', 'success'], true)) {
            Log::info("bKash IPN transaction ignored. Status is '{$status}' (expected Completed).");
            return response()->json(['status' => 'ignored', 'message' => 'Non-success transaction status'], 200);
        }

        // ── Gate 4: trxId idempotency — reject already-processed transactions ──
        if (CollectionSummary::where('transaction_id', $trxId)->exists()) {
            Log::info("bKash IPN duplicate trxId rejected: {$trxId}");
            return response()->json(['status' => 'ignored', 'message' => 'Transaction already processed'], 200);
        }

        // ── Gate 5: Server-side Query Payment verification via bKash API ───────
        // Do NOT trust the IPN payload alone; confirm with bKash's own Query API.
        try {
            $verified = $this->verifyBkashTransaction($trxId, (float) $amount);
        } catch (\Exception $e) {
            Log::error('bKash IPN Query Payment API call failed: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Gateway verification failed'], 502);
        }

        if (! $verified) {
            Log::warning("bKash IPN rejected: Query Payment API did not confirm trxId={$trxId} amount={$amount}");
            return response()->json(['status' => 'error', 'message' => 'Payment verification failed'], 422);
        }

        // ── Gate 6: Look up the customer ───────────────────────────────────────
        // Use a constant-time response to avoid customer-existence oracle
        $customer = CustomersInfo::where('customer_unique_id', $payerReference)->first();

        if (! $customer) {
            Log::warning("bKash IPN customer not found for payerReference: {$payerReference}");
            // Return 200 to avoid leaking customer existence to an attacker
            return response()->json(['status' => 'ignored', 'message' => 'Reference not matched'], 200);
        }

        // ── Process payment ────────────────────────────────────────────────────
        try {
            $this->paymentService->processSuccessPayment($customer, (float) $amount, 'bkash', $trxId);
            return response()->json(['status' => 'success', 'message' => 'Payment processed successfully']);
        } catch (\Exception $e) {
            Log::error('bKash IPN processing failed: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Internal server error'], 500);
        }
    }

    /**
     * Verify a bKash transaction server-side by calling the Query Payment API.
     * Returns true only if the gateway confirms the payment as completed and
     * the gateway-returned amount matches the IPN-reported amount.
     */
    private function verifyBkashTransaction(string $trxId, float $ipnAmount): bool
    {
        $config  = $this->getBkashConfig();
        $apiType = siteUrlSettings('payment_bkash_api_type') ?: 'tokenized';

        // Obtain a short-lived token from bKash
        $tokenUrl = ($apiType === 'tokenized')
            ? $config['base_url'] . '/tokenized/checkout/token/grant'
            : $config['base_url'] . '/checkout/token/grant';

        $tokenResp = Http::withBasicAuth($config['username'], $config['password'])
            ->post($tokenUrl, [
                'app_key'    => $config['app_key'],
                'app_secret' => $config['app_secret'],
            ]);

        $tokenBody = $tokenResp->json();
        if (empty($tokenBody['id_token'])) {
            throw new \Exception('bKash token grant failed: ' . json_encode($tokenBody));
        }
        $idToken = $tokenBody['id_token'];

        // Query the payment by trxId
        $queryUrl = ($apiType === 'tokenized')
            ? $config['base_url'] . '/tokenized/checkout/payment/query/' . urlencode($trxId)
            : $config['base_url'] . '/checkout/payment/query/' . urlencode($trxId);

        $queryResp = Http::withToken($idToken)
            ->withHeaders(['X-App-Key' => $config['app_key']])
            ->get($queryUrl);

        $res = $queryResp->json();
        Log::info('bKash Query Payment response for trxId=' . $trxId . ': ' . json_encode($res));

        // Validate: statusCode 0000 = success, and amounts must match within 0.01 BDT
        $gatewayStatus = strtolower($res['transactionStatus'] ?? $res['statusMessage'] ?? '');
        $gatewayAmount = (float) ($res['amount'] ?? 0);
        $statusCode    = $res['statusCode'] ?? null;

        $statusOk = ($statusCode === '0000')
            || in_array($gatewayStatus, ['completed', 'successful', 'success'], true);
        $amountOk = abs($gatewayAmount - $ipnAmount) < 0.01;

        return $statusOk && $amountOk;
    }

    /**
     * Return the bKash API configuration from site settings / config.
     */
    private function getBkashConfig(): array
    {
        return [
            'base_url'   => siteUrlSettings('payment_bkash_base_url')   ?: config('services.bkash.base_url'),
            'username'   => siteUrlSettings('payment_bkash_username')    ?: config('services.bkash.username'),
            'password'   => siteUrlSettings('payment_bkash_password')    ?: config('services.bkash.password'),
            'app_key'    => siteUrlSettings('payment_bkash_app_key')     ?: config('services.bkash.app_key'),
            'app_secret' => siteUrlSettings('payment_bkash_app_secret')  ?: config('services.bkash.app_secret'),
        ];
    }

    /**
     * Handle MFS SMS Forwarder webhooks.
     */
    public function handleSmsReceiver(Request $request)
    {
        // Log the SMS payload (Sanctum auth handles token verification)
        Log::info('MFS SMS Forwarder Webhook received payload: ' . json_encode($request->all()));

        $sender = $request->input('sender') ?? $request->input('from') ?? $request->input('sender_name') ?? '';
        $message = $request->input('message') ?? $request->input('text') ?? $request->input('body') ?? $request->input('msg') ?? '';

        if (empty($message)) {
            return response()->json(['status' => 'ignored', 'message' => 'Empty message content'], 200);
        }

        // 2. Parse SMS parameters
        $parsed = $this->parseSms($sender, $message);
        $amount = $parsed['amount'];
        $trxId = $parsed['trxId'];
        $ref = $parsed['ref'];
        $senderMobile = $parsed['senderMobile'];
        $gateway = $parsed['gateway'];

        if (!$amount || !$trxId) {
            Log::warning("Could not extract amount or transaction ID from MFS SMS. Parsed Amount: '{$amount}', TrxID: '{$trxId}'");
            return response()->json(['status' => 'error', 'message' => 'Failed to parse SMS content'], 422);
        }

        $customer = null;

        // Step 1: Match by reference in SMS (customer unique ID)
        if ($ref) {
            $customer = CustomersInfo::where('customer_unique_id', $ref)->first();
        }

        // Step 2: Match by reference in SMS (PPP secret username)
        if (!$customer && $ref) {
            $customer = CustomersInfo::whereHas('pppUser', function ($query) use ($ref) {
                $query->where('username', $ref);
            })->first();
        }

        // Step 3: Match by sender's mobile number
        if (!$customer && $senderMobile) {
            // Clean up 88/+88 prefixes for matching
            $cleanMobile = $senderMobile;
            if (str_starts_with($cleanMobile, '88')) {
                $cleanMobile = substr($cleanMobile, 2);
            } elseif (str_starts_with($cleanMobile, '+88')) {
                $cleanMobile = substr($cleanMobile, 3);
            }

            $customer = CustomersInfo::where('mobile', $cleanMobile)
                ->orWhere('mobile', '88' . $cleanMobile)
                ->orWhere('mobile', '+88' . $cleanMobile)
                ->first();
        }

        if (!$customer) {
            Log::warning("No matching customer found for MFS SMS. Ref: '{$ref}', SenderMobile: '{$senderMobile}', TrxID: '{$trxId}'");
            return response()->json(['status' => 'error', 'message' => 'No matching customer found'], 404);
        }

        try {
            $this->paymentService->processSuccessPayment($customer, (float)$amount, $gateway, $trxId);
            return response()->json([
                'status' => 'success',
                'message' => 'Payment processed successfully',
                'customer' => $customer->customer_unique_id,
                'amount' => $amount,
                'gateway' => $gateway,
                'transaction_id' => $trxId
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to process MFS SMS payment: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Payment process exception: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Helper method to parse MFS notification SMS texts using regex patterns.
     */
    private function parseSms($sender, $message)
    {
        $senderLower = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $sender));
        $amount = null;
        $trxId = null;
        $ref = null;
        $senderMobile = null;
        $gateway = 'mfs';

        if (str_contains($senderLower, 'bkash')) {
            $gateway = 'bkash';
            // Parse Amount
            if (preg_match('/(?:Received BDT|Cash In BDT|Payment received BDT)\s*(\d+(?:\.\d+)?)/i', $message, $matches)) {
                $amount = (float)$matches[1];
            }
            // Parse Sender Mobile
            if (preg_match('/from\s*(\d{11})/i', $message, $matches)) {
                $senderMobile = $matches[1];
            }
            // Parse TrxID
            if (preg_match('/TrxID\s*([A-Z0-9]+)/i', $message, $matches)) {
                $trxId = $matches[1];
            }
            // Parse Ref
            if (preg_match('/Ref\s*([a-zA-Z0-9_-]+)/i', $message, $matches)) {
                $ref = $matches[1];
            }
        } elseif (str_contains($senderLower, 'nagad')) {
            $gateway = 'nagad';
            // Parse Amount
            if (preg_match('/(?:Received Amount:\s*Tk\s*|Received BDT\s*)(\d+(?:\.\d+)?)/i', $message, $matches)) {
                $amount = (float)$matches[1];
            }
            // Parse Sender Mobile
            if (preg_match('/From:\s*(\d{11})/i', $message, $matches)) {
                $senderMobile = $matches[1];
            }
            // Parse TxnID
            if (preg_match('/TxnID:\s*([A-Z0-9]+)/i', $message, $matches)) {
                $trxId = $matches[1];
            }
            // Parse Ref
            if (preg_match('/Ref:\s*([a-zA-Z0-9_-]+)/i', $message, $matches)) {
                $ref = $matches[1];
            }
        } elseif (str_contains($senderLower, 'rocket') || str_contains($senderLower, 'dbbl') || $senderLower === '16216') {
            $gateway = 'rocket';
            // Parse Amount
            if (preg_match('/Tk\.?\s*(\d+(?:\.\d+)?)/i', $message, $matches)) {
                $amount = (float)$matches[1];
            }
            // Parse Sender Mobile
            if (preg_match('/from\s*(\d{11,12})/i', $message, $matches)) {
                $senderMobile = $matches[1];
                if (strlen($senderMobile) === 12) {
                    $senderMobile = substr($senderMobile, 0, 11);
                }
            }
            // Parse TxnID
            if (preg_match('/(?:TxnID|TrxID):\s*([A-Z0-9]+)/i', $message, $matches)) {
                $trxId = $matches[1];
            }
            // Parse Ref
            if (preg_match('/Ref:\s*([a-zA-Z0-9_-]+)/i', $message, $matches)) {
                $ref = $matches[1];
            }
        } else {
            // General Fallback
            if (preg_match('/(?:BDT|Tk|TK)\.?\s*(\d+(?:\.\d+)?)/i', $message, $matches)) {
                $amount = (float)$matches[1];
            } elseif (preg_match('/(\d+(?:\.\d+)?)\s*(?:BDT|Tk|TK)/i', $message, $matches)) {
                $amount = (float)$matches[1];
            }

            if (preg_match('/(?:TrxID|TxnID|TxID)\s*:?\s*([A-Z0-9]+)/i', $message, $matches)) {
                $trxId = $matches[1];
            }

            if (preg_match('/from\s*(\d{11})/i', $message, $matches)) {
                $senderMobile = $matches[1];
            }

            if (preg_match('/Ref\s*:?\s*([a-zA-Z0-9_-]+)/i', $message, $matches)) {
                $ref = $matches[1];
            }
        }

        return [
            'amount' => $amount,
            'trxId' => $trxId,
            'ref' => $ref,
            'senderMobile' => $senderMobile,
            'gateway' => $gateway
        ];
    }
}
