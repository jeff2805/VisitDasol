<?php

namespace App\Services;

use App\Enums\AboutSection as Section;
use App\Models\AboutSection;
use App\Models\Destination;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The About page's content: reading it, drafting it, and publishing it.
 *
 * The ONLY place a section is written. Three rules live here and nowhere
 * else, which is why the controller is thin:
 *
 *   1. SAVING IS NOT PUBLISHING. saveDraft() touches the draft column and
 *      nothing the public reads. A page that could change from a half
 *      finished form is a page nobody dares edit.
 *
 *   2. PUBLISHING IS A COPY. publish() moves draft into published and
 *      stamps who and when. There is no third state to get wrong.
 *
 *   3. A FILE IS DELETED ONLY WHEN NEITHER COPY WANTS IT. A replaced
 *      picture is still on the live page until the change is published, so
 *      deleting it on upload would put a hole in the public page while the
 *      office was still deciding.
 */
class AboutPageService
{
    public function __construct(
        private readonly ImageUploadService $images,
        private readonly ActivityLogger $logger,
    ) {}

    /* Reading ---------------------------------------------------------- */

    /**
     * Every section's row, created on first use so the office always has
     * something to edit.
     *
     * @return Collection<string, AboutSection>
     */
    public function all(): Collection
    {
        $rows = AboutSection::query()->get()->keyBy(fn (AboutSection $s): string => $s->section_key->value);

        foreach (Section::cases() as $section) {
            if (! $rows->has($section->value)) {
                $rows->put($section->value, $this->row($section));
            }
        }

        return $rows->sortBy(
            fn (AboutSection $s): int => array_search($s->section_key, Section::cases(), true)
        );
    }

    /** One section's row, made if it does not exist yet. */
    public function row(Section $section): AboutSection
    {
        return AboutSection::query()->firstOrCreate(['section_key' => $section->value]);
    }

    /**
     * What the public About page renders.
     *
     * @return array<string, array<string, mixed>>
     */
    public function published(): array
    {
        return $this->resolve(fn (AboutSection $s): array => $s->live());
    }

    /**
     * What the preview renders: the office's unsaved-to-the-public work.
     *
     * @return array<string, array<string, mixed>>
     */
    public function preview(): array
    {
        return $this->resolve(fn (AboutSection $s): array => $s->working());
    }

    /**
     * @param  callable(AboutSection): array<string, mixed>  $reader
     * @return array<string, array<string, mixed>>
     */
    private function resolve(callable $reader): array
    {
        $out = [];

        foreach ($this->all() as $key => $row) {
            $out[$key] = $reader($row);
        }

        return $out;
    }

    /**
     * The destination records the About page shows, in the office's order.
     *
     * READ FROM THE DESTINATIONS TABLE, never copied into this module: the
     * About page shows the same beach the Destinations screen manages, and
     * a second description of it would be a second thing to keep right.
     *
     * An id whose destination has since been deleted or deactivated simply
     * does not come back, so the page quietly shows the rest rather than a
     * broken card. With no choice made at all, the featured destinations
     * stand in — a fresh install should not show an empty row.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Destination>
     */
    public function destinationsFor(array $ids): Collection
    {
        if ($ids === []) {
            return Destination::query()->active()->where('is_featured', true)
                ->with('images')->orderBy('name')->limit(3)->get();
        }

        $found = Destination::query()->active()->whereKey($ids)->with('images')->get()->keyBy('id');

        // The office's order, not the database's.
        return collect($ids)
            ->map(fn ($id) => $found->get((int) $id))
            ->filter()
            ->values();
    }

    /* Writing ---------------------------------------------------------- */

    /**
     * Save what the office has typed. The public page does not move.
     *
     * @param  array<string, mixed>  $payload
     */
    public function saveDraft(Section $section, array $payload, User $actor): AboutSection
    {
        return DB::transaction(function () use ($section, $payload, $actor): AboutSection {
            $row = AboutSection::query()
                ->where('section_key', $section->value)
                ->lockForUpdate()
                ->first() ?? $this->row($section);

            $row->forceFill([
                // Merged over what was already being drafted, so a form
                // that posts one half of a section does not silently drop
                // the other half.
                'draft' => array_merge($row->working(), $payload),
                'updated_by' => $actor->id,
            ])->save();

            $this->logger->log(
                'about.draft_saved',
                $row,
                "Saved a draft of the About page's {$section->label()} section",
                ['section' => $section->value],
                $actor,
            );

            return $row;
        });
    }

    /**
     * Put the draft in front of the public.
     *
     * @throws \RuntimeException when there is nothing to publish
     */
    public function publish(Section $section, User $actor): AboutSection
    {
        return DB::transaction(function () use ($section, $actor): AboutSection {
            $row = AboutSection::query()
                ->where('section_key', $section->value)
                ->lockForUpdate()
                ->first() ?? $this->row($section);

            $wasLive = $row->live();
            $goingLive = $row->working();

            $row->forceFill([
                'published' => $goingLive,
                'draft' => null,
                'published_by' => $actor->id,
                'published_at' => now(),
            ])->save();

            // Pictures the old version used and the new one does not are
            // now referenced by nothing, and only now is it safe to remove
            // them from the disk.
            $this->forgetUnusedFiles($wasLive, $goingLive);

            $this->logger->log(
                'about.published',
                $row,
                "Published the About page's {$section->label()} section",
                ['section' => $section->value],
                $actor,
            );

            return $row;
        });
    }

    /**
     * Take a section back off the public page.
     *
     * The words are kept as a draft rather than thrown away: unpublishing
     * is usually "this is wrong, take it down while I fix it", and an
     * office that lost the text doing so would not use the button twice.
     */
    public function unpublish(Section $section, User $actor): AboutSection
    {
        return DB::transaction(function () use ($section, $actor): AboutSection {
            $row = AboutSection::query()
                ->where('section_key', $section->value)
                ->lockForUpdate()
                ->first() ?? $this->row($section);

            $row->forceFill([
                'draft' => $row->working(),
                'published' => null,
                'published_at' => null,
                'published_by' => null,
                'updated_by' => $actor->id,
            ])->save();

            $this->logger->log(
                'about.unpublished',
                $row,
                "Took the About page's {$section->label()} section off the public page",
                ['section' => $section->value],
                $actor,
            );

            return $row;
        });
    }

    /* Pictures --------------------------------------------------------- */

    /**
     * Store an upload and hand back its path.
     *
     * NOTHING IS DELETED HERE. The picture being replaced may still be the
     * one on the live page, and the office has not published this change
     * yet. Files are tidied in publish(), once it is settled which of them
     * nobody is looking at.
     */
    public function storeImage(Section $section, UploadedFile $file): string
    {
        return $this->images->store($file, $section->folder());
    }

    /**
     * Paths referenced anywhere in a payload: the single picture, and
     * every picture in a gallery.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function pathsIn(array $payload): array
    {
        $paths = [];

        if (filled($payload['image_path'] ?? null)) {
            $paths[] = (string) $payload['image_path'];
        }

        foreach ((array) ($payload['images'] ?? []) as $image) {
            if (filled($image['path'] ?? null)) {
                $paths[] = (string) $image['path'];
            }
        }

        return $paths;
    }

    /**
     * Remove files the new version no longer refers to.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function forgetUnusedFiles(array $before, array $after): void
    {
        $orphans = array_diff($this->pathsIn($before), $this->pathsIn($after));

        foreach ($orphans as $path) {
            $this->images->delete($path);
        }
    }
}
