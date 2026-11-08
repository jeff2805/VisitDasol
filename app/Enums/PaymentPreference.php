<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use App\Models\ResortManualPaymentAccount;
use App\Services\Payments\PaymentTerms;

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
     * Whether this resort may be paid this way for this stay.
     *
     * TWO QUESTIONS, NOT ONE. Whether the METHOD exists at all — is
     * PayMongo configured, has the resort set up a GCash account — and
     * whether the resort's payment terms ALLOW it for this booking.
     * Manual GCash was once offered by resorts asking for no deposit
     * at all, sending the guest to collect a reference number and
     * upload a receipt for a payment nobody wanted yet.
     *
     * THIS IS THE ONE GATE. The booking form draws what it returns,
     * StoreBookingRequest accepts what it returns, and BookingService
     * refuses anything else against the real terms. A method withdrawn
     * here is withdrawn everywhere, which is why "Pay at the resort"
     * needed no change to a view, a route or a rule.
     *
     * $terms is optional, and with none the answers are about the site
     * rather than one stay: a report, a seeder, a screen listing what
     * exists. Every caller deciding for a real booking passes them.
     */
    public function isAvailable(
        ?ResortManualPaymentAccount $manualAccount = null,
        ?PaymentTerms $terms = null,
    ): bool {
        return match ($this) {
            /*
             * QRPh is dormant: there is no key in this deployment, so
             * isConfigured() is false and the gateway is never offered.
             * The two methods the portal actually supports are the two
             * below, each on its owner's own switch.
             *
             * The amount is not asked here. A gateway that cannot take
             * a payment of nothing simply is not reached: the
             * reservation page opens a payment only where something is
             * outstanding.
             */
            self::Online => PaymentProvider::PayMongo->isConfigured(),

            /*
             * PAY AT THE RESORT. THE OWNER'S SWITCH DECIDES, AND
             * NOTHING ELSE DOES.
             *
             * It used to need a second condition: that the terms asked
             * for nothing before arrival. The reasoning was that a
             * required deposit cannot be settled on arrival — true of
             * the deposit, but not a reason to withdraw the method.
             *
             * What it did in practice was hide the switch's effect from
             * the owner who had just set it. A resort on "No advance
             * payment" with a last-minute rule asking for a deposit
             * offered Pay at the resort for a stay six weeks out and
             * silently withheld it for one tomorrow, with nothing on
             * either screen saying why. The owner saw a switch that
             * did nothing.
             *
             * The amount is still the terms': what is due now and what
             * is left stay exactly as PaymentPolicyService works them
             * out, and the booking form still shows both. The method
             * says where the money is handed over, not how much. An
             * owner who turns this on has said they will take it at the
             * desk; it is not for this enum to tell them they may not.
             *
             * booking-form.js asks the same question — onSiteAllowed,
             * with no second clause — so the script and the server
             * still agree about every booking.
             *
             * With no terms at all the answer stays false: a caller
             * that has not said what is owed is asking about the site
             * in general, and "pay on arrival" is a fact about one
             * stay, not about the portal.
             */
            self::OnArrival => $terms !== null && $terms->onSiteAllowed,

            /*
             * GCASH. THE OWNER'S SWITCH DECIDES, AND NOTHING ELSE DOES.
             *
             * This needed an amount to collect as well, which is the
             * same coupling that was taken off OnArrival above and it
             * failed in the same way: one resort with both switches on
             * showed the guest exactly one method, and which one
             * depended on what the terms happened to ask for that week.
             * Changing the last-minute rule swapped GCash for paying on
             * arrival with nothing on either screen saying why.
             *
             * WHAT THE AMOUNT STILL DECIDES is whether there is a
             * payment to open. ManualPaymentService::isAvailable() asks
             * for a confirmed booking with something outstanding before
             * it offers the guest a reference number and a receipt
             * upload, so a stay with nothing due in advance simply
             * never reaches that step — the guest has said how they
             * mean to pay and settles at the resort. That is a quiet
             * answer, not a dead end.
             *
             * isUsable() rather than ->active alone: an account with the
             * switch on but no name or number on it cannot be paid, and
             * offering it IS a dead end.
             */
            self::ManualGcash => $manualAccount !== null
                && $manualAccount->isUsable(),
        };
    }

    /**
     * @return list<self>
     */
    public static function available(
        ?ResortManualPaymentAccount $manualAccount = null,
        ?PaymentTerms $terms = null,
    ): array {
        return array_values(array_filter(
            self::cases(),
            fn (self $preference) =>
                $preference->isAvailable($manualAccount, $terms)
        ));
    }
}