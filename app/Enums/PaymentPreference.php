<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use App\Models\ResortManualPaymentAccount;

enum PaymentPreference: string
{
    use EnumHelpers;

    case Online = 'online';
    case OnArrival = 'on_arrival';
    case ManualGcash = 'manual_gcash';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'QRPh',
            self::OnArrival => 'Pay at the resort',
            self::ManualGcash => 'Manual GCash',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Online =>
                'Pay through PayMongo QRPh once the resort has confirmed.',
            self::OnArrival =>
                'Settle in person, in cash, on the day you arrive.',
            self::ManualGcash =>
                'Send payment to the resort owner through GCash after confirmation.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Online => 'phone',
            self::OnArrival => 'cash-coin',
            self::ManualGcash => 'qr-code',
        };
    }

    /**
     * Manual GCash is available only when this resort
     * has an active GCash account configured.
     */
    public function isAvailable(
        ?ResortManualPaymentAccount $manualAccount = null
    ): bool {
        return match ($this) {
            self::Online => PaymentProvider::PayMongo->isConfigured(),
            self::OnArrival => true,
            self::ManualGcash => (bool) (
                $manualAccount && $manualAccount->active
            ),
        };
    }

    /**
     * @return list<self>
     */
    public static function available(
        ?ResortManualPaymentAccount $manualAccount = null
    ): array {
        return array_values(array_filter(
            self::cases(),
            fn (self $preference) =>
                $preference->isAvailable($manualAccount)
        ));
    }
}