<?php

namespace App\Http\Controllers;

use App\Exceptions\EnvironmentalFeeException;
use App\Models\EnvironmentalFeeAccount;
use App\Models\EnvironmentalFeeCheckpoint;
use App\Models\EnvironmentalFeeCollection;
use App\Services\EnvironmentalFeeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Recording a fee taken at a gate.
 *
 * Not under the admin prefix, because the people who work the gates are
 * not necessarily the tourism office. Authority comes from an assignment
 * row, checked by EnvironmentalFeeCollectionPolicy and again inside
 * EnvironmentalFeeService — the form, the policy and the service all ask
 * the same question, and the service's answer is the one that decides.
 */
class EnvironmentalFeeCollectionController extends Controller
{
    public function __construct(private readonly EnvironmentalFeeService $fees) {}

    /** The gates this person works, and what they have taken there. */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', EnvironmentalFeeCollection::class);

        $user = $request->user();

        $checkpoints = $user->isSuperAdmin()
            ? EnvironmentalFeeCheckpoint::query()->active()->orderBy('name')->get()
            : EnvironmentalFeeCheckpoint::query()->collectedBy($user)->orderBy('name')->get();

        return view('environmental-fee.index', [
            'checkpoints' => $checkpoints,

            // The live policy, so the screen can show the rate it would
            // charge rather than leaving a collector to guess.
            'policy' => $this->fees->activePolicy(),

            // Why nothing can be collected, when nothing can. The usual
            // answer while the module is new.
            'blocked' => $this->fees->blockedReason(),

            /*
             * The municipal GCash account, when the office has set one
             * up and switched it on. Null means cash only, and the form
             * does not offer a choice it cannot honour.
             */
            'gcash' => EnvironmentalFeeAccount::current()?->isUsable()
                ? EnvironmentalFeeAccount::current()
                : null,

            // Their own receipts. A collector has no business reading
            // the whole municipality's takings.
            'recent' => EnvironmentalFeeCollection::query()
                ->when(
                    ! $user->isSuperAdmin(),
                    fn ($query) => $query->where('collector_id', $user->id)
                )
                ->with(['checkpoint:id,name', 'collector:id,first_name,last_name'])
                ->latest('collected_at')
                ->limit(25)
                ->get(),
        ]);
    }

    /**
     * Write down a fee that was taken.
     *
     * The amount is NOT read from the form. The service works it out
     * from the policy in force and the quantity, so a collector cannot
     * decide what the municipality charges by editing a page.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', EnvironmentalFeeCollection::class);

        $data = $request->validate([
            'checkpoint_id' => ['required', 'exists:environmental_fee_checkpoints,id'],
            'receipt_number' => ['required', 'string', 'max:50'],
            'quantity' => ['required', 'integer', 'min:1', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],

            /*
             * Cash unless somebody says otherwise. It is the baseline
             * every gate can take, and the one the form sends when the
             * office has not set GCash up — so an absent value is an
             * answer, not an omission.
             */
            'payment_method' => ['nullable', 'in:cash,gcash'],

            // Only meaningful for a transfer, and then the office has
            // nothing to look up without it.
            'gcash_reference' => [
                'exclude_unless:payment_method,gcash',
                'required', 'string', 'max:80',
            ],
            'gcash_sender' => [
                'exclude_unless:payment_method,gcash',
                'nullable', 'string', 'max:120',
            ],
            'proof_image' => [
                'exclude_unless:payment_method,gcash',
                'nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120',
            ],
        ], [
            'receipt_number.required' => 'Enter the number on the receipt you issued.',
            'quantity.min' => 'A collection covers at least one.',
            'gcash_reference.required' =>
                'Enter the GCash reference number from the payer\'s receipt.',
            'proof_image.image' => 'Upload a photo or screenshot of the GCash receipt.',
        ]);

        /*
         * Kept off the PUBLIC disk. A payer's screenshot carries their
         * GCash account name and often their balance, and a guessable
         * path under /storage would hand that to anybody who asked.
         */
        if ($request->hasFile('proof_image')) {
            $data['proof_image'] = $request->file('proof_image')
                ->store('environmental-fee-proofs', 'local');
        }

        $checkpoint = EnvironmentalFeeCheckpoint::findOrFail($data['checkpoint_id']);

        // This gate, not merely some gate.
        $this->authorize('collectAt', $checkpoint);

        try {
            $collection = $this->fees->record($request->user(), $checkpoint, $data);
        } catch (EnvironmentalFeeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        /*
         * Worded by how the money came. Cash is in hand; a transfer is
         * a claim until the office checks the app, and telling a
         * collector "Recorded" for both would have them believe the
         * second had settled.
         */
        return redirect()->route('environmental-fee.index')->with(
            'success',
            $collection->isGcash()
                ? "Recorded {$collection->reference} — "
                    .\App\Support\Money::peso($collection->amount, true)
                    .' by GCash. The tourism office checks the transfer before it '
                    .'counts towards any total.'
                : "Recorded {$collection->reference} — "
                    .\App\Support\Money::peso($collection->amount, true)
                    ." in cash against receipt {$collection->receipt_number}."
        );
    }

    public function show(EnvironmentalFeeCollection $collection): View
    {
        $this->authorize('view', $collection);

        return view('environmental-fee.show', [
            'collection' => $collection->load([
                'checkpoint', 'collector:id,first_name,last_name',
                'policy', 'voidedBy:id,first_name,last_name', 'corrects',
            ]),
        ]);
    }

    /**
     * Cancel a receipt that should not stand. The office only.
     *
     * The row is marked, never removed: a collection that simply
     * vanished would be indistinguishable from one never written, which
     * is precisely the gap an audit trail exists to close.
     */
    public function void(
        Request $request,
        EnvironmentalFeeCollection $collection,
    ): RedirectResponse {
        $this->authorize('void', $collection);

        $data = $request->validate([
            'void_reason' => ['required', 'string', 'min:4', 'max:500'],
        ], [
            'void_reason.required' => 'Say why this collection is being voided.',
            'void_reason.min' => 'Give a reason somebody reading this later can understand.',
        ]);

        try {
            $this->fees->void($collection, $request->user(), $data['void_reason']);
        } catch (EnvironmentalFeeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'success',
            "{$collection->reference} has been voided. It no longer counts towards any total."
        );
    }
}
