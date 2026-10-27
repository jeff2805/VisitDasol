<?php

namespace App\Models;

use App\Enums\LastMinuteRule;
use App\Enums\PaymentDeadlineReference;
use App\Enums\PaymentRequirement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One resort's payment terms.
 *
 * A resort that has never opened the settings page has no row here and
 * uses the portal's defaults (config('visitdasol.payment_policy')). That
 * is why nothing reads this model directly: ask the resort for
 * paymentTerms(), which hands back a policy either way.
 *
 * resort_id is not fillable. The controller takes the resort from the
 * signed-in owner, so a form can never point a policy at somebody else's
 * listing.
 *
 * @property PaymentRequirement $requirement
 * @property PaymentDeadlineReference $deadline_reference
 * @property LastMinuteRule $last_minute_rule
 */
class ResortPaymentPolicy extends Model
{
    protected $fillable = [
        'requirement',
        'deposit_percent',
        'deadline_hours',
        'deadline_reference',
        'last_minute_rule',
        'last_minute_hours',
        'allow_on_site',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requirement' => PaymentRequirement::class,
            'deadline_reference' => PaymentDeadlineReference::class,
            'last_minute_rule' => LastMinuteRule::class,
            'deposit_percent' => 'integer',
            'deadline_hours' => 'integer',
            'last_minute_hours' => 'integer',
            'allow_on_site' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function resort(): BelongsTo
    {
        return $this->belongsTo(Resort::class);
    }

    /**
     * The portal's terms, as an unsaved policy.
     *
     * Returned for a resort that has set none, so every caller gets a
     * real policy object and none of them has to carry a second set of
     * fallbacks. version 0 marks it as nobody's considered choice.
     */
    public static function fallback(): self
    {
        $defaults = config('visitdasol.payment_policy', []);

        return (new self)->forceFill([
            'requirement' => $defaults['requirement'] ?? 'deposit',
            'deposit_percent' => (int) ($defaults['deposit_percent'] ?? 30),
            'deadline_hours' => (int) ($defaults['deadline_hours'] ?? 24),
            'deadline_reference' => $defaults['deadline_reference'] ?? 'confirmation',
            'last_minute_rule' => $defaults['last_minute_rule'] ?? 'full',
            'last_minute_hours' => (int) ($defaults['last_minute_hours'] ?? 48),
            'allow_on_site' => (bool) ($defaults['allow_on_site'] ?? true),
            'version' => 0,
        ]);
    }

    /** Whether an owner has actually chosen these terms. */
    public function isCustomised(): bool
    {
        return $this->exists;
    }
}
