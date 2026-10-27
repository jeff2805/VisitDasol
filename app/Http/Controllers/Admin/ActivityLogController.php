<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The audit trail: who did what, when, and from where.
 *
 * Entries are only ever written, never edited or deleted, so there is no
 * form on this page. It answers questions like "who approved this resort"
 * and "when was that account deactivated" months after the fact.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $group = $this->group($request->query('group'));
        $actor = User::query()->whereKey($request->query('user'))->first();
        $search = trim((string) $request->query('q', ''));
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));

        $logs = ActivityLog::query()
            ->with(['user:id,first_name,last_name,role', 'subject'])
            ->when($group, fn ($query) => $query->where('action', 'like', $group.'.%'))
            ->when($actor, fn ($query) => $query->where('user_id', $actor->id))
            ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->when($search !== '', fn ($query) => $query->where(function ($match) use ($search) {
                $match->where('description', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%");
            }))
            ->latest('created_at')
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.activity-logs.index', [
            'logs' => $logs,
            'group' => $group,
            'actor' => $actor,
            'search' => $search,
            'from' => $from,
            'to' => $to,
            'groups' => $this->availableGroups(),
            'actors' => User::query()
                ->whereIn('id', ActivityLog::query()->select('user_id')->whereNotNull('user_id')->distinct())
                ->orderBy('first_name')
                ->get(),
        ]);
    }

    /**
     * The parts of the system that actually appear in the log, so the
     * filter never offers an empty choice.
     *
     * @return list<string>
     */
    private function availableGroups(): array
    {
        return ActivityLog::query()
            ->selectRaw("SUBSTRING_INDEX(action, '.', 1) as grp")
            ->distinct()
            ->orderBy('grp')
            ->pluck('grp')
            ->all();
    }

    private function group(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-z_]+$/', $value) ? $value : null;
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
