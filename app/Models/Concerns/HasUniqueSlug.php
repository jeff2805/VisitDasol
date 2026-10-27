<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Gives a model a unique, URL-friendly slug when it is first created.
 *
 * The slug is generated from slugSource() ("name" by default) and is not
 * changed on later renames, so public links keep working.
 * Requires model events: do not seed these models with WithoutModelEvents.
 */
trait HasUniqueSlug
{
    public static function bootHasUniqueSlug(): void
    {
        static::creating(function (self $model): void {
            if (blank($model->getAttribute('slug'))) {
                $model->setAttribute('slug', static::uniqueSlug((string) $model->getAttribute($model->slugSource())));
            }
        });
    }

    public function slugSource(): string
    {
        return 'name';
    }

    public static function uniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = rtrim(Str::limit(Str::slug($value), 90, ''), '-') ?: 'item';
        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
