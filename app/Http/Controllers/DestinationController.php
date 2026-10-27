<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use App\Models\DestinationCategory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Public tourist destination pages. Only destinations the office has
 * published (status = active) are listed or reachable.
 */
class DestinationController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $category = DestinationCategory::query()
            ->where('slug', (string) $request->query('category'))
            ->first();

        return view('site.destinations.index', [
            'destinations' => Destination::active()
                ->with('category')
                ->when($category, fn (Builder $query) => $query->where('category_id', $category->id))
                ->when($search !== '', function (Builder $query) use ($search) {
                    $query->where(function (Builder $match) use ($search) {
                        $match->where('name', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%")
                            ->orWhere('barangay', 'like', "%{$search}%");
                    });
                })
                ->orderByDesc('is_featured')
                ->orderBy('name')
                ->paginate(config('visitdasol.per_page'))
                ->withQueryString(),
            'categories' => DestinationCategory::query()
                ->withCount(['destinations' => fn (Builder $query) => $query->active()])
                ->orderBy('name')
                ->get()
                ->filter(fn (DestinationCategory $item) => $item->destinations_count > 0),
            'category' => $category,
            'search' => $search,
        ]);
    }

    public function show(Destination $destination): View
    {
        abort_unless($destination->isActive(), 404);

        $destination->load(['category', 'images']);

        return view('site.destinations.show', [
            'destination' => $destination,
            'related' => Destination::active()
                ->whereKeyNot($destination->id)
                ->where('category_id', $destination->category_id)
                ->with('category')
                ->orderByDesc('is_featured')
                ->limit(3)
                ->get(),
        ]);
    }
}
