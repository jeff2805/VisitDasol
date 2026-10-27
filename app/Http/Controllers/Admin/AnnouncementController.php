<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AnnouncementStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AnnouncementRequest;
use App\Models\Announcement;
use App\Services\ActivityLogger;
use App\Services\ImageUploadService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Announcements and advisories from the Municipal Tourism Office.
 *
 * Writing is separate from publishing on purpose: drafts can be prepared
 * quietly, and a published announcement can be taken down again without
 * being deleted. A published date in the future keeps it hidden until then.
 */
class AnnouncementController extends Controller
{
    public function __construct(
        private readonly ImageUploadService $images,
        private readonly ActivityLogger $logger,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Announcement::class);

        $status = AnnouncementStatus::tryFrom((string) $request->query('status'));
        $search = trim((string) $request->query('q', ''));

        return view('admin.announcements.index', [
            'announcements' => Announcement::query()
                ->when($status, fn ($query) => $query->where('status', $status->value))
                ->when($search !== '', fn ($query) => $query->where('title', 'like', "%{$search}%"))
                ->with('author:id,first_name,last_name')
                ->orderByDesc('is_featured')
                ->latest('created_at')
                ->paginate(config('visitdasol.per_page'))
                ->withQueryString(),
            'status' => $status,
            'search' => $search,
            'counts' => [
                AnnouncementStatus::Draft->value => Announcement::query()->where('status', AnnouncementStatus::Draft->value)->count(),
                AnnouncementStatus::Published->value => Announcement::query()->where('status', AnnouncementStatus::Published->value)->count(),
            ],
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Announcement::class);

        return view('admin.announcements.create', ['announcement' => new Announcement]);
    }

    public function store(AnnouncementRequest $request): RedirectResponse
    {
        $this->authorize('create', Announcement::class);

        $announcement = new Announcement($request->announcementData());
        $announcement->forceFill([
            'status' => AnnouncementStatus::Draft,
            'created_by' => $request->user()->id,
        ]);

        DB::transaction(function () use ($request, $announcement): void {
            $announcement->save();

            if ($request->hasFile('image')) {
                $announcement->forceFill([
                    'image' => $this->images->store($request->file('image'), "announcements/{$announcement->id}"),
                ])->save();
            }
        });

        $this->logger->log('announcement.created', $announcement, "Wrote the announcement {$announcement->title}");

        return redirect()->route('admin.announcements.edit', $announcement)
            ->with('success', 'Saved as a draft. Publish it when you are ready.');
    }

    public function edit(Announcement $announcement): View
    {
        $this->authorize('update', $announcement);

        return view('admin.announcements.edit', ['announcement' => $announcement]);
    }

    public function update(AnnouncementRequest $request, Announcement $announcement): RedirectResponse
    {
        $this->authorize('update', $announcement);

        $announcement->fill($request->announcementData());

        DB::transaction(function () use ($request, $announcement): void {
            if ($request->boolean('remove_image')) {
                $this->images->delete($announcement->image);
                $announcement->forceFill(['image' => null]);
            }

            if ($request->hasFile('image')) {
                $announcement->forceFill([
                    'image' => $this->images->replace(
                        $announcement->image,
                        $request->file('image'),
                        "announcements/{$announcement->id}"
                    ),
                ]);
            }

            // Changing the date of something already published moves when it appears.
            if ($request->filled('published_at') && $announcement->status === AnnouncementStatus::Published) {
                $announcement->forceFill(['published_at' => CarbonImmutable::parse($request->validated('published_at'))]);
            }

            $announcement->save();
        });

        $this->logger->log('announcement.updated', $announcement, "Updated the announcement {$announcement->title}");

        return redirect()->route('admin.announcements.index')->with('success', 'Your changes have been saved.');
    }

    /**
     * Publishing only needs a date: the wording was already checked when
     * the announcement was written or edited.
     */
    public function publish(Request $request, Announcement $announcement): RedirectResponse
    {
        $this->authorize('publish', $announcement);

        $validated = $request->validate([
            'published_at' => ['nullable', 'date'],
        ]);

        $when = filled($validated['published_at'] ?? null)
            ? CarbonImmutable::parse($validated['published_at'])
            : CarbonImmutable::now();

        $announcement->forceFill([
            'status' => AnnouncementStatus::Published,
            'published_at' => $when,
        ])->save();

        $this->logger->logStatusChange('announcement.published', $announcement,
            "Published the announcement {$announcement->title}", 'draft', 'published', ['published_at' => $when->toDateTimeString()]);

        return back()->with('success', $when->isFuture()
            ? 'Scheduled. It appears to visitors on '.$when->format('M j, Y g:i A').'.'
            : 'Published. Visitors can read it now.');
    }

    public function unpublish(Announcement $announcement): RedirectResponse
    {
        $this->authorize('unpublish', $announcement);

        $announcement->forceFill([
            'status' => AnnouncementStatus::Draft,
            'is_featured' => false,
        ])->save();

        $this->logger->logStatusChange('announcement.unpublished', $announcement,
            "Took down the announcement {$announcement->title}", 'published', 'draft');

        return back()->with('success', 'Taken down. It is a draft again and visitors cannot see it.');
    }

    /** Pin an announcement to the top of the public list and home page. */
    public function feature(Announcement $announcement): RedirectResponse
    {
        $this->authorize('feature', $announcement);

        $featured = ! $announcement->is_featured;
        $announcement->forceFill(['is_featured' => $featured])->save();

        return back()->with('success', $featured
            ? 'Pinned to the top for visitors.'
            : 'No longer pinned.');
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        $this->authorize('delete', $announcement);

        $title = $announcement->title;

        DB::transaction(function () use ($announcement): void {
            $image = $announcement->image;
            $announcement->delete();
            $this->images->delete($image);
        });

        $this->logger->log('announcement.deleted', null, "Deleted the announcement {$title}");

        return redirect()->route('admin.announcements.index')->with('success', "{$title} has been deleted.");
    }
}
