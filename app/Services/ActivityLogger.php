<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes the audit trail (activity_logs).
 *
 *   $logger->log('owner.approved', $owner, 'Approved the owner account', ['from' => 'pending', 'to' => 'active']);
 *
 * Never pass passwords, tokens, or file contents in $properties.
 * Entries are never updated or deleted by the application.
 */
class ActivityLogger
{
    /** Keys that must never be stored, even if a caller passes them by mistake. */
    private const FORBIDDEN_KEYS = ['password', 'password_confirmation', 'current_password', 'remember_token', 'token', 'secret'];

    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(string $action, ?Model $subject = null, string $description = '', array $properties = [], ?User $actor = null): ActivityLog
    {
        $actor ??= Auth::user();

        return ActivityLog::create([
            'user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'description' => $description !== '' ? $description : $action,
            'properties' => $this->clean($properties) ?: null,
            'ip_address' => Request::ip(),
        ]);
    }

    /**
     * Convenience helper for status changes.
     */
    public function logStatusChange(string $action, Model $subject, string $description, string $from, string $to, array $extra = []): ActivityLog
    {
        return $this->log($action, $subject, $description, ['from' => $from, 'to' => $to] + $extra);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function clean(array $properties): array
    {
        foreach (array_keys($properties) as $key) {
            if (in_array(mb_strtolower((string) $key), self::FORBIDDEN_KEYS, true)) {
                unset($properties[$key]);
            }
        }

        return $properties;
    }
}
