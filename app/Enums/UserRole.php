<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * The account roles. Stored in users.role.
 *
 * A role says what post somebody holds. It does NOT say where they
 * work: which gate a collector may take money at is an assignment row
 * in environmental_fee_collectors, checked by
 * EnvironmentalFeeCheckpointPolicy::collectAt(). Keeping the two apart
 * is what lets one person be moved between checkpoints without being
 * given a new account.
 *
 * Only the Municipal Tourism Office creates the staff roles below, from
 * the staff screen. Nothing public can reach them: tourists and resort
 * owners come in through their own registration, which can only ever
 * produce Tourist or ResortOwner.
 */
enum UserRole: string
{
    use EnumHelpers;

    case Tourist = 'tourist';
    case ResortOwner = 'resort_owner';
    case BarangayOfficial = 'barangay_official';

    /** Takes the environmental fee at a checkpoint. */
    case FeeCollector = 'fee_collector';

    /** Counts what the gates hand in, and reads the municipal figures. */
    case Cashier = 'cashier';

    case SuperAdmin = 'super_admin';

    public function label(): string
    {
        return match ($this) {
            self::Tourist => 'Tourist',
            self::ResortOwner => 'Resort owner',
            self::BarangayOfficial => 'Barangay official',
            self::FeeCollector => 'Environmental fee collector',
            self::Cashier => 'Cashier / finance staff',
            self::SuperAdmin => 'MTO administrator',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Tourist => 'Books rooms and writes reviews.',
            self::ResortOwner => 'Lists and runs one resort.',
            self::BarangayOfficial => 'Writes up destinations for their barangay.',
            self::FeeCollector => 'Records the environmental fee at the checkpoints '
                .'they are assigned to, and hands the takings in.',
            self::Cashier => 'Counts what collectors hand in and reads the municipal '
                .'figures. Cannot set the fee or create accounts.',
            self::SuperAdmin => 'Runs the whole site, including the fee and its staff.',
        };
    }

    /**
     * The roles the office creates for environmental fee work.
     *
     * Deliberately excludes Tourist and ResortOwner — those are public
     * registrations, and a staff screen that could mint one would be a
     * way to create an account nobody applied for.
     *
     * @return list<self>
     */
    public static function environmentalFeeStaff(): array
    {
        return [self::FeeCollector, self::Cashier, self::SuperAdmin];
    }

    /**
     * value => label for the staff form's role menu.
     *
     * @return array<string, string>
     */
    public static function staffOptions(): array
    {
        $options = [];

        foreach (self::environmentalFeeStaff() as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }

    /** Whether this post works the environmental fee at all. */
    public function isEnvironmentalFeeStaff(): bool
    {
        return in_array($this, self::environmentalFeeStaff(), true);
    }

    /** Whether a checkpoint assignment means anything for this post. */
    public function takesCheckpoints(): bool
    {
        return $this === self::FeeCollector;
    }
}
