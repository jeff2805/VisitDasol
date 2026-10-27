<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EnvironmentalFeeCheckpointRequest;
use App\Models\EnvironmentalFeeCheckpoint;
use App\Models\EnvironmentalFeeCollector;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The places the municipality collects at, and who may collect there.
 *
 * Checkpoints are named one at a time. Nothing here walks the resort
 * list or the barangay list turning them into toll points: a
 * municipality collects where an ordinance says, and that is usually a
 * handful of roads, not everywhere the portal happens to know about.
 */
class EnvironmentalFeeCheckpointController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function index(): View
    {
        $this->authorize('viewAny', EnvironmentalFeeCheckpoint::class);

        return view('admin.environmental-fee.checkpoints.index', [
            'checkpoints' => EnvironmentalFeeCheckpoint::query()
                ->withCount([
                    'assignments as collector_count' => fn ($query) => $query->where('active', true),
                    'collections as collection_count' => fn ($query) => $query->counted(),
                ])
                ->orderBy('barangay')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', EnvironmentalFeeCheckpoint::class);

        return view('admin.environmental-fee.checkpoints.create', [
            'checkpoint' => new EnvironmentalFeeCheckpoint,
        ]);
    }

    public function store(EnvironmentalFeeCheckpointRequest $request): RedirectResponse
    {
        $this->authorize('create', EnvironmentalFeeCheckpoint::class);

        $checkpoint = new EnvironmentalFeeCheckpoint($request->validated());
        $checkpoint->createdBy()->associate($request->user());
        $checkpoint->save();

        $this->logger->log(
            'environmental_fee.checkpoint_created',
            $checkpoint,
            "Added the checkpoint {$checkpoint->name}",
            [
                'barangay' => $checkpoint->barangay,
                'latitude' => $checkpoint->latitude,
                'longitude' => $checkpoint->longitude,
            ],
        );

        return redirect()->route('admin.environmental-fee.checkpoints.show', $checkpoint)
            ->with('success', "{$checkpoint->name} has been added. Assign a collector to it next.");
    }

    public function show(EnvironmentalFeeCheckpoint $checkpoint): View
    {
        $this->authorize('update', $checkpoint);

        return view('admin.environmental-fee.checkpoints.show', [
            'checkpoint' => $checkpoint->load([
                'assignments.user:id,first_name,last_name,email,role',
                'assignments.assignedBy:id,first_name,last_name',
            ]),

            /*
             * Who may be appointed. Tourists are left out: a visitor is
             * who the fee is collected FROM. Beyond that the office
             * chooses, because this project does not know how Dasol
             * staffs its gates and will not guess.
             */
            'candidates' => User::query()
                ->whereIn('role', [
                    UserRole::SuperAdmin->value,
                    UserRole::BarangayOfficial->value,
                ])
                ->whereNotIn('id', $checkpoint->assignments()->pluck('user_id'))
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name', 'role', 'barangay']),
        ]);
    }

    public function edit(EnvironmentalFeeCheckpoint $checkpoint): View
    {
        $this->authorize('update', $checkpoint);

        return view('admin.environmental-fee.checkpoints.edit', [
            'checkpoint' => $checkpoint,
        ]);
    }

    public function update(
        EnvironmentalFeeCheckpointRequest $request,
        EnvironmentalFeeCheckpoint $checkpoint,
    ): RedirectResponse {
        $this->authorize('update', $checkpoint);

        $checkpoint->fill($request->validated());
        $changed = array_keys($checkpoint->getDirty());
        $checkpoint->save();

        $this->logger->log(
            'environmental_fee.checkpoint_updated',
            $checkpoint,
            "Updated the checkpoint {$checkpoint->name}",
            ['fields' => $changed],
        );

        return redirect()->route('admin.environmental-fee.checkpoints.show', $checkpoint)
            ->with('success', "{$checkpoint->name} has been saved.");
    }

    /**
     * Open or close a gate.
     *
     * Never a delete: a checkpoint that has taken money is part of the
     * record of where that money came from.
     */
    public function status(EnvironmentalFeeCheckpoint $checkpoint): RedirectResponse
    {
        $this->authorize('update', $checkpoint);

        $wasActive = $checkpoint->active;
        $checkpoint->forceFill(['active' => ! $wasActive])->save();

        $this->logger->logStatusChange(
            $wasActive
                ? 'environmental_fee.checkpoint_deactivated'
                : 'environmental_fee.checkpoint_activated',
            $checkpoint,
            ($wasActive ? 'Closed ' : 'Opened ').$checkpoint->name,
            $wasActive ? 'active' : 'inactive',
            $wasActive ? 'inactive' : 'active',
        );

        return back()->with('success', $wasActive
            ? "{$checkpoint->name} is closed. Nothing more can be collected there."
            : "{$checkpoint->name} is open for collection again.");
    }

    /* Collectors ---------------------------------------------------------- */

    public function assign(
        Request $request,
        EnvironmentalFeeCheckpoint $checkpoint,
    ): RedirectResponse {
        $this->authorize('assignCollectors', $checkpoint);

        $data = $request->validate([
            'user_id' => [
                'required',
                Rule::exists('users', 'id')->whereIn('role', [
                    UserRole::SuperAdmin->value,
                    UserRole::BarangayOfficial->value,
                ]),
            ],
        ], [
            'user_id.exists' => 'Choose somebody who can be given a municipal collection duty.',
        ]);

        $user = User::findOrFail($data['user_id']);

        EnvironmentalFeeCollector::query()->updateOrCreate(
            ['checkpoint_id' => $checkpoint->id, 'user_id' => $user->id],
            ['active' => true, 'assigned_by' => $request->user()->id],
        );

        $this->logger->log(
            'environmental_fee.collector_assigned',
            $checkpoint,
            "Authorised {$user->full_name} to collect at {$checkpoint->name}",
            ['user_id' => $user->id, 'checkpoint' => $checkpoint->name],
        );

        return back()->with('success', "{$user->full_name} can now collect at {$checkpoint->name}.");
    }

    /**
     * Withdraw somebody's authority at one gate.
     *
     * The row stays, deactivated: collections point at the collector,
     * and the record of who took the money has to outlive the posting.
     */
    public function withdraw(
        EnvironmentalFeeCheckpoint $checkpoint,
        EnvironmentalFeeCollector $assignment,
    ): RedirectResponse {
        $this->authorize('assignCollectors', $checkpoint);

        abort_if((int) $assignment->checkpoint_id !== (int) $checkpoint->id, 404);

        $assignment->forceFill(['active' => false])->save();
        $assignment->load('user');

        $this->logger->log(
            'environmental_fee.collector_withdrawn',
            $checkpoint,
            "Withdrew {$assignment->user->full_name} from {$checkpoint->name}",
            ['user_id' => $assignment->user_id, 'checkpoint' => $checkpoint->name],
        );

        return back()->with(
            'success',
            "{$assignment->user->full_name} can no longer collect at {$checkpoint->name}."
        );
    }
}
