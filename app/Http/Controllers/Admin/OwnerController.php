<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TableSort;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Resort owners with their business and listing at a glance.
 */
class OwnerController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $status = UserStatus::tryFrom((string) $request->query('status'));
        $search = trim((string) $request->query('q', ''));

        $sort = TableSort::fromRequest($request, [
            'name' => ['last_name', 'first_name'],
            'status' => 'status',
            'approved' => '-created_at',
        ], default: 'name');

        return view('admin.owners.index', [
            'status' => $status,
            'search' => $search,
            'sort' => $sort,
            'owners' => User::query()
                ->resortOwners()
                ->when($status, fn ($query) => $query->withStatus($status))
                // This list only grows: every approved application lands here
                // and never leaves, so after a season the only way to find one
                // owner was to read every page.
                ->when($search !== '', fn ($query) => $query->where(function ($match) use ($search) {
                    $match->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('ownerProfile', fn ($profile) => $profile
                            ->where('business_name', 'like', "%{$search}%"))
                        ->orWhereHas('resorts', fn ($resort) => $resort
                            ->where('name', 'like', "%{$search}%"));
                }))
                ->with(['ownerProfile', 'resort'])
                ->tap(fn ($query) => $sort->apply($query))
                ->paginate(config('visitdasol.per_page'))
                ->withQueryString(),
        ]);
    }
}
