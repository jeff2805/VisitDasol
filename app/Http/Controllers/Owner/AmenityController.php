<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\AmenityRequest;
use App\Models\Amenity;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The amenity list, as a resort owner sees it.
 *
 * ADD ONLY, AND THAT IS THE WHOLE DESIGN.
 *
 * There is one amenities table and every resort shares it — that is
 * what makes "filter by amenity" work across the whole municipality. So
 * an owner may put something on the list that is genuinely missing, and
 * may not rename, switch off or delete anything, because each of those
 * reaches into other people's listings. One owner deleting "Wi-Fi"
 * would strip it from every resort that had ticked it and from the
 * public filter at the same time.
 *
 * Which amenities a given resort HAS is a different question, answered
 * by the tickboxes on the resort form and stored in the pivot.
 */
class AmenityController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function index(Request $request): View
    {
        $resort = $request->user()->resort()->with('amenities')->first();

        return view('owner.amenities.index', [
            'resort' => $resort,

            /*
             * Live entries only. One the office switched off is not
             * offered to anybody, and showing it here would invite an
             * owner to tick something no visitor can filter by.
             */
            'amenities' => Amenity::query()
                ->active()
                ->withCount('resorts')
                ->orderBy('name')
                ->get(),

            // What this owner's own resort already claims, so the page
            // can say which of them are theirs.
            'mine' => $resort?->amenities->pluck('id')->all() ?? [],
        ]);
    }

    public function store(AmenityRequest $request): RedirectResponse
    {
        $amenity = Amenity::create([
            'name' => $request->validated('name'),
            'icon' => $request->validated('icon'),

            // Live the moment it is added. An owner adding something
            // only to find it absent from their own form would have
            // gained nothing.
            'is_active' => true,
        ]);

        /*
         * Attached to the asker's resort straight away. They are adding
         * it because they offer it; making them go to another page to
         * tick what they just typed is a step for nothing.
         */
        $resort = $request->user()->resort()->first();

        if ($resort !== null) {
            $resort->amenities()->syncWithoutDetaching([$amenity->id]);
        }

        $this->logger->log(
            'amenity.added',
            $amenity,
            'Added "'.$amenity->name.'" to the amenity list',
            ['resort_id' => $resort?->id]
        );

        return redirect()
            ->route('owner.amenities.index')
            ->with('success', '"'.$amenity->name.'" is on the list and ticked for your resort.');
    }
}
