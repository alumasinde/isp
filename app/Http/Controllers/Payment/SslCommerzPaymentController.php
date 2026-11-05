<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\CollectionSummary;
use App\Models\CustomersInfo;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SslCommerzPaymentController extends Controller
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    private function getSslConfig()
    {
        $sandbox = siteUrlSettings('payment_sslcommerz_sandbox') ?? true;
        $baseUrl = $sandbox
            ? 'https://sandbox.sslcommerz.com'
            : 'https://header.sslcommerz.com';

        return [
            'base_url' => $baseUrl,
            'store_id' => siteUrlSettings('payment_sslcommerz_store_id'),
            'store_passwd' => siteUrlSettings('payment_sslcommerz_store_password'),
            'sandbox' => $sandbox,
        ];
    }

    public function initiate(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
        ]);

        $amount = $request->amount;
        $user = auth()->user();

        if (! $user || ! $user->customer) {
            return redirect()->back()->with('error', 'Unauthorized customer access.');
        }

        $customer = $user->customer;

        // Auto-detect local development to offer sandbox mock helper
        $host = request()->getHost();
        $isLocal = str_ends_with($host, '.test') || str_ends_with($host, '.local') || $host === 'localhost' || $host === '127.0.0.1';

        if ($isLocal && $request->has('mock')) {
            return response()->view('payment.mock_checkout', [
                'gateway' => 'SSLCommerz',
                'customer' => $customer,
                'amount' => $amount,
                'reason' => null,
            ]);
        }

        try {
            $config = $this->getSslConfig();

            if (empty($config['store_id']) || empty($config['store_passwd'])) {
                if ($isLocal) {
                    return response()->view('payment.mock_checkout', [
                        'gateway' => 'SSLCommerz',
                        'customer' => $customer,
                        'amount' => $amount,
                        'reason' => 'SSLCommerz store ID or password is not configured.',
                    ]);
                }

                return redirect()->route('filament.portal.pages.pay-bill')
                    ->with('error', 'SSLCommerz is not properly configured.');
            }

            $post_data = [
                'store_id' => $config['store_id'],
                'store_passwd' => $config['store_passwd'],
                'total_amount' => (string) round($amount, 2),
                'currency' => 'BDT',
                'tran_id' => 'SSL_'.uniqid(),
                'success_url' => route('payment.sslcommerz.callback'),
                'fail_url' => route('payment.sslcommerz.callback'),
                'cancel_url' => route('payment.sslcommerz.callback'),

                // Customer Information
                'cus_name' => $customer->customer_name ?: 'ISP Customer',
                'cus_email' => $customer->email ?: 'customer@example.com',
                'cus_add1' => $customer->address ?: 'Dhaka',
                'cus_city' => 'Dhaka',
                'cus_country' => 'Bangladesh',
                'cus_phone' => $customer->mobile ?: '01700000000',

                // Non-physical goods
                'shipping_method' => 'NO',
                'product_name' => 'Internet Bill',
                'product_category' => 'Internet',
                'product_profile' => 'non-physical-goods',
            ];

            $response = Http::asForm()->post($config['base_url'].'/gwprocess/v4/api.php', $post_data);
            $res = $response->json();

            if (isset($res['status']) && $res['status'] === 'SUCCESS' && isset($res['GatewayPageURL'])) {
                session(['ssl_amount' => $amount, 'ssl_customer_id' => $customer->id]);

                return redirect()->away($res['GatewayPageURL']);
            }

            Log::error('SSLCommerz Session Init Failed: '.json_encode($res));

            if ($isLocal) {
                return response()->view('payment.mock_checkout', [
                    'gateway' => 'SSLCommerz',
                    'customer' => $customer,
                    'amount' => $amount,
                    'reason' => 'SSLCommerz API error: '.($res['failedreason'] ?? 'Session creation failed.'),
                ]);
            }

            return redirect()->route('filament.portal.pages.pay-bill')
                ->with('error', 'SSLCommerz session creation failed: '.($res['failedreason'] ?? 'Unknown error'));

        } catch (\Exception $e) {
            Log::error('SSLCommerz initiate exception: '.$e->getMessage());

            if ($isLocal) {
                return response()->view('payment.mock_checkout', [
                    'gateway' => 'SSLCommerz',
                    'customer' => $customer,
                    'amount' => $amount,
                    'reason' => 'Connection exception: '.$e->getMessage(),
                ]);
            }

            return redirect()->route('filament.portal.pages.pay-bill')
                ->with('error', 'SSLCommerz service currently offline: '.$e->getMessage());
        }
    }

    public function callback(Request $request)
    {
        $status = $request->input('status');
        $tranId = $request->input('tran_id');
        $valId = $request->input('val_id');

        // Locate customer by session or auth
        $customer = auth()->user() ? auth()->user()->customer : null;
        if (! $customer && session()->has('ssl_customer_id')) {
            $customer = CustomersInfo::find(session('ssl_customer_id'));
        }

        if ($status === 'VALID' || $status === 'VALIDATED') {
            if (empty($valId)) {
                activity('payment')
                    ->event('invalid_callback')
                    ->performedOn($customer ?: new CustomersInfo())
                    ->causedBy(auth()->user())
                    ->withProperties([
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'customer_id' => $customer?->id,
                        'customer_unique_id' => $customer?->customer_unique_id,
                        'customer_name' => $customer?->customer_name,
                        'customer_mobile' => $customer?->mobile,
                        'status' => $status,
                        'tran_id' => $tranId,
                    ])
                    ->log("SSLCommerz callback received VALID status without val_id for customer " . ($customer?->customer_name ?? 'Unknown') . " (ID: " . ($customer?->customer_unique_id ?? 'N/A') . ")");

                return redirect()->route('filament.portal.pages.pay-bill')
                    ->with('error', 'Invalid payment validation data.');
            }

            try {
                $config = $this->getSslConfig();

                // Double check validity using SSLCommerz transaction validator API
                $validator = Http::get($config['base_url'].'/validator/api/valid.php', [
                    'val_id' => $valId,
                    'store_id' => $config['store_id'],
                    'store_passwd' => $config['store_passwd'],
                    'v' => 1,
                    'format' => 'json',
                ]);

                $res = $validator->json();

                if (isset($res['status']) && ($res['status'] === 'VALID' || $res['status'] === 'VALIDATED')) {
                    // MUST bind amount to gateway validator response, NEVER client-supplied request input
                    $validatedAmount = (float) ($res['amount'] ?? $res['currency_amount'] ?? 0);
                    if ($validatedAmount <= 0) {
                        activity('payment')
                            ->event('invalid_amount')
                            ->performedOn($customer ?: new CustomersInfo())
                            ->causedBy(auth()->user())
                            ->withProperties([
                                'ip' => $request->ip(),
                                'customer_id' => $customer?->id,
                                'customer_unique_id' => $customer?->customer_unique_id,
                                'customer_name' => $customer?->customer_name,
                                'gateway_response' => $res,
                            ])
                            ->log("SSLCommerz validator reported non-positive amount for customer " . ($customer?->customer_name ?? 'Unknown'));

                        return redirect()->route('filament.portal.pages.pay-bill')
                            ->with('error', 'Invalid payment amount reported by gateway.');
                    }

                    // Check for tampering between request input, expected session amount, and gateway validated amount
                    $rawRequestAmount = (float) $request->input('amount');
                    $expectedAmount = session()->has('ssl_amount') ? (float) session('ssl_amount') : null;

                    $isTampered = ($rawRequestAmount > 0 && abs($rawRequestAmount - $validatedAmount) > 0.01) ||
                                  ($expectedAmount !== null && abs($validatedAmount - $expectedAmount) > 0.01);

                    if ($isTampered) {
                        activity('payment')
                            ->event('amount_tampering')
                            ->performedOn($customer ?: new CustomersInfo())
                            ->causedBy(auth()->user() ?: $customer?->user)
                            ->withProperties([
                                'attributes' => [
                                    'customer_unique_id' => $customer?->customer_unique_id ?? 'N/A',
                                    'customer_name'      => $customer?->customer_name ?? 'Unknown',
                                    'mobile'             => $customer?->mobile ?? 'N/A',
                                    'gateway'            => 'sslcommerz',
                                    'paid_gateway_amount'=> number_format($validatedAmount, 2),
                                    'attempted_amount'   => number_format($rawRequestAmount, 2),
                                    'expected_amount'    => $expectedAmount !== null ? number_format($expectedAmount, 2) : 'N/A',
                                    'val_id'             => $valId,
                                    'tran_id'            => $tranId,
                                    'ip_address'         => $request->ip(),
                                ],
                                'old' => [
                                    'paid_gateway_amount'=> number_format($rawRequestAmount, 2),
                                ]
                            ])
                            ->log("Payment amount tampering detected! Customer " . ($customer?->customer_name ?? 'Unknown') . " (ID: " . ($customer?->customer_unique_id ?? 'N/A') . ", Mobile: " . ($customer?->mobile ?? 'N/A') . ") paid BDT {$validatedAmount}, but attempted callback with BDT {$rawRequestAmount} (Expected: BDT " . ($expectedAmount ?? 'N/A') . ").");

                        return redirect()->route('filament.portal.pages.pay-bill')
                            ->with('error', 'Payment amount mismatch detected.');
                    }

                    $trxID = ! empty($res['bank_trx_id']) ? $res['bank_trx_id'] : (! empty($res['tran_id']) ? $res['tran_id'] : $valId);

                    // Idempotency / Replay guard: Ensure transaction has not already been credited
                    if (CollectionSummary::where('transaction_id', $trxID)->orWhere('transaction_id', $valId)->exists()) {
                        activity('payment')
                            ->event('duplicate_payment')
                            ->performedOn($customer ?: new CustomersInfo())
                            ->causedBy(auth()->user() ?: $customer?->user)
                            ->withProperties([
                                'attributes' => [
                                    'customer_unique_id' => $customer?->customer_unique_id ?? 'N/A',
                                    'customer_name'      => $customer?->customer_name ?? 'Unknown',
                                    'mobile'             => $customer?->mobile ?? 'N/A',
                                    'gateway'            => 'sslcommerz',
                                    'transaction_id'     => $trxID,
                                    'val_id'             => $valId,
                                    'amount'             => number_format($validatedAmount, 2),
                                    'ip_address'         => $request->ip(),
                                    'status'             => 'duplicate_blocked',
                                ]
                            ])
                            ->log("Duplicate payment replay attempt blocked for customer " . ($customer?->customer_name ?? 'Unknown') . " (ID: " . ($customer?->customer_unique_id ?? 'N/A') . "). TrxID: {$trxID}, ValID: {$valId}");

                        return redirect()->route('filament.portal.pages.dashboard')
                            ->with('info', 'This payment has already been credited.');
                    }

                    if ($customer) {
                        $this->paymentService->processSuccessPayment($customer, $validatedAmount, 'sslcommerz', $trxID);

                        // Clear payment session keys
                        session()->forget(['ssl_amount', 'ssl_customer_id']);

                        activity('payment')
                            ->event('payment_success')
                            ->performedOn($customer)
                            ->causedBy(auth()->user() ?: $customer?->user)
                            ->withProperties([
                                'attributes' => [
                                    'customer_unique_id' => $customer->customer_unique_id,
                                    'customer_name'      => $customer->customer_name,
                                    'mobile'             => $customer->mobile,
                                    'gateway'            => 'sslcommerz',
                                    'amount'             => number_format($validatedAmount, 2),
                                    'transaction_id'     => $trxID,
                                    'val_id'             => $valId,
                                    'ip_address'         => $request->ip(),
                                ]
                            ])
                            ->log("Payment of BDT {$validatedAmount} received successfully via SSLCommerz for {$customer->customer_name} (ID: {$customer->customer_unique_id}). TrxID: {$trxID}");

                        return redirect()->route('filament.portal.pages.dashboard')
                            ->with('success', 'Payment of BDT '.$validatedAmount.' received successfully via SSLCommerz. Your account is active.');
                    }
                }

                activity('payment')
                    ->event('validation_failed')
                    ->performedOn($customer ?: new CustomersInfo())
                    ->causedBy(auth()->user())
                    ->withProperties([
                        'ip' => $request->ip(),
                        'customer_id' => $customer?->id,
                        'customer_unique_id' => $customer?->customer_unique_id,
                        'customer_name' => $customer?->customer_name,
                        'gateway_response' => $res,
                        'val_id' => $valId,
                    ])
                    ->log("SSLCommerz transaction validation failed for " . ($customer?->customer_name ?? 'Customer') . " (ID: " . ($customer?->customer_unique_id ?? 'N/A') . ").");

                return redirect()->route('filament.portal.pages.pay-bill')
                    ->with('error', 'Transaction validation failed.');

            } catch (\Exception $e) {
                activity('payment')
                    ->event('exception')
                    ->performedOn($customer ?: new CustomersInfo())
                    ->causedBy(auth()->user())
                    ->withProperties([
                        'ip' => $request->ip(),
                        'customer_id' => $customer?->id,
                        'error' => $e->getMessage(),
                    ])
                    ->log("SSLCommerz verification exception for " . ($customer?->customer_name ?? 'Customer') . ": " . $e->getMessage());

                return redirect()->route('filament.portal.pages.pay-bill')
                    ->with('error', 'Verification exception: '.$e->getMessage());
            }
        }

        return redirect()->route('filament.portal.pages.pay-bill')
            ->with('error', 'Payment failed or cancelled. Status: '.$status);
    }
}
