<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EnvironmentalFeePolicyRequest;
use App\Models\EnvironmentalFeeCheckpoint;
use App\Models\EnvironmentalFeePolicy;
use App\Services\ActivityLogger;
use App\Services\EnvironmentalFeeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The municipal environmental fee: its configuration and its history.
 *
 * The office's screen, and only the office's — every action here is
 * checked against EnvironmentalFeePolicyPolicy on the server as well as
 * behind the role middleware on the route, because a hidden menu item
 * is not authorisation.
 *
 * Nothing in this controller touches a booking, a room rate or a
 * payment. The fee is municipal money and is kept apart from resort
 * income by having no path between them at all.
 */
class EnvironmentalFeeController extends Controller
{
    public function __construct(
        private readonly EnvironmentalFeeService $fees,
        private readonly ActivityLogger $logger,
    ) {}

    /** What is configured now, and everything that was configured before. */
    public function index(): View
    {
        $this->authorize('viewAny', EnvironmentalFeePolicy::class);

        return view('admin.environmental-fee.index', [
            'active' => $this->fees->activePolicy(),
            'blocked' => $this->fees->blockedReason(),

            // The table is its own history: each save is a row.
            'policies' => EnvironmentalFeePolicy::query()
                ->with(['createdBy:id,first_name,last_name', 'enabledBy:id,first_name,last_name'])
                ->withCount('collections')
                ->latest('id')
                ->paginate(config('visitdasol.per_page')),

            'checkpointCount' => EnvironmentalFeeCheckpoint::query()->active()->count(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('configure', EnvironmentalFeePolicy::class);

        return view('admin.environmental-fee.create', [
            'policy' => new EnvironmentalFeePolicy,
        ]);
    }

    /**
     * Record what an ordinance says. Switched OFF.
     *
     * Writing down a rate and beginning to collect it are two different
     * decisions, so this does only the first. The office enables it
     * separately, and that act is logged with a name against it.
     */
    public function store(EnvironmentalFeePolicyRequest $request): RedirectResponse
    {
        $this->authorize('configure', EnvironmentalFeePolicy::class);

        $policy = new EnvironmentalFeePolicy($request->validated());
        $policy->createdBy()->associate($request->user());
        $policy->enabled = false;
        $policy->save();

        $this->logger->log(
            'environmental_fee.policy_created',
            $policy,
            'Recorded an environmental fee configuration',
            [
                'amount' => (string) $policy->amount,
                'scope' => $policy->scope?->value,
                'effective_from' => $policy->effective_from?->toDateString(),
                'effective_until' => $policy->effective_until?->toDateString(),
                'authority_reference' => $policy->authority_reference,
                'enabled' => false,
            ],
        );

        return redirect()->route('admin.environmental-fee.index')->with(
            'success',
            'The configuration has been saved and is switched off. '
            .'Nothing will be collected until you turn it on.'
        );
    }

    /**
     * Change when a fee stops applying — and nothing else.
     *
     * An end date that passes takes the fee out of force, visitors
     * stop being told about it, and nothing may be collected. Until
     * now the only way back was a whole new policy, which is the right
     * answer when the RATE changes and overkill when the council
     * simply extended the same ordinance.
     *
     * THE RATE IS NOT TOUCHABLE HERE, on purpose. Receipts have been
     * written against this policy at this amount; editing the amount
     * would rewrite what visitors were charged on days already gone.
     */
    public function extend(Request $request, EnvironmentalFeePolicy $policy): RedirectResponse
    {
        $this->authorize('extend', $policy);

        $validated = $request->validate([
            /*
             * Nullable means "no end date" — the ordinary shape of a
             * municipal ordinance, which runs until it is repealed.
             */
            'effective_until' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:'.$policy->effective_from->toDateString(),
            ],
        ], [
            'effective_until.after_or_equal' =>
                'The end date cannot be before the day this fee started ('
                .$policy->effective_from->format('M j, Y').').',
        ]);

        $before = $policy->effective_until?->toDateString();
        $after = $validated['effective_until'] ?? null;

        if ($before === $after) {
            return back()->with('info', 'That is already the end date.');
        }

        $policy->forceFill(['effective_until' => $after])->save();

        $this->logger->log(
            'environmental_fee.policy_extended',
            $policy->refresh(),
            $after === null
                ? 'Removed the end date from the environmental fee'
                : 'Changed the environmental fee end date to '
                    .$policy->effective_until->format('M j, Y'),
            [
                // The rate is recorded unchanged, so the trail shows
                // plainly that this edit did not touch it.
                'amount' => (string) $policy->amount,
                'effective_from' => $policy->effective_from->toDateString(),
                'effective_until_was' => $before ?? '(none)',
                'effective_until_now' => $after ?? '(none)',
            ],
        );

        return redirect()
            ->route('admin.environmental-fee.index')
            ->with('success', $after === null
                ? 'The fee now runs until you stop it.'
                : 'The fee now stops applying on '.$policy->effective_until->format('M j, Y').'.');
    }

    /**
     * Begin, or stop, collecting.
     *
     * Its own action on purpose. This is the moment a municipality
     * starts taking money from visitors, and it is recorded with who
     * did it and when — which is also what the policies table's
     * enabled_by and enabled_at columns are for.
     */
    public function toggle(Request $request, EnvironmentalFeePolicy $policy): RedirectResponse
    {
        $this->authorize('configure', EnvironmentalFeePolicy::class);

        $turningOn = ! $policy->enabled;

        /*
         * Refuse to switch on something that could not be collected
         * against anyway. An officer who enables a policy and then finds
         * nothing happens at the gate has been told nothing useful.
         */
        if ($turningOn) {
            $reason = $policy->amount === null
                ? 'This configuration has no amount.'
                : null;

            if ($reason !== null) {
                return back()->with('error', $reason);
            }

            $clash = EnvironmentalFeePolicy::query()
                ->where('enabled', true)
                ->whereKeyNot($policy->id)
                ->where('effective_from', '<=', $policy->effective_until ?? '9999-12-31')
                ->where(function ($query) use ($policy): void {
                    $query->whereNull('effective_until')
                        ->orWhere('effective_until', '>=', $policy->effective_from);
                })
                ->exists();

            if ($clash) {
                return back()->with(
                    'error',
                    'Another fee is already switched on for these dates. Two rates '
                    .'covering the same day would leave nobody able to say what a '
                    .'visitor owes. Switch the other one off first.'
                );
            }
        }

        $policy->forceFill($turningOn
            ? ['enabled' => true, 'enabled_by' => $request->user()->id, 'enabled_at' => now()]
            : ['enabled' => false])->save();

        $this->logger->logStatusChange(
            $turningOn ? 'environmental_fee.enabled' : 'environmental_fee.disabled',
            $policy,
            ($turningOn ? 'Started' : 'Stopped').' environmental fee collection',
            $turningOn ? 'disabled' : 'enabled',
            $turningOn ? 'enabled' : 'disabled',
            ['amount' => (string) $policy->amount],
        );

        return back()->with('success', $turningOn
            ? 'Collection is on. Assigned collectors can now record fees at active checkpoints.'
            : 'Collection is off. No further fees can be recorded.');
    }
}
