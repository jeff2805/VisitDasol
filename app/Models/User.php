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
 * role, status and barangay are NOT mass assignable. Set them explicitly:
 *     $user->role = UserRole::ResortOwner;
 *     $user->status = UserStatus::Pending;
 *
 * barangay says which barangay an official speaks for. It is null for every
 * other role, where it would mean nothing.
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

    /**
     * Destinations this account wrote.
     *
     * Everything with created_by set, so it covers spots the office wrote as
     * well; in practice it is read for barangay officials, who have nothing
     * else here.
     */
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

    public function isBarangayOfficial(): bool
    {
        return $this->role === UserRole::BarangayOfficial;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    /**
     * The checkpoints this person has been put on.
     *
     * The assignment rows themselves, not the checkpoints, because the
     * row carries whether the assignment is still live and who made
     * it — both of which the staff screen shows.
     */
    public function feeAssignments(): HasMany
    {
        return $this->hasMany(EnvironmentalFeeCollector::class, 'user_id');
    }

    /** Takes the environmental fee at a checkpoint. */
    public function isFeeCollector(): bool
    {
        return $this->role === UserRole::FeeCollector;
    }

    /** Counts what the gates hand in, and reads the municipal figures. */
    public function isCashier(): bool
    {
        return $this->role === UserRole::Cashier;
    }

    /**
     * Any post that works the environmental fee.
     *
     * Says nothing about WHICH checkpoint — that is an assignment row,
     * checked by EnvironmentalFeeCheckpointPolicy::collectAt(). This
     * only answers "is this person fee staff at all".
     */
    public function isEnvironmentalFeeStaff(): bool
    {
        return $this->role->isEnvironmentalFeeStaff();
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

    public function scopeBarangayOfficials(Builder $query): void
    {
        $query->withRole(UserRole::BarangayOfficial);
    }

    /**
     * Everybody who works the environmental fee, whatever their post.
     *
     * Drawn from the enum rather than a hard-coded list, so a role
     * added there appears on the staff screen without this being
     * edited and quietly left behind.
     */
    public function scopeEnvironmentalFeeStaff(Builder $query): void
    {
        $query->whereIn('role', array_map(
            fn (UserRole $role) => $role->value,
            UserRole::environmentalFeeStaff()
        ));
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
