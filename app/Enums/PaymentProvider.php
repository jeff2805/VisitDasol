<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * The gateways a guest may pay through. Stored in payments.provider.
 *
 * GCash is not a case of its own: it is one of the methods PayMongo offers,
 * alongside Maya, GrabPay, and cards. Choosing PayMongo and choosing GCash
 * on PayMongo's page are the same payment as far as this system is concerned,
 * and the method that was actually used comes back in payments.method.
 */
enum PaymentProvider: string
{
    use EnumHelpers;

    case PayMongo = 'paymongo';
    case PayPal = 'paypal';
    case ManualGcash = 'manual_gcash';

    public function label(): string
    {
        return match ($this) {
            self::PayMongo => 'Pay with '.self::payMongoMethodPhrase().' through PayMongo',
            self::PayPal => 'Pay with your PayPal account',
            self::ManualGcash => 'GCash (sent to the resort)',
        };
    }

    /**
     * The configured PayMongo methods named the way a guest would say them:
     * "QRPh" on its own, "QRPh or card" once cards are switched on.
     *
     * The one place any screen gets this wording from. Hard-coding it meant
     * a form promising QRPh while PayMongo's page offered a card as well,
     * and the two drifting apart is exactly what this prevents.
     */
    public static function payMongoMethodPhrase(): string
    {
        return Arr::join(self::payMongoMethods(), ', ', ' or ') ?: 'online payment';
    }

    /** Short name for a button: the headline method, not the whole list. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::PayMongo => self::payMongoMethods()[0] ?? 'Online payment',
            self::PayPal => 'PayPal',
            self::ManualGcash => 'GCash',
        };
    }

    /**
     * The wallets and cards PayMongo will actually offer, named the way a
     * guest would recognise them.
     *
     * Read from the configured method list rather than written out here, so a
     * button can never promise a wallet that PayMongo's checkout page will not
     * show. The site is set to GCash alone; see config/services.php.
     *
     * @return list<string>
     */
    private static function payMongoMethods(): array
    {
        $names = [
            'gcash' => 'GCash',
            /*
             * "card", not "credit or debit card". Spelling it out reads
             * well on its own and badly in a list — "GCash or credit or
             * debit card" — and this phrase has to serve both. The radio
             * label upper-cases the first letter, so a card-only site
             * shows "Card", which is what every other checkout calls it.
             */
            'card' => 'card',
            'paymaya' => 'Maya',
            'grab_pay' => 'GrabPay',
            'qrph' => 'QRPh',
        ];

        return array_values(array_map(
            fn (string $method): string => $names[$method] ?? Str::headline($method),
            (array) config('services.paymongo.methods', [])
        ));
    }

    public function icon(): string
    {
        return match ($this) {
            self::PayMongo => 'phone',
            self::PayPal => 'paypal',
            self::ManualGcash => 'cash-coin',
        };
    }

    /** True when the keys for this gateway are configured. */
    public function isConfigured(): bool
    {
        return match ($this) {
            self::PayMongo => filled(config('services.paymongo.secret')),
            self::PayPal => filled(config('services.paypal.client_id'))
                && filled(config('services.paypal.secret')),
            self::ManualGcash => true,
        };
    }

    /**
     * The gateways that are ready to take a payment right now.
     *
     * @return list<self>
     */
    public static function available(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $p): bool => $p !== self::ManualGcash && $p->isConfigured()
        ));
    }
}
