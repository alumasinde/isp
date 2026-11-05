<?php

namespace App\Filament\Pages;

use App\Http\Controllers\MikrotikController;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

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

    public $paymentMethod = 'bkash';

    public ?string $connectionStatus = 'checking...';

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

        // Set amount to pay, defaulting to outstanding due amount if any, otherwise monthly rent
        $due = (float) $this->billing->due_amount;
        $this->amount = $due > 0 ? $due : (float) $this->billing->monthly_rent;

        // Set default payment method based on what's active in PayBridge or siteUrlSettings
        $pbDefault = \PayBridge\Payment\Models\PaymentGatewaySetting::where('is_default', true)->where('is_active', true)->value('code');
        $pbFirst = \PayBridge\Payment\Models\PaymentGatewaySetting::active()->value('code');

        if ($pbDefault) {
            $this->paymentMethod = $pbDefault;
        } elseif ($pbFirst) {
            $this->paymentMethod = $pbFirst;
        } elseif (siteUrlSettings('payment_bkash_enabled')) {
            $this->paymentMethod = 'bkash';
        } elseif (siteUrlSettings('payment_nagad_enabled')) {
            $this->paymentMethod = 'nagad';
        } elseif (siteUrlSettings('payment_sslcommerz_enabled')) {
            $this->paymentMethod = 'sslcommerz';
        }

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
                true // bypassCache
            );

            if (is_array($activeSessions) && count($activeSessions) > 0 && is_array($activeSessions[0])) {
                $this->connectionStatus = 'online';
            } else {
                $this->connectionStatus = 'offline';
            }
        } catch (\Exception $e) {
            \Log::warning("Failed to check live status for PPP User {$username} on router {$routerName}: ".$e->getMessage());
            $this->connectionStatus = 'unknown';
        }
    }

    public function pay()
    {
        $this->validate([
            'amount' => 'required|numeric|min:1',
            'paymentMethod' => 'required|string',
        ]);

        // Check if chosen method is managed by PayBridge
        $pbGateway = \PayBridge\Payment\Models\PaymentGatewaySetting::where('code', $this->paymentMethod)
            ->where('is_active', true)
            ->first();

        if ($pbGateway) {
            $trxId = 'PB_' . $this->customer->id . '_' . time();
            $callbackUrl = route('payment.paybridge.callback', ['gateway' => $this->paymentMethod]);

            session([
                'paybridge_customer_id' => $this->customer->id,
                'paybridge_customer_unique_id' => $this->customer->customer_unique_id,
                'paybridge_amount' => (float)$this->amount,
                'paybridge_trx' => $trxId,
                'paybridge_gateway' => $this->paymentMethod,
            ]);

            $payload = [
                'amount'         => (float) $this->amount,
                'transaction_id' => $trxId,
                'currency'       => 'BDT',
                'payer_reference'=> $this->customer->customer_unique_id,
                'customer_name'  => $this->customer->customer_name ?: 'Customer',
                'customer_email' => $this->customer->email ?: 'customer@isp.local',
                'customer_phone' => $this->customer->mobile ?: '01700000000',
                'callback_url'   => $callbackUrl,
                'success_url'    => $callbackUrl,
                'fail_url'       => $callbackUrl,
                'cancel_url'     => $callbackUrl,
            ];

            try {
                $response = \PayBridge\Payment\Facades\PayBridge::driver($this->paymentMethod)->pay($payload);

                if (! empty($response['success']) && ! empty($response['redirect_url'])) {
                    return redirect()->away($response['redirect_url']);
                }

                // Bangla QR: show QR code inline instead of redirect
                if (! empty($response['success']) && $this->paymentMethod === 'bangla_qr' && ! empty($response['qr_image_url'])) {
                    session([
                        'bangla_qr_image'  => $response['qr_image_url'],
                        'bangla_qr_trx'    => $response['transaction_id'] ?? $trxId,
                        'bangla_qr_amount' => (float) $this->amount,
                    ]);
                    return redirect()->route('filament.portal.pages.pay-bill')
                        ->with('bangla_qr', true);
                }

                $errorMsg = $response['message'] ?? 'Unable to initiate payment with ' . $pbGateway->name;
                session()->flash('error', $errorMsg);
                return;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("PayBridge initiation failed for [{$this->paymentMethod}]: " . $e->getMessage());
                session()->flash('error', 'Payment gateway error: ' . $e->getMessage());
                return;
            }
        }

        // Fallback to legacy routes if legacy code is used
        $routeParams = ['amount' => $this->amount];
        $host = request()->getHost();
        $isLocal = str_ends_with($host, '.test') || str_ends_with($host, '.local') || $host === 'localhost' || $host === '127.0.0.1';

        if ($isLocal) {
            $routeParams['mock'] = 'true';
        }

        if ($this->paymentMethod === 'bkash') {
            return redirect()->route('payment.bkash.initiate', $routeParams);
        } elseif ($this->paymentMethod === 'nagad') {
            return redirect()->route('payment.nagad.initiate', $routeParams);
        } elseif ($this->paymentMethod === 'sslcommerz') {
            return redirect()->route('payment.sslcommerz.initiate', $routeParams);
        }

        session()->flash('error', 'Selected payment gateway is currently unavailable.');
    }
}
