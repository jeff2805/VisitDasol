<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ExtraPriceUnit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\ResortExtraRequest;
use App\Models\Resort;
use App\Models\ResortExtra;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * What a resort offers besides the room.
 *
 * Kayak hire, a banana boat ride, island hopping. The resort is always
 * taken from the signed-in owner and every action is checked against
 * ResortExtraPolicy, which looks at the extra's own resort — so an
 * owner cannot read or change another resort's list by changing the URL.
 */
class ResortExtraController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function index(Request $request): View|RedirectResponse
    {
        $resort = $this->resortOrRedirect($request);

        if ($resort instanceof RedirectResponse) {
            return $resort;
        }

        $this->authorize('viewAny', ResortExtra::class);

        return view('owner.extras.index', [
            'resort' => $resort,
            'extras' => $resort->extras()
                ->inOrder()
                // How often each has actually been asked for, which is
                // the only thing on this screen worth sorting by eye.
                ->withCount([
                    'bookingExtras as requested_count',
                    'bookingExtras as confirmed_count' => fn ($query) => $query->confirmed(),
                ])
                ->get(),
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $resort = $this->resortOrRedirect($request);

        if ($resort instanceof RedirectResponse) {
            return $resort;
        }

        $this->authorize('create', ResortExtra::class);

        return view('owner.extras.create', [
            'resort' => $resort,
            'extra' => new ResortExtra,
            'units' => ExtraPriceUnit::options(),
        ]);
    }

    public function store(ResortExtraRequest $request): RedirectResponse
    {
        $resort = $request->user()->resort()->firstOrFail();

        $this->authorize('create', ResortExtra::class);

        $extra = new ResortExtra($request->validated());
        $extra->resort()->associate($resort);
        $extra->save();

        $this->logger->log(
            'resort.extra_added',
            $extra,
            "Added {$extra->name} to what {$resort->name} offers",
            [
                'price' => (string) $extra->price,
                'price_unit' => $extra->price_unit->value,
            ],
        );

        return redirect()->route('owner.extras.index')
            ->with('success', "{$extra->name} is now offered to guests booking your resort.");
    }

    public function edit(ResortExtra $extra): View
    {
        $this->authorize('update', $extra);

        return view('owner.extras.edit', [
            'resort' => $extra->resort,
            'extra' => $extra,
            'units' => ExtraPriceUnit::options(),
        ]);
    }

    public function update(ResortExtraRequest $request, ResortExtra $extra): RedirectResponse
    {
        $this->authorize('update', $extra);

        $extra->fill($request->validated())->save();

        $this->logger->log(
            'resort.extra_updated',
            $extra,
            "Updated {$extra->name}",
            [
                'price' => (string) $extra->price,
                'price_unit' => $extra->price_unit->value,
            ],
        );

        return redirect()->route('owner.extras.index')
            ->with('success', "{$extra->name} has been saved. Guests who already asked for it "
                .'keep the price they were quoted.');
    }

    /**
     * Stop offering something, or offer it again.
     *
     * Not a delete. Guests have asked for this and their requests
     * name it; switching it off takes it off the booking form and
     * leaves every record that mentions it intact.
     */
    public function status(ResortExtra $extra): RedirectResponse
    {
        $this->authorize('update', $extra);

        $extra->forceFill(['active' => ! $extra->active])->save();

        $this->logger->logStatusChange(
            'resort.extra_status_changed',
            $extra,
            ($extra->active ? 'Started offering ' : 'Stopped offering ').$extra->name,
            $extra->active ? 'inactive' : 'active',
            $extra->active ? 'active' : 'inactive',
        );

        return back()->with('success', $extra->active
            ? "{$extra->name} is offered to guests again."
            : "{$extra->name} is no longer offered. Requests already made are unaffected.");
    }

    private function resortOrRedirect(Request $request): Resort|RedirectResponse
    {
        $resort = $request->user()->resort()->first();

        if ($resort === null) {
            return redirect()->route('owner.resort.create')
                ->with('info', 'Register your resort first, then add what you offer besides the room.');
        }

        return $resort;
    }
}
