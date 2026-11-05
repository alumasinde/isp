<?php

namespace App\Filament\Pages;

use App\Http\Controllers\MikrotikController;
use App\Models\MpesaPayment;
use App\Services\MpesaService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PayBill extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected string $view = 'filament.pages.pay-bill';

    protected static ?string $navigationLabel = 'Pay Bill';

    protected static ?string $title = 'Pay Bill';

    protected static ?int $navigationSort = 4;

    public $customer;

    public $billing;

    public $amount;

    public $phone;

    public ?string $connectionStatus = 'checking...';

    public ?int $pendingPaymentId = null;

    public ?string $pendingMessage = null;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->guard('ppp')->check();
    }

    public function mount()
    {
        $user = Auth::user();
        if (! $user) {
            abort(403);
        }

        $this->customer = $user->customer;
        if (! $this->customer) {
            session()->flash('error', 'Customer account details not found.');

            return;
        }

        $this->billing = $this->customer->billing;
        if (! $this->billing) {
            session()->flash('error', 'Billing account details not found.');

            return;
        }

        // Pay the outstanding balance by default, otherwise one month's rent.
        $due = (float) $this->billing->due_amount;
        $this->amount = $due > 0 ? $due : (float) $this->billing->monthly_rent;

        $this->phone = $this->customer->mobile;

        $this->connectionStatus = 'checking...';
    }

    public function checkLiveStatus()
    {
        if (! $this->customer || ! $this->customer->pppUser) {
            $this->connectionStatus = 'offline';

            return;
        }

        $pppUser = $this->customer->pppUser;
        $routerName = $pppUser->router_name;
        $username = $pppUser->username;

        if (! $routerName || ! $username) {
            $this->connectionStatus = 'offline';

            return;
        }

        try {
            $ctrl = app(MikrotikController::class);

            // Query MikroTik active sessions for this specific user, bypassing the cache
            $activeSessions = $ctrl->singleRead(
                $routerName,
                '/ppp/active/print',
                'ppp active print without-paging terse where name='.$ctrl->mtQuote($username),
                ['name' => $username],
                false,
                true
            );

            if (is_array($activeSessions) && count($activeSessions) > 0 && is_array($activeSessions[0])) {
                $this->connectionStatus = 'online';
            } else {
                $this->connectionStatus = 'offline';
            }
        } catch (\Exception $e) {
            Log::warning("Failed to check live status for PPP User {$username} on router {$routerName}: ".$e->getMessage());
            $this->connectionStatus = 'unknown';
        }
    }

    public function pay()
    {
        $this->resetErrorBag();

        $max = (int) config('payment.drivers.mpesa.max_amount', 250000);

        $this->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:'.$max],
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $mpesa = app(MpesaService::class);

        if (! $mpesa->isConfigured()) {
            $this->addError('amount', 'M-Pesa is not configured yet. Please contact support.');

            return;
        }

        $msisdn = $mpesa->normalizeMsisdn($this->phone);

        if (! $msisdn) {
            $this->addError('phone', 'Enter a valid Safaricom number, for example 0712 345 678.');

            return;
        }

        try {
            $amount = $mpesa->payableAmount((float) $this->amount);

            $payment = MpesaPayment::create([
                'customer_unique_id' => $this->customer->customer_unique_id,
                'purpose' => 'bill',
                'phone' => $msisdn,
                'amount' => $amount,
                'currency' => config('payment.currency', 'KES'),
                'account_reference' => $mpesa->accountReference($this->customer),
                'status' => MpesaPayment::STATUS_PENDING,
            ]);

            $response = $mpesa->stkPush(
                $msisdn,
                $amount,
                $mpesa->accountReference($this->customer),
                'Internet'
            );

            $payment->update([
                'merchant_request_id' => $response['MerchantRequestID'] ?? null,
                'checkout_request_id' => $response['CheckoutRequestID'] ?? null,
                'raw_response' => $response,
            ]);

            $this->pendingPaymentId = $payment->id;
            $this->pendingMessage = 'An M-Pesa prompt was sent to '.$msisdn.'. Enter your M-Pesa PIN to complete the payment.';
            $this->amount = $amount;
        } catch (\Throwable $e) {
            Log::error('M-Pesa STK push failed: '.$e->getMessage());
            $this->addError('amount', $e->getMessage());
        }
    }

    /**
     * Polled by the portal while the customer approves the STK prompt.
     */
    public function pollStatus()
    {
        if (! $this->pendingPaymentId || ! $this->customer) {
            return;
        }

        $payment = MpesaPayment::whereKey($this->pendingPaymentId)
            ->where('customer_unique_id', $this->customer->customer_unique_id)
            ->first();

        if (! $payment) {
            $this->pendingPaymentId = null;

            return;
        }

        // Safaricom gives up after ~60 seconds; do not leave the portal spinning.
        $expiry = (int) config('payment.drivers.mpesa.timeout', 60) + 30;
        if ($payment->isPending() && $payment->created_at?->lt(now()->subSeconds($expiry))) {
            $payment->update([
                'status' => MpesaPayment::STATUS_FAILED,
                'result_desc' => 'No M-Pesa confirmation was received before the request expired.',
            ]);
        }

        if ($payment->isCompleted()) {
            session()->flash('success', 'Payment received. M-Pesa receipt: '.$payment->mpesa_receipt.'. Your account has been updated.');

            $this->pendingPaymentId = null;
            $this->pendingMessage = null;

            $this->customer = $this->customer->fresh();
            $this->billing = $this->customer?->billing;
            $this->amount = (float) ($this->billing?->due_amount ?? 0) ?: (float) ($this->billing?->monthly_rent ?? 0);

            return;
        }

        if (in_array($payment->status, [MpesaPayment::STATUS_FAILED, MpesaPayment::STATUS_CANCELLED], true)) {
            session()->flash('error', app(MpesaService::class)->failureMessage($payment->result_code, $payment->result_desc));

            $this->pendingPaymentId = null;
            $this->pendingMessage = null;
        }
    }

    public function cancelPending()
    {
        $this->pendingPaymentId = null;
        $this->pendingMessage = null;
    }
}
