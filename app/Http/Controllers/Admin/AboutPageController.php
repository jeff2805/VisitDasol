<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AboutSection as Section;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAboutSectionRequest;
use App\Models\AboutSection;
use App\Models\Destination;
use App\Services\AboutPageService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * About Page Management, for the Municipal Tourism Office.
 *
 * Seven sections, each saved and published on its own. Deliberately not one
 * enormous form: an office correcting a postal code should not have to scroll
 * past six paragraphs of history, and should not be able to publish them by
 * accident while doing it.
 *
 * Every action is authorized against AboutSectionPolicy before anything is
 * read or written. The service owns the rules about what saving and
 * publishing mean; this reads the form and turns a refusal into a sentence.
 */
class AboutPageController extends Controller
{
    public function __construct(private readonly AboutPageService $about) {}

    /** The seven cards, and what state each is in. */
    public function index(): View
    {
        $this->authorize('viewAny', AboutSection::class);

        return view('admin.about.index', [
            'sections' => $this->about->all(),
        ]);
    }

    /** One section's form. */
    public function edit(string $section): View
    {
        $this->authorize('viewAny', AboutSection::class);

        $section = $this->sectionOr404($section);
        $row = $this->about->row($section);

        return view('admin.about.edit', [
            'section' => $section,
            'row' => $row,
            // The draft over the live copy, so the form opens with real
            // words rather than empty boxes.
            'values' => $row->working(),

            // Only for the destinations section, and only the real records:
            // the About page points at destinations, it does not hold them.
            'destinations' => $section === Section::Destinations
                ? Destination::query()->active()->orderBy('name')->get(['id', 'name', 'barangay'])
                : collect(),
        ]);
    }

    /**
     * Save what has been typed. THE PUBLIC PAGE DOES NOT MOVE.
     */
    public function update(UpdateAboutSectionRequest $request, string $section): RedirectResponse
    {
        $this->authorize('update', AboutSection::class);

        $section = $this->sectionOr404($section);

        $payload = $this->payload($request, $section);

        $this->about->saveDraft($section, $payload, $request->user());

        return redirect()
            ->route('admin.about.edit', $section->value)
            ->with('success', 'Saved as a draft. The public page has not changed yet — '
                .'use Publish when you are ready.');
    }

    public function publish(Request $request, string $section): RedirectResponse
    {
        $this->authorize('publish', AboutSection::class);

        $section = $this->sectionOr404($section);

        $this->about->publish($section, $request->user());

        return redirect()
            ->route('admin.about.index')
            ->with('success', "The {$section->label()} section is now live on the About page.");
    }

    public function unpublish(Request $request, string $section): RedirectResponse
    {
        $this->authorize('publish', AboutSection::class);

        $section = $this->sectionOr404($section);

        $this->about->unpublish($section, $request->user());

        return redirect()
            ->route('admin.about.index')
            ->with('warning', "The {$section->label()} section has been taken off the public page. "
                .'What you wrote is kept as a draft.');
    }

    /**
     * The About page as it WOULD look, drafts and all.
     *
     * The real public template with the draft content poured into it, so
     * what the office checks is the page itself and not an approximation
     * of it that could be wrong in its own way.
     */
    public function preview(): View
    {
        $this->authorize('viewAny', AboutSection::class);

        $content = $this->about->preview();

        return view('site.about', [
            'about' => $content,
            'destinations' => $this->about->destinationsFor(
                (array) ($content['destinations']['destination_ids'] ?? [])
            ),
            'isPreview' => true,
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Read the form into the payload this section stores.
     *
     * @return array<string, mixed>
     */
    private function payload(UpdateAboutSectionRequest $request, Section $section): array
    {
        $payload = $request->safe()->except([
            'image', 'remove_image', 'new_images',
            'gallery_path', 'gallery_caption', 'gallery_order',
            'destination_order',
        ]);

        if ($section->hasImage()) {
            $payload = $payload + $this->singleImage($request, $section);
        }

        if ($section->hasGallery()) {
            $payload['images'] = $this->gallery($request, $section);
        }

        if ($section === Section::Destinations) {
            $payload['destination_ids'] = $this->orderedDestinations($request);
        }

        return $payload;
    }

    /**
     * The chosen destinations, in the order the office numbered them.
     *
     * STORED IN ORDER, not stored with an order. The payload is a plain
     * list and the page walks it, so there is no second field to fall out
     * of step with the first — and AboutPageService::destinationsFor()
     * needs to know nothing about positions.
     *
     * The numbers are keyed by destination id rather than by position in
     * the form, so unticking a box and ticking it again does not quietly
     * move the place to the end.
     *
     * @return list<int>
     */
    private function orderedDestinations(UpdateAboutSectionRequest $request): array
    {
        $chosen = array_map('intval', (array) $request->validated('destination_ids', []));
        $positions = (array) $request->validated('destination_order', []);

        usort($chosen, function (int $a, int $b) use ($positions): int {
            // Anything unnumbered sorts after everything numbered, rather
            // than jumping to the front as a zero would.
            $first = $positions[$a] ?? PHP_INT_MAX;
            $second = $positions[$b] ?? PHP_INT_MAX;

            return [$first, $a] <=> [$second, $b];
        });

        return array_values($chosen);
    }

    /**
     * @return array<string, mixed>
     */
    private function singleImage(UpdateAboutSectionRequest $request, Section $section): array
    {
        if ($request->boolean('remove_image')) {
            return ['image_path' => null];
        }

        if ($request->hasFile('image')) {
            return ['image_path' => $this->about->storeImage($section, $request->file('image'))];
        }

        // Neither replaced nor removed: leave whatever the section had.
        return [];
    }

    /**
     * The gallery after this save: the pictures that survived, in the order
     * the form posted them, then anything newly uploaded.
     *
     * A picture left out of gallery_path is a picture the office removed.
     * Its file is not deleted here — it may still be on the live page until
     * this change is published; see AboutPageService::publish().
     *
     * @return list<array{path: string, caption: string|null}>
     */
    private function gallery(UpdateAboutSectionRequest $request, Section $section): array
    {
        $paths = (array) $request->validated('gallery_path', []);
        $captions = (array) $request->validated('gallery_caption', []);
        $positions = (array) $request->validated('gallery_order', []);

        $kept = [];

        foreach ($paths as $index => $path) {
            $kept[] = [
                'path' => (string) $path,
                'caption' => filled($captions[$index] ?? null) ? (string) $captions[$index] : null,
                // The form's own row number stands in for a blank box, so
                // an office that numbers nothing keeps the order it sees.
                'position' => $positions[$index] ?? $index,
                'tiebreak' => $index,
            ];
        }

        usort($kept, fn (array $a, array $b): int =>
            [$a['position'], $a['tiebreak']] <=> [$b['position'], $b['tiebreak']]);

        $images = array_map(
            fn (array $image): array => ['path' => $image['path'], 'caption' => $image['caption']],
            $kept,
        );

        // New pictures go on the end, where the office can see them and
        // number them on the next save. Slotting them in by guesswork
        // would reorder a gallery nobody asked to reorder.
        foreach ((array) $request->file('new_images', []) as $file) {
            $images[] = [
                'path' => $this->about->storeImage($section, $file),
                'caption' => null,
            ];
        }

        return $images;
    }

    /** An address naming a section that does not exist is not found. */
    private function sectionOr404(string $section): Section
    {
        return Section::tryFrom($section) ?? abort(404);
    }
}
