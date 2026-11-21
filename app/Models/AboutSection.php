<?php

namespace App\Models;

use App\Enums\AboutSection as Section;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One section of the public About page, in two copies.
 *
 * Nothing here is mass assignable: App\Services\AboutPageService owns every
 * write, because "this is now published" is a statement about what the
 * municipality is telling the public and must not follow from form input.
 *
 * @property Section $section_key
 * @property array<string, mixed>|null $draft
 * @property array<string, mixed>|null $published
 */
class AboutSection extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'section_key' => Section::class,
            'draft' => 'array',
            'published' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'section_key';
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /* Reading --------------------------------------------------------- */

    /**
     * What the public sees: the published copy over the built-in defaults.
     *
     * Merged rather than replaced, so a payload written before a field
     * existed still answers for that field instead of rendering a blank
     * heading. Adding a field to a section's defaults is therefore safe on
     * a site that has already published it.
     *
     * @return array<string, mixed>
     */
    public function live(): array
    {
        return array_merge($this->section_key->defaults(), $this->published ?? []);
    }

    /**
     * What the office is working on: the draft over the live copy.
     *
     * A section never edited shows what is published; a section never
     * published shows the defaults. Either way the form opens with real
     * words in it rather than empty boxes.
     *
     * @return array<string, mixed>
     */
    public function working(): array
    {
        return array_merge($this->live(), $this->draft ?? []);
    }

    /** Whether the office has changes the public cannot see yet. */
    public function hasUnpublishedChanges(): bool
    {
        if ($this->draft === null) {
            return false;
        }

        return $this->working() !== $this->live();
    }

    public function isPublished(): bool
    {
        return $this->published !== null && $this->published_at !== null;
    }
}
