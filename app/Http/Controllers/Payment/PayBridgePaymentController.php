<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\CustomersInfo;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PayBridge\Payment\Facades\PayBridge;

class PayBridgePaymentController extends Controller
{
    protected PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Handle Success redirect from Gateway (as expected by PayBridge drivers)
     */
    public function success(Request $request, string $gateway)
    {
        return $this->processVerification($request, $gateway);
    }

    /**
     * Handle Failure redirect from Gateway
     */
    public function fail(Request $request, string $gateway)
    {
        $msg = $request->input('message', $request->input('error', 'Payment was cancelled or failed.'));
        return redirect()->route('filament.portal.pages.pay-bill')
            ->with('error', $msg);
    }

    /**
     * Handle Cancel redirect from Gateway
     */
    public function cancel(Request $request, string $gateway)
    {
        return redirect()->route('filament.portal.pages.pay-bill')
            ->with('error', 'Payment was cancelled.');
    }

    /**
     * Generic callback handler for gateways configured with /payment/{gateway}/callback
     */
    public function callback(Request $request, string $gateway)
    {
        $status = $request->input('status', $request->query('status', ''));
        if (in_array(strtolower($status), ['cancel', 'cancelled', 'canceled'])) {
            return $this->cancel($request, $gateway);
        }

        if (in_array(strtolower($status), ['fail', 'failed', 'error'])) {
            return $this->fail($request, $gateway);
        }

        return $this->processVerification($request, $gateway);
    }

    /**
     * Server-to-server IPN / Webhooks
     */
    public function webhook(Request $request, string $gateway)
    {
        try {
            $response = PayBridge::driver($gateway)->webhook($request->all());

            if (! empty($response['success'])) {
                $trxId = $response['transaction_id'] ?? $request->input('tran_id') ?? ('PB_' . uniqid());
                $amount = (float) ($response['amount'] ?? 0);

                // Find customer by payer reference or transaction ID pattern
                $customer = null;
                if ($request->filled('payerReference')) {
                    $customer = CustomersInfo::where('customer_unique_id', $request->input('payerReference'))->first();
                }

                if ($customer && $amount > 0) {
                    if (\App\Models\CollectionSummary::where('transaction_id', $trxId)->exists()) {
                        activity('payment')
                            ->event('duplicate_payment')
                            ->performedOn($customer)
                            ->causedBy($customer?->user)
                            ->withProperties([
                                'attributes' => [
                                    'customer_unique_id' => $customer->customer_unique_id,
                                    'customer_name'      => $customer->customer_name,
                                    'mobile'             => $customer->mobile,
                                    'gateway'            => $gateway,
                                    'transaction_id'     => $trxId,
                                    'amount'             => number_format($amount, 2),
                                    'ip_address'         => $request->ip(),
                                    'status'             => 'duplicate_blocked',
                                ]
                            ])
                            ->log("PayBridge webhook duplicate transaction [{$trxId}] ignored for customer {$customer->customer_name} (ID: {$customer->customer_unique_id}).");

                        return response()->json(['success' => true, 'message' => 'Already processed'], 200);
                    }

                    $this->paymentService->processSuccessPayment($customer, $amount, $gateway, $trxId);

                    activity('payment')
                        ->event('payment_success')
                        ->performedOn($customer)
                        ->causedBy($customer?->user)
                        ->withProperties([
                            'attributes' => [
                                'customer_unique_id' => $customer->customer_unique_id,
                                'customer_name'      => $customer->customer_name,
                                'mobile'             => $customer->mobile,
                                'gateway'            => $gateway,
                                'amount'             => number_format($amount, 2),
                                'transaction_id'     => $trxId,
                                'ip_address'         => $request->ip(),
                            ]
                        ])
                        ->log("Payment of BDT {$amount} received successfully via {$gateway} webhook for customer {$customer->customer_name} (ID: {$customer->customer_unique_id}). TrxID: {$trxId}");
                }

                return response()->json(['success' => true, 'message' => 'Processed successfully'], 200);
            }

            Log::warning("PayBridge invalid webhook signature for {$gateway}", $request->all());
            return response()->json(['success' => false, 'message' => 'Invalid Webhook Signature'], 400);

        } catch (\Throwable $e) {
            Log::error("PayBridge webhook error for {$gateway}: " . $e->getMessage());
            return response()->json(['error' => 'Server Error'], 500);
        }
    }

