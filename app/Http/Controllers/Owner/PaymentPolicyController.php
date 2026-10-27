<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\PaymentPolicyRequest;
use App\Models\ResortPaymentPolicy;
use App\Services\ActivityLogger;
use App\Services\Payments\PaymentPolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * An owner's payment terms: how much up front, and by when.
 *
 * The resort always comes from the signed-in owner and never from the
 * form, and every action is checked against ResortPolicy, so one owner
 * can never reach another's terms by changing a URL.
 */
class PaymentPolicyController extends Controller
{
    public function __construct(
        private readonly PaymentPolicyService $policies,
        private readonly ActivityLogger $logger,
    ) {}

    public function edit(Request $request): View|RedirectResponse
    {
        $resort = $request->user()->resort()->first();

        if ($resort === null) {
            return redirect()->route('owner.resort.create')
                ->with('info', 'Register your resort first, then set your payment terms.');
        }

        $this->authorize('managePaymentPolicy', $resort);

        $policy = $resort->paymentTerms();

        return view('owner.payment-policy.edit', [
            'resort' => $resort,
            'policy' => $policy,
            'isDefault' => ! $policy->isCustomised(),

            /*
             * Two worked examples on a round number, so an owner can see
             * what their terms actually do before a guest meets them.
             * One ordinary booking, one arriving inside their own
             * last-minute window.
             */
            'preview' => $this->policies->terms(
                $policy, '5000.00', CarbonImmutable::today()->addDays(30)
            ),
            'previewLastMinute' => $this->policies->terms(
                $policy, '5000.00', CarbonImmutable::today()
            ),

            'manualAccount' => $resort->manualAccount()->first(),
        ]);
    }

    public function update(PaymentPolicyRequest $request): RedirectResponse
    {
        $resort = $request->user()->resort()->firstOrFail();

        $this->authorize('managePaymentPolicy', $resort);

        $policy = $resort->paymentPolicy()->firstOrNew();

        $policy->fill($request->policyAttributes(
            max(1, (int) ($policy->deposit_percent ?? 30))
        ));

        /*
         * Every save is a new edition. Bookings snapshot the number, so
         * a reservation can still say which terms it was made under
         * however many times the owner has changed their mind since.
         */
        $policy->resort()->associate($resort);
        $policy->version = (int) ($policy->version ?? 0) + 1;
        $policy->save();

        $this->logger->log(
            'resort.payment_policy_updated',
            $resort,
            "Updated the payment terms for {$resort->name}",
            [
                'version' => $policy->version,
                'requirement' => $policy->requirement->value,
                'deposit_percent' => $policy->deposit_percent,
                'deadline_hours' => $policy->deadline_hours,
                'last_minute_rule' => $policy->last_minute_rule->value,
                'allow_on_site' => $policy->allow_on_site,
            ],
        );

        return redirect()->route('owner.payment-policy.edit')->with(
            'success',
            'Your payment terms have been saved. Bookings already made keep the terms '
            .'they were agreed under.'
        );
    }

    /** Back to the portal's own terms, as a deliberate choice. */
    public function destroy(Request $request): RedirectResponse
    {
        $resort = $request->user()->resort()->firstOrFail();

        $this->authorize('managePaymentPolicy', $resort);

        $resort->paymentPolicy()->delete();

        $this->logger->log(
            'resort.payment_policy_reset',
            $resort,
            "Reset the payment terms for {$resort->name} to the portal's defaults",
        );

        return redirect()->route('owner.payment-policy.edit')->with(
            'success',
            "Your payment terms are back to the portal's defaults."
        );
    }
}
