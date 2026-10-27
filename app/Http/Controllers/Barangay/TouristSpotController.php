<?php

namespace App\Http\Controllers\Barangay;

use App\Enums\DestinationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Barangay\TouristSpotRequest;
use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Services\ImageUploadService;
use App\Services\TouristSpotSubmissionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Destinations written up by a barangay official.
 *
 * An official only ever sees and edits their own submissions, and never sets
 * a status: they send a spot in, and the Municipal Tourism Office decides
 * whether it goes on the public site. See DestinationPolicy.
 */
class TouristSpotController extends Controller
{
    public function __construct(
        private readonly ImageUploadService $images,
        private readonly TouristSpotSubmissionService $submissions,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Destination::class);

        $official = $request->user();

        return view('barangay.spots.index', [
            'spots' => Destination::query()
                ->submittedBy($official)
                ->with('category')
                ->withCount('images')
                ->latest('id')
                ->paginate(config('visitdasol.per_page')),
            'barangay' => $official->barangay,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Destination::class);

        return view('barangay.spots.create', $this->formData(new Destination));
    }

    public function store(TouristSpotRequest $request): RedirectResponse
    {
        $this->authorize('create', Destination::class);

        $storedPath = null;

        try {
            $spot = DB::transaction(function () use ($request, &$storedPath): Destination {
                $spot = $this->submissions->submit($request->user(), $request->spotData());

                if ($request->hasFile('main_image')) {
                    $storedPath = $this->images->store($request->file('main_image'), "destinations/{$spot->id}/cover");
                    $spot->forceFill(['main_image' => $storedPath])->save();
                }

                return $spot;
            });
        } catch (Throwable $e) {
            // The row is rolled back; the file is not, so it has to go by hand.
            $this->images->delete($storedPath);

            throw $e;
        }

        return redirect()->route('barangay.spots.index')
            ->with('success', "{$spot->name} has been sent to the tourism office. "
                .'It appears on the site once they have approved it.');
    }

    public function edit(Destination $spot): View
    {
        $this->authorize('update', $spot);

        $spot->load('images');

        return view('barangay.spots.edit', $this->formData($spot));
    }

    public function update(TouristSpotRequest $request, Destination $spot): RedirectResponse
    {
        $this->authorize('update', $spot);

        $spot->fill($request->spotData());

        DB::transaction(function () use ($request, $spot): void {
            if ($request->boolean('remove_main_image')) {
                $this->images->delete($spot->main_image);
                $spot->forceFill(['main_image' => null]);
            }

            if ($request->hasFile('main_image')) {
                $spot->forceFill([
                    'main_image' => $this->images->replace(
                        $spot->main_image,
                        $request->file('main_image'),
                        "destinations/{$spot->id}/cover"
                    ),
                ]);
            }

            $spot->save();
        });

        return redirect()->route('barangay.spots.index')->with('success', "{$spot->name} has been saved.");
    }

    /** A sent-back spot, corrected and offered to the office again. */
    public function resubmit(Destination $spot, Request $request): RedirectResponse
    {
        $this->authorize('resubmit', $spot);

        $this->submissions->resubmit($request->user(), $spot);

        return redirect()->route('barangay.spots.index')
            ->with('success', "{$spot->name} has been sent to the tourism office again.");
    }

    public function destroy(Destination $spot): RedirectResponse
    {
        $this->authorize('delete', $spot);

        $name = $spot->name;

        DB::transaction(function () use ($spot): void {
            $paths = $spot->images()->pluck('path')->push($spot->main_image)->filter();
            $spot->images()->delete();
            $spot->delete();

            foreach ($paths as $path) {
                $this->images->delete($path);
            }
        });

        return redirect()->route('barangay.spots.index')->with('success', "{$name} has been withdrawn.");
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Destination $spot): array
    {
        return [
            'spot' => $spot,
            'categories' => DestinationCategory::query()->orderBy('name')->pluck('name', 'id')->all(),
            'sentBack' => $spot->exists && $spot->status === DestinationStatus::Rejected,
        ];
    }
}
