<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DestinationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DestinationRequest;
use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Services\ActivityLogger;
use App\Services\ImageUploadService;
use App\Support\TableSort;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tourist destinations, written and published by the tourism office.
 * Visitors see a destination only while its status is "active".
 */
class DestinationController extends Controller
{
    public function __construct(
        private readonly ImageUploadService $images,
        private readonly ActivityLogger $logger,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Destination::class);

        $status = DestinationStatus::tryFrom((string) $request->query('status'));
        $category = DestinationCategory::query()->where('slug', (string) $request->query('category'))->first();
        $search = trim((string) $request->query('q', ''));

        $sort = TableSort::fromRequest($request, [
            'featured' => ['-is_featured', 'name'],
            'name' => 'name',
            'barangay' => ['barangay', 'name'],
            'status' => 'status',
            // A spot with no photograph is the one the office needs to chase,
            // so this column is worth being able to put at the top.
            'photos' => 'images_count',
            'added' => '-created_at',
        ], default: 'featured');

        return view('admin.destinations.index', [
            'destinations' => Destination::query()
                ->when($status, fn ($query) => $query->where('status', $status->value))
                ->when($category, fn ($query) => $query->where('category_id', $category->id))
                ->when($search !== '', fn ($query) => $query->where(function ($match) use ($search) {
                    $match->where('name', 'like', "%{$search}%")->orWhere('barangay', 'like', "%{$search}%");
                }))
                ->with('category')
                ->withCount('images')
                ->tap(fn ($query) => $sort->apply($query))
                ->paginate(config('visitdasol.per_page'))
                ->withQueryString(),
            'categories' => DestinationCategory::query()->orderBy('name')->get(),
            'status' => $status,
            'category' => $category,
            'search' => $search,
            'sort' => $sort,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Destination::class);

        return view('admin.destinations.create', $this->formData(new Destination));
    }

    public function store(DestinationRequest $request): RedirectResponse
    {
        $this->authorize('create', Destination::class);

        $destination = new Destination($request->destinationData());
        $destination->forceFill([
            'status' => DestinationStatus::Active,
            'created_by' => $request->user()->id,
        ]);

        $storedPath = null;

        try {
            DB::transaction(function () use ($request, $destination, &$storedPath): void {
                $destination->save();

                if ($request->hasFile('main_image')) {
                    $storedPath = $this->images->store($request->file('main_image'), "destinations/{$destination->id}/cover");
                    $destination->forceFill(['main_image' => $storedPath])->save();
                }
            });
        } catch (Throwable $e) {
            $this->images->delete($storedPath);

            throw $e;
        }

        $this->logger->log('destination.created', $destination, "Added the destination {$destination->name}");

        return redirect()->route('admin.destinations.edit', $destination)
            ->with('success', "{$destination->name} has been published. Add photos so visitors can see it.");
    }

    public function edit(Destination $destination): View
    {
        $this->authorize('update', $destination);

        $destination->load('images');

        return view('admin.destinations.edit', $this->formData($destination));
    }

    public function update(DestinationRequest $request, Destination $destination): RedirectResponse
    {
        $this->authorize('update', $destination);

        $destination->fill($request->destinationData());
        $changed = array_keys($destination->getDirty());

        DB::transaction(function () use ($request, $destination): void {
            if ($request->boolean('remove_main_image')) {
                $this->images->delete($destination->main_image);
                $destination->forceFill(['main_image' => null]);
            }

            if ($request->hasFile('main_image')) {
                $destination->forceFill([
                    'main_image' => $this->images->replace(
                        $destination->main_image,
                        $request->file('main_image'),
                        "destinations/{$destination->id}/cover"
                    ),
                ]);
            }

            $destination->save();
        });

        $this->logger->log('destination.updated', $destination, "Updated the destination {$destination->name}", [
            'fields' => $changed,
        ]);

        return redirect()->route('admin.destinations.index')->with('success', "{$destination->name} has been saved.");
    }

    /** Publish or hide a destination. */
    public function status(Destination $destination): RedirectResponse
    {
        $this->authorize('update', $destination);

        $published = $destination->status === DestinationStatus::Active;

        $destination->forceFill([
            'status' => $published ? DestinationStatus::Inactive : DestinationStatus::Active,
            // A hidden destination cannot stay on the home page.
            'is_featured' => $published ? false : $destination->is_featured,
        ])->save();

        $this->logger->log(
            $published ? 'destination.hidden' : 'destination.published',
            $destination,
            ($published ? 'Hid the destination ' : 'Published the destination ').$destination->name,
        );

        return back()->with('success', $published
            ? "{$destination->name} is hidden from visitors."
            : "{$destination->name} is visible to visitors again.");
    }

    public function feature(Destination $destination): RedirectResponse
    {
        $this->authorize('feature', $destination);

        $featured = ! $destination->is_featured;
        $destination->forceFill(['is_featured' => $featured])->save();

        $this->logger->log(
            $featured ? 'destination.featured' : 'destination.unfeatured',
            $destination,
            ($featured ? 'Featured the destination ' : 'Removed the feature flag from ').$destination->name,
        );

        return back()->with('success', $featured
            ? "{$destination->name} is now highlighted on the home page."
            : "{$destination->name} is no longer highlighted.");
    }

    public function destroy(Destination $destination): RedirectResponse
    {
        $this->authorize('delete', $destination);

        $name = $destination->name;

        DB::transaction(function () use ($destination): void {
            $paths = $destination->images()->pluck('path')->push($destination->main_image)->filter();
            $destination->images()->delete();
            $destination->delete();

            foreach ($paths as $path) {
                $this->images->delete($path);
            }
        });

        $this->logger->log('destination.deleted', null, "Deleted the destination {$name}");

        return redirect()->route('admin.destinations.index')->with('success', "{$name} has been deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Destination $destination): array
    {
        return [
            'destination' => $destination,
            'categories' => DestinationCategory::query()->orderBy('name')->pluck('name', 'id')->all(),
            'barangays' => config('visitdasol.barangays'),
        ];
    }
}
