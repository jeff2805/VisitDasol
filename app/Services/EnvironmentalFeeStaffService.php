<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\EnvironmentalFeeCollector;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Creating and amending the people who work the environmental fee.
 *
 * TWO THINGS THIS NEVER DOES.
 *
 * It never stores a password anybody chose for somebody else: an
 * account is created with a random secret that is generated, hashed
 * and thrown away in the same breath, and the person sets their own
 * through an expiring link. Nothing plaintext is written and nothing
 * permanent is emailed.
 *
 * It never deletes anything. A staff account that is finished with is
 * deactivated, because the receipts they wrote, the handovers they
 * made and the audit entries naming them all point at this row and
 * must go on pointing at it.
 */
class EnvironmentalFeeStaffService
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * Create an account and put the person on their checkpoints.
     *
     * @param  array{first_name: string, last_name: string, email: string, phone?: ?string, employee_id?: ?string, role: string}  $data
     * @param  list<int>  $checkpoints
     */
    public function create(User $office, array $data, array $checkpoints = []): User
    {
        $role = UserRole::from($data['role']);

        return DB::transaction(function () use ($office, $data, $role, $checkpoints): User {
            $staff = new User([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,

                /*
                 * Random, hashed by the model's cast, and never read
                 * back. It exists only because the column is NOT NULL;
                 * nobody holds it, including the office.
                 */
                'password' => Str::random(64),
            ]);

            $staff->forceFill([
                'role' => $role,
                'employee_id' => $data['employee_id'] ?? null,

                /*
                 * Active from the start. The office has just vouched
                 * for this person by typing their details in, so there
                 * is nothing left to approve — and the invitation link
                 * is what proves the address was typed correctly.
                 */
                'status' => UserStatus::Active,

                // NOT marked confirmed. Setting a password through the
                // emailed link is what confirms the address.
                'email_verified_at' => null,
            ])->save();

            $this->syncCheckpoints($office, $staff, $checkpoints, logEach: false);

            $this->logger->log(
                'environmental_fee.staff_created',
                $staff,
                "Created a {$role->label()} account for {$staff->full_name}",
                [
                    'role' => $role->value,
                    'employee_id' => $staff->employee_id,
                    'checkpoints' => count($checkpoints),
                ],
                $office,
            );

            return $staff;
        });
    }

    /**
     * Change a staff member's details, post or checkpoints.
     *
     * @param  array{first_name: string, last_name: string, email: string, phone?: ?string, employee_id?: ?string, role: string}  $data
     * @param  list<int>  $checkpoints
     */
    public function update(User $office, User $staff, array $data, array $checkpoints = []): User
    {
        $role = UserRole::from($data['role']);
        $wasRole = $staff->role;

        return DB::transaction(function () use ($office, $staff, $data, $role, $wasRole, $checkpoints): User {
            $staff->fill([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
            ]);

            $staff->forceFill([
                'role' => $role,
                'employee_id' => $data['employee_id'] ?? null,
            ])->save();

            /*
             * A change of post is the entry an auditor looks for, so it
             * gets its own line rather than being folded into a general
             * "details edited".
             */
            if ($wasRole !== $role) {
                $this->logger->logStatusChange(
                    'environmental_fee.staff_role_changed',
                    $staff,
                    "Changed {$staff->full_name} from {$wasRole->label()} to {$role->label()}",
                    $wasRole->value,
                    $role->value,
                    [],
                    $office,
                );
            }

            $this->syncCheckpoints($office, $staff, $checkpoints);

            return $staff->refresh();
        });
    }

    /**
     * Put somebody on exactly these checkpoints, and no others.
     *
     * Existing rows are switched off rather than deleted: a collection
     * points at the collector, and the record of who was posted where
     * on a given day is part of how a disputed receipt gets settled.
     *
     * @param  list<int>  $checkpoints
     */
    public function syncCheckpoints(
        User $office,
        User $staff,
        array $checkpoints,
        bool $logEach = true,
    ): void {
        $wanted = array_values(array_unique(array_map('intval', $checkpoints)));

        $current = EnvironmentalFeeCollector::query()
            ->where('user_id', $staff->id)
            ->get();

        $live = $current->where('active', true)->pluck('checkpoint_id')->map(fn ($id) => (int) $id);

        // Taken off a gate.
        foreach ($current->where('active', true) as $assignment) {
            if (! in_array((int) $assignment->checkpoint_id, $wanted, true)) {
                $assignment->forceFill(['active' => false])->save();

                if ($logEach) {
                    $this->logger->log(
                        'environmental_fee.staff_checkpoint_withdrawn',
                        $staff,
                        "Took {$staff->full_name} off {$assignment->checkpoint?->name}",
                        ['checkpoint_id' => (int) $assignment->checkpoint_id],
                        $office,
                    );
                }
            }
        }

        // Put on a gate, reviving a withdrawn row rather than stacking
        // a second one beside it.
        foreach ($wanted as $checkpointId) {
            if ($live->contains($checkpointId)) {
                continue;
            }

            $assignment = $current->firstWhere('checkpoint_id', $checkpointId);

            if ($assignment !== null) {
                $assignment->forceFill([
                    'active' => true,
                    'assigned_by' => $office->id,
                ])->save();
            } else {
                $assignment = EnvironmentalFeeCollector::query()->create([
                    'checkpoint_id' => $checkpointId,
                    'user_id' => $staff->id,
                    'assigned_by' => $office->id,
                    'active' => true,
                ]);
            }

            if ($logEach) {
                $this->logger->log(
                    'environmental_fee.staff_checkpoint_assigned',
                    $staff,
                    "Put {$staff->full_name} on {$assignment->checkpoint?->name}",
                    ['checkpoint_id' => $checkpointId],
                    $office,
                );
            }
        }
    }

    /**
     * Send the link that lets somebody set their first password.
     *
     * Laravel's own password broker, so the token is hashed in the
     * database, single-use, and expires on its own after
     * config('auth.passwords.users.expire') minutes — an hour as this
     * project is configured. Nothing about the password travels by
     * email; only a link that lets them choose one.
     *
     * Returns the broker's status string so the caller can tell a
     * throttled request apart from a sent one.
     */
    public function invite(User $office, User $staff): string
    {
        $status = Password::broker()->sendResetLink(['email' => $staff->email]);

        if ($status === Password::RESET_LINK_SENT) {
            $this->logger->log(
                'environmental_fee.staff_invited',
                $staff,
                "Sent {$staff->full_name} a link to set their password",
                [
                    // The address, because which mailbox it went to is
                    // the whole question if it never arrives. Never a
                    // token: the audit trail is read by people who
                    // should not be able to take over an account.
                    'email' => $staff->email,
                    'expires_in_minutes' => (int) config('auth.passwords.users.expire', 60),
                ],
                $office,
            );
        }

        return $status;
    }
}
