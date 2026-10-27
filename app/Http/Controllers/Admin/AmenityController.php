<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AmenityRequest;
use App\Models\Amenity;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * The amenity list owners pick from when describing their resort.
 *
 * Switching an amenity off keeps it on resorts that already use it but
 * removes it from the owner's form. Deleting is only possible while no
 * resort uses it, so existing listings never change silently.
 */
class AmenityController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function index(): View
    {
        $this->authorize('viewAny', Amenity::class);

        return view('admin.amenities.index', [
            'amenities' => Amenity::query()->withCount('resorts')->orderBy('name')->get(),
        ]);
    }

    public function store(AmenityRequest $request): RedirectResponse
    {
        $this->authorize('create', Amenity::class);

        $amenity = Amenity::create($request->validated() + ['is_active' => true]);

        $this->logger->log('amenity.created', $amenity, "Added the amenity {$amenity->name}");

        return back()->with('success', "{$amenity->name} has been added.");
    }

    public function update(AmenityRequest $request, Amenity $amenity): RedirectResponse
    {
        $this->authorize('update', Amenity::class);

        $amenity->update($request->validated());

        return back()->with('success', "{$amenity->name} has been saved.");
    }

    public function toggle(Amenity $amenity): RedirectResponse
    {
        $this->authorize('update', Amenity::class);

        $amenity->update(['is_active' => ! $amenity->is_active]);

        return back()->with('success', $amenity->is_active
            ? "{$amenity->name} can be chosen again."
            : "{$amenity->name} is no longer offered to owners. Resorts already using it keep it.");
    }

    public function destroy(Amenity $amenity): RedirectResponse
    {
        $this->authorize('delete', Amenity::class);

        if ($amenity->resorts()->exists()) {
            return back()->with('error', "{$amenity->name} is used by resorts. Switch it off instead of deleting it.");
        }

        $name = $amenity->name;
        $amenity->delete();

        $this->logger->log('amenity.deleted', null, "Deleted the amenity {$name}");

        return back()->with('success', "{$name} has been deleted.");
    }
}
