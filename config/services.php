<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'mapbox' => [
        'token' => env('MAPBOX_PUBLIC_TOKEN'),
    ],
    /*
    |--------------------------------------------------------------------------
    | Online payment
    |--------------------------------------------------------------------------
    |
    | Both gateways take the payment on their own pages, so no card number or
    | wallet detail ever reaches this application. Leave a gateway's keys empty
    | and it simply is not offered; with neither set, the booking screens fall
    | back to "arrange payment with the resort" exactly as before.
    |
    | Keep the secret keys out of version control. .env is already gitignored.
    */

    /*
    |--------------------------------------------------------------------------
    | Signing in with Google or Facebook
    |--------------------------------------------------------------------------
    |
    | Leave the keys empty and nothing changes: the buttons do not appear on
    | the sign-in page and the addresses behind them answer 404. Fill them in
    | and the buttons appear on their own.
    |
    | Google:   console.cloud.google.com -> APIs & Services -> Credentials ->
    |           OAuth client ID, type "Web application".
    | Facebook: developers.facebook.com -> My Apps -> Facebook Login.
    |
    | Both need the redirect address below registered with them EXACTLY, or
    | they refuse the sign-in. It must be the address visitors actually use,
    | so a site served over https needs the https form registered.
    |
    | Only tourist accounts can be created this way. See App\Services\SocialLogin.
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/auth/google/callback'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI', env('APP_URL').'/auth/facebook/callback'),
    ],

    /*
     * PayMongo is how a guest pays online. Keys come from the PayMongo
     * dashboard: Developers -> API Keys. A test key begins sk_test_ and moves
     * no real money.
     *
     * One key, so one account: every online payment made anywhere on this site
     * lands in whichever PayMongo account this key belongs to, NOT in the
     * individual resort's. Whoever holds that account therefore owes each
     * resort its takings, and how that is settled is an office decision, not a
     * setting. Until that is agreed, leave PAYMONGO_SECRET_KEY unset: with no
     * key the booking form quietly drops the GCash option and every resort
     * collects at the gate exactly as it does today.
     */
    'paymongo' => [
        'secret' => env('PAYMONGO_SECRET_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        /*
         * Which methods PayMongo offers on its checkout page.
         *
         * QRPh alone, because that is what the site advertises and what
         * visitors here actually use. PayMongo can also do GCash, cards, Maya
         * and GrabPay; adding any of them is a matter of listing it here AND
         * switching it on for the PayMongo account, in either order, but the
         * booking form's wording says QRPh and the two should agree.
         *
         * Lower-cased: PayMongo's API reads these as its own method codes
         * (e.g. "qrph", not "QRPh"), and a wrong case fails the whole
         * checkout rather than just the label shown for it.
         */
        'methods' => array_values(array_filter(array_map(
            fn (string $method): string => strtolower(trim($method)),
            explode(',', (string) env('PAYMONGO_METHODS', 'qrph'))
        ))),
    ],

    // PayPal, mainly for guests paying from outside the Philippines.
    // Keys come from developer.paypal.com -> Apps & Credentials. Keep mode on
    // "sandbox" until the account is ready to take real money.
    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'secret' => env('PAYPAL_SECRET'),
        'mode' => env('PAYPAL_MODE', 'sandbox'),
        // From the webhook you create in the PayPal dashboard. Without it a
        // PayPal webhook cannot be proved genuine, so none are accepted.
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
    ],

    /*
    | How long a signed webhook stays acceptable, in seconds. A captured
    | request replayed after this window is refused.
    */
    'webhooks' => [
        'tolerance' => (int) env('WEBHOOK_TOLERANCE_SECONDS', 300),
    ],

];
