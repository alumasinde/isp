<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payments (Kenya)
    |--------------------------------------------------------------------------
    |
    | The platform bills in Kenyan Shillings and collects through Safaricom
    | M-Pesa (Daraja API): STK Push for portal/customer initiated payments and
    | C2B Paybill/Till confirmations for customer-entered payments.
    |
    */

    'default' => env('PAYMENT_GATEWAY', 'mpesa'),

    'currency' => env('PAYMENT_CURRENCY', 'KES'),

    'currency_symbol' => env('PAYMENT_CURRENCY_SYMBOL', 'KSh'),

    'country' => env('PAYMENT_COUNTRY', 'KE'),

    'timezone' => env('APP_TIMEZONE', 'Africa/Nairobi'),

    /* Kenya VAT rate (%), used as the default when generating bills. */
    'vat_rate' => (float) env('PAYMENT_VAT_RATE', 16),

    'drivers' => [

        'mpesa' => [
            'enabled' => filter_var(env('MPESA_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

            /* "sandbox" or "production" */
            'environment' => env('MPESA_ENVIRONMENT', 'sandbox'),

            'base_url' => env(
                'MPESA_BASE_URL',
                env('MPESA_ENVIRONMENT', 'sandbox') === 'production'
                    ? 'https://api.safaricom.co.ke'
                    : 'https://sandbox.safaricom.co.ke'
            ),

            /* Daraja app credentials (Lipa na M-Pesa Online) */
            'consumer_key' => env('MPESA_CONSUMER_KEY'),
            'consumer_secret' => env('MPESA_CONSUMER_SECRET'),

            /* Paybill or Till (Buy Goods) shortcode that receives the money */
            'shortcode' => env('MPESA_SHORTCODE', '174379'),
            'passkey' => env('MPESA_PASSKEY'),

            /* CustomerPayBillOnline (Paybill) or CustomerBuyGoodsOnline (Till) */
            'transaction_type' => env('MPESA_TRANSACTION_TYPE', 'CustomerPayBillOnline'),

            /* Public HTTPS endpoints registered with Safaricom */
            'callback_url' => env('MPESA_CALLBACK_URL'),
            'c2b_validation_url' => env('MPESA_C2B_VALIDATION_URL'),
            'c2b_confirmation_url' => env('MPESA_C2B_CONFIRMATION_URL'),
            'c2b_response_type' => env('MPESA_C2B_RESPONSE_TYPE', 'Completed'),

            /* STK requests expire after ~60 seconds at Safaricom */
            'timeout' => (int) env('MPESA_TIMEOUT', 60),

            /* Daraja caps a single STK request at KES 250,000 */
            'max_amount' => (int) env('MPESA_MAX_AMOUNT', 250000),

            /*
             * Extra safety: after a successful callback, confirm the result with
             * the Transaction Status / STK Query endpoint before crediting the
             * customer. M-Pesa callbacks are not signed.
             */
            'verify_with_query' => filter_var(env('MPESA_VERIFY_WITH_QUERY', true), FILTER_VALIDATE_BOOLEAN),
        ],

    ],

];
