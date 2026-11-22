<?php

namespace App\Models;

use App\Enums\AccreditationStatus;
use App\Support\AccreditationKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row of the Municipal Tourism Office's official accreditation list.
 *
 * Uploaded by the office from its own spreadsheet, never written by a
 * resort owner. It is reference data: it describes a resort the office
 * has accredited, whether or not that resort has ever registered here.
 *
 * @property AccreditationStatus $accreditation_status
 */
class AccreditedResort extends Model
{
    protected $fillable = [
        'resort_name',
        'owner_name',
        'barangay',
        'accreditation_no',
        'accreditation_status',
        'valid_until',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'accreditation_status' => AccreditationStatus::class,
            'valid_until' => 'date',
        ];
    }

    /**
     * Keeps the comparison keys in step with the words they come from.
     *
     * In the model rather than in the import service because the office
     * can also edit a row by hand on the management screen, and a key
     * left stale by that path would silently stop matching — the row
     * would sit on the list looking correct and never be found.
     */
    protected static function booted(): void
    {
        static::saving(function (self $row): void {
            $row->name_key = AccreditationKey::name($row->resort_name);
            $row->owner_key = AccreditationKey::person($row->owner_name) ?: null;
            $row->barangay_key = AccreditationKey::barangay($row->barangay) ?: null;
        });
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The applications this row has been matched to.
     *
     * Nulled rather than cascaded when the row goes, so replacing the
     * list does not erase the fact that an application once matched.
     */
    public function matchedResorts(): HasMany
    {
        return $this->hasMany(Resort::class, 'matched_accredited_resort_id');
    }

    /**
     * Whether the office would currently stand behind this accreditation.
     *
     * Both halves matter. A row can say "active" and still be out of
     * date, because the status column is whatever the last spreadsheet
     * said while valid_until is a date that passes on its own.
     */
    public function isCurrent(): bool
    {
        if (! $this->accreditation_status->isCurrent()) {
            return false;
        }

        return $this->valid_until === null || ! $this->valid_until->isPast();
    }

    /** Why it is not current, for the reviewer to read. */
    public function lapseReason(): ?string
    {
        if (! $this->accreditation_status->isCurrent()) {
            return 'the accreditation is marked '.strtolower($this->accreditation_status->label());
        }

        if ($this->valid_until !== null && $this->valid_until->isPast()) {
            return 'the accreditation expired on '.$this->valid_until->format('j M Y');
        }

        return null;
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('accreditation_status', AccreditationStatus::Active->value)
            ->where(function (Builder $q): void {
                $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', now()->toDateString());
            });
    }

    /** Free-text search across the columns the office would search by. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            foreach (['resort_name', 'owner_name', 'barangay', 'accreditation_no'] as $column) {
                $q->orWhere($column, 'like', '%'.$term.'%');
            }
        });
    }
}
