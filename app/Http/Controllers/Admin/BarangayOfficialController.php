<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DestinationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BarangayOfficialRequest;
use App\Models\Destination;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Barangay officials, as the office sees them.
 *
 * One account per person, tied to one barangay. Deactivating is done from the
 * accounts screen like any other account; there is no delete, because the
 * destinations they wrote stay on the site and the record of who wrote them
 * has to stay with them.
 */
class BarangayOfficialController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function index(): View
    {
        $this->authorize('viewAny', User::class);

        return view('admin.barangay-officials.index', [
            'officials' => User::query()
                ->barangayOfficials()
                // How much each one has actually contributed, which is the
                // only thing on this screen worth sorting by.
                ->withCount([
                    'submittedSpots as published_count' => fn ($query) => $query
                        ->where('status', DestinationStatus::Active->value),
                    'submittedSpots as waiting_count' => fn ($query) => $query
                        ->where('status', DestinationStatus::Pending->value),
                ])
                ->orderBy('barangay')
                ->orderBy('last_name')
                ->paginate(config('visitdasol.per_page')),
            // So the office can see at a glance which barangays have nobody.
            'covered' => User::query()->barangayOfficials()
                ->where('status', UserStatus::Active->value)
                ->pluck('barangay')
                ->filter()
                ->unique()
                ->all(),
            'barangays' => config('visitdasol.barangays'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('createBarangayOfficial', User::class);

        return view('admin.barangay-officials.create', [
            'barangays' => config('visitdasol.barangays'),
        ]);
    }

    public function store(BarangayOfficialRequest $request): RedirectResponse
    {
        $this->authorize('createBarangayOfficial', User::class);

        $official = DB::transaction(function () use ($request): User {
            $official = new User($request->safe()->only([
                'first_name', 'last_name', 'email', 'phone', 'password',
            ]));

            /*
             * Active straight away: the office has just vouched for this
             * person by typing their details in, so there is nothing left to
             * approve. The email address is NOT marked confirmed — they go
             * through the usual code on first sign-in, which is what proves
             * the address was typed correctly.
             */
            $official->forceFill([
                'role' => UserRole::BarangayOfficial,
                'status' => UserStatus::Active,
                'barangay' => $request->validated('barangay'),
            ])->save();

            return $official;
        });

        $this->logger->log(
            'barangay_official.created',
            $official,
            "Created a barangay official account for {$official->full_name} ({$official->barangay})",
            ['barangay' => $official->barangay],
        );

        return redirect()->route('admin.barangay-officials.index')
            ->with('success', "{$official->full_name} can now send in destinations for Barangay {$official->barangay}. "
                .'Give them the password you set; they will be asked to confirm their email address when they first sign in.');
    }

    public function show(User $official): View
    {
        $this->authorize('view', $official);

        abort_unless($official->isBarangayOfficial(), 404);

        return view('admin.barangay-officials.show', [
            'official' => $official,
            'spots' => Destination::query()
                ->submittedBy($official)
                ->with('category')
                ->latest('id')
                ->get(),
        ]);
    }
}
