<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Any account: tourist, resort owner, or MTO (super admin).
 *
 * role and status are NOT mass assignable. Set them explicitly:
 *     $user->role = UserRole::ResortOwner;
 *     $user->status = UserStatus::Pending;
 *
 * @property UserRole $role
 * @property UserStatus $status
 * @property-read string $full_name
 * @property-read string $initials
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'home_city',
        'home_country',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * New, unsaved users are pending tourists until code says otherwise.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'tourist',
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /* ------------------------------------------------------------------
     | Relationships
     * ------------------------------------------------------------------ */

    public function ownerProfile(): HasOne
    {
        return $this->hasOne(OwnerProfile::class);
    }

    /** All resorts owned by this user (the one-resort rule is a policy). */
    public function resorts(): HasMany
    {
        return $this->hasMany(Resort::class, 'owner_id');
    }

    /**
     * The owner's resort (the first one, under the one-resort rule).
     *
     * Reached as $user->resort()->first() throughout, not $user->resort.
     *
     * Both work for the signed-in user, because Model::shouldBeStrict() only
     * arms its lazy-loading guard on models that came back from a query of more
     * than one row (Builder::hydrate), and the session fetches one. But a User
     * taken out of a list IS armed, and then the dynamic property throws. The
     * query form costs the same and does not depend on knowing that rule. The
     * same goes for ownerProfile() above.
     */
    public function resort(): HasOne
    {
        return $this->hasOne(Resort::class, 'owner_id')->oldestOfMany();
    }

    /** Reservations made by this user as a tourist. */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /** Google or Facebook accounts that sign in as this user. */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** Destinations this account wrote. */
    public function submittedSpots(): HasMany
    {
        return $this->hasMany(Destination::class, 'created_by');
    }

    /* ------------------------------------------------------------------
     | Accessors
     * ------------------------------------------------------------------ */

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name.' '.$this->last_name));
    }

    protected function initials(): Attribute
    {
        return Attribute::get(fn (): string => mb_strtoupper(
            mb_substr((string) $this->first_name, 0, 1).mb_substr((string) $this->last_name, 0, 1)
        ));
    }

    /* ------------------------------------------------------------------
     | Role and status helpers
     * ------------------------------------------------------------------ */

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isTourist(): bool
    {
        return $this->role === UserRole::Tourist;
    }

    public function isResortOwner(): bool
    {
        return $this->role === UserRole::ResortOwner;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /* ------------------------------------------------------------------
     | Query scopes
     * ------------------------------------------------------------------ */

    public function scopeWithRole(Builder $query, UserRole $role): void
    {
        $query->where('role', $role->value);
    }

    public function scopeWithStatus(Builder $query, UserStatus $status): void
    {
        $query->where('status', $status->value);
    }

    public function scopeTourists(Builder $query): void
    {
        $query->withRole(UserRole::Tourist);
    }

    public function scopeResortOwners(Builder $query): void
    {
        $query->withRole(UserRole::ResortOwner);
    }

    public function scopeActive(Builder $query): void
    {
        $query->withStatus(UserStatus::Active);
    }

    /** Owner applications waiting for MTO review. */
    public function scopePendingOwners(Builder $query): void
    {
        $query->resortOwners()->withStatus(UserStatus::Pending);
    }
}
