<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * The account roles. Stored in users.role.
 *
 * Three posts, and only three. Tourists and resort owners come in
 * through their own registration; the Municipal Tourism Office account
 * is made by the installer and never by a form.
 *
 * The barangay_official, fee_collector and cashier roles were removed
 * along with the environmental fee module and the barangay spot
 * submission queue. Accounts that held them were moved to Tourist
 * rather than deleted — see the migration that retired them.
 */
enum UserRole: string
{
    use EnumHelpers;

    case Tourist = 'tourist';
    case ResortOwner = 'resort_owner';
    case SuperAdmin = 'super_admin';

    public function label(): string
    {
        return match ($this) {
            self::Tourist => 'Tourist',
            self::ResortOwner => 'Resort owner',
            self::SuperAdmin => 'MTO administrator',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Tourist => 'Books rooms and writes reviews.',
            self::ResortOwner => 'Lists and runs one resort.',
            self::SuperAdmin => 'Runs the whole site.',
        };
    }
}