    /**
     * Core verification and billing activation using PayBridge driver verify()
     */
    protected function processVerification(Request $request, string $gateway)
    {
        Log::info("PayBridge verifying payment for [{$gateway}]", [
            'query' => $request->query(),
            'body'  => $request->except(['password', 'app_secret', 'secret']),
        ]);

        try {
            // Strictly passes payload to PayBridge driver for cryptographic signature verification
            $verifyData = array_merge($request->query(), $request->all());
            $response = PayBridge::driver($gateway)->verify($verifyData);

            if (! empty($response['success'])) {
                $customerId = session('paybridge_customer_id');
                $amount = (float) ($response['amount'] ?? session('paybridge_amount', 0));
                $trxId = $response['transaction_id'] 
                    ?? $request->input('trxID') 
                    ?? $request->input('tran_id') 
                    ?? $request->input('val_id') 
                    ?? ('PB_' . uniqid());

                $customer = null;
                if ($customerId) {
                    $customer = CustomersInfo::find($customerId);
                }

                // Fallback resolution from session customer unique id
                if (! $customer && session('paybridge_customer_unique_id')) {
                    $customer = CustomersInfo::where('customer_unique_id', session('paybridge_customer_unique_id'))->first();
                }

                // Fallback resolution from request payerReference
                if (! $customer && $request->filled('payerReference')) {
                    $customer = CustomersInfo::where('customer_unique_id', $request->input('payerReference'))->first();
                }

                if ($customer && $amount > 0) {
                    if (\App\Models\CollectionSummary::where('transaction_id', $trxId)->exists()) {
                        activity('payment')
                            ->event('duplicate_payment')
                            ->performedOn($customer)
                            ->causedBy(auth()->user() ?: $customer?->user)
                            ->withProperties([
                                'attributes' => [
                                    'customer_unique_id' => $customer->customer_unique_id,
                                    'customer_name'      => $customer->customer_name,
                                    'mobile'             => $customer->mobile,
                                    'gateway'            => $gateway,
                                    'transaction_id'     => $trxId,
                                    'amount'             => number_format($amount, 2),
                                    'ip_address'         => $request->ip(),
                                    'status'             => 'duplicate_blocked',
                                ]
                            ])
                            ->log("Duplicate payment replay attempt blocked for customer {$customer->customer_name} (ID: {$customer->customer_unique_id}) on {$gateway}. TrxID: {$trxId}");

                        return redirect()->route('filament.portal.pages.dashboard')
                            ->with('info', 'This payment has already been processed.');
                    }

                    $this->paymentService->processSuccessPayment($customer, $amount, $gateway, $trxId);

                    // Clear payment session keys
                    session()->forget([
                        'paybridge_customer_id',
                        'paybridge_customer_unique_id',
                        'paybridge_amount',
                        'paybridge_trx',
                        'paybridge_gateway',
                    ]);

                    activity('payment')
                        ->event('payment_success')
                        ->performedOn($customer)
                        ->causedBy(auth()->user() ?: $customer?->user)
                        ->withProperties([
                            'attributes' => [
                                'customer_unique_id' => $customer->customer_unique_id,
                                'customer_name'      => $customer->customer_name,
                                'mobile'             => $customer->mobile,
                                'gateway'            => $gateway,
                                'amount'             => number_format($amount, 2),
                                'transaction_id'     => $trxId,
                                'ip_address'         => $request->ip(),
                            ]
                        ])
                        ->log("Payment of BDT {$amount} received successfully via " . ucfirst(str_replace('_', ' ', $gateway)) . " for {$customer->customer_name} (ID: {$customer->customer_unique_id}). TrxID: {$trxId}");

                    return redirect()->route('filament.portal.pages.dashboard')
                        ->with('success', "Payment of BDT {$amount} received successfully via " . ucfirst(str_replace('_', ' ', $gateway)) . ". Your account is active.");
                }

                if (! $customer) {
                    Log::error("PayBridge: Customer not found for transaction [{$trxId}] on gateway [{$gateway}]");
                    return redirect()->route('filament.portal.pages.pay-bill')
                        ->with('error', 'Payment received, but unable to locate customer profile. Please contact support with Ref: ' . $trxId);
                }

                return redirect()->route('filament.portal.pages.pay-bill')
                    ->with('error', 'Invalid payment amount received.');
            }

            Log::error("PayBridge verification failed for [{$gateway}]: " . ($response['message'] ?? 'Unknown error'));
            return redirect()->route('filament.portal.pages.pay-bill')
                ->with('error', 'Payment verification failed: ' . ($response['message'] ?? 'Unable to verify payment.'));

        } catch (\Throwable $e) {
            Log::error("PayBridge verification exception for [{$gateway}]: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('filament.portal.pages.pay-bill')
                ->with('error', 'Payment processing error: ' . $e->getMessage());
        }
    }
}
