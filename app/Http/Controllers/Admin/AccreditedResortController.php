<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccreditationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccreditationUploadRequest;
use App\Http\Requests\Admin\UpdateAccreditedResortRequest;
use App\Models\AccreditedResort;
use App\Services\AccreditationImportService;
use App\Services\AccreditationMatcher;
use App\Services\ActivityLogger;
use App\Support\TableSort;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The Municipal Tourism Office's accreditation register.
 *
 * Uploading is two steps — read, then commit — so the office sees what
 * a file would do before it does it. The uploaded file waits on the
 * private disk in between; nothing is written to the register until the
 * second step is confirmed.
 */
class AccreditedResortController extends Controller
{
    /** Where an uploaded file waits between preview and confirmation. */
    private const STAGING = 'accreditation-imports';

    public function __construct(
        private readonly AccreditationImportService $importer,
        private readonly AccreditationMatcher $matcher,
        private readonly ActivityLogger $logger,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AccreditedResort::class);

        $sort = TableSort::fromRequest($request, [
            'resort' => 'resort_name',
            'barangay' => 'barangay',
            'number' => 'accreditation_no',
            'valid' => '-valid_until',
            'added' => '-created_at',
        ], 'resort');

        $status = AccreditationStatus::tryFrom((string) $request->query('status'));

        $query = AccreditedResort::query()
            ->search($request->query('q'))
            ->when($status, fn ($q) => $q->where('accreditation_status', $status->value));

        $records = $sort->apply($query)->paginate(20)->withQueryString();

        return view('admin.accredited-resorts.index', [
            'records' => $records,
            'sort' => $sort,
            'search' => (string) $request->query('q'),
            'status' => $status,
            'total' => AccreditedResort::count(),
            'current' => AccreditedResort::current()->count(),
            'statuses' => AccreditationStatus::options(),
        ]);
    }

    /**
     * Reads the uploaded file and shows what importing it would do.
     *
     * Nothing is written here. The file is kept on the private disk
     * under a random name and named back on the confirm step, so the
     * rows the office approved are the rows that get imported — rather
     * than a second upload sneaking in between the two screens.
     */
    public function preview(AccreditationUploadRequest $request): View|RedirectResponse
    {
        $this->authorize('create', AccreditedResort::class);

        $this->sweepStaging();

        $upload = $request->file('file');
        $token = Str::uuid()->toString();
        $extension = strtolower($upload->getClientOriginalExtension());

        $upload->storeAs(self::STAGING, $token.'.'.$extension, 'local');
        $path = Storage::disk('local')->path(self::STAGING.'/'.$token.'.'.$extension);

        try {
            $preview = $this->importer->read($path, $extension);
        } catch (RuntimeException $e) {
            Storage::disk('local')->delete(self::STAGING.'/'.$token.'.'.$extension);

            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return view('admin.accredited-resorts.preview', [
            'preview' => $preview,
            'token' => $token,
            'extension' => $extension,
            'filename' => $upload->getClientOriginalName(),
        ]);
    }

    /**
     * Writes the previewed rows, then re-checks waiting applications.
     *
     * The re-check is the point of the upload: an office that has just
     * added a resort expects that resort's pending application to stop
     * saying "not listed" without anyone having to apply again.
     */
    public function import(Request $request): RedirectResponse
    {
        $this->authorize('create', AccreditedResort::class);

        $validated = $request->validate([
            'token' => ['required', 'string', 'uuid'],
            'extension' => ['required', 'string', 'in:xlsx,csv,txt'],
            'filename' => ['required', 'string', 'max:255'],
        ]);

        $relative = self::STAGING.'/'.$validated['token'].'.'.$validated['extension'];

        if (! Storage::disk('local')->exists($relative)) {
            return redirect()
                ->route('admin.accredited-resorts.index')
                ->withErrors(['file' => 'That upload has expired. Please choose the file again.']);
        }

        try {
            $preview = $this->importer->read(
                Storage::disk('local')->path($relative),
                $validated['extension'],
            );
        } catch (RuntimeException $e) {
            Storage::disk('local')->delete($relative);

            return redirect()
                ->route('admin.accredited-resorts.index')
                ->withErrors(['file' => $e->getMessage()]);
        }

        $result = $this->importer->commit($preview, $request->user(), $validated['filename']);

        Storage::disk('local')->delete($relative);

        $rechecked = $this->matcher->recheckPending();

        return redirect()
            ->route('admin.accredited-resorts.index')
            ->with('success', $this->summarise($result, $rechecked));
    }

    public function update(UpdateAccreditedResortRequest $request, AccreditedResort $accreditedResort): RedirectResponse
    {
        $this->authorize('update', $accreditedResort);

        $accreditedResort->fill($request->validated())->save();

        $this->logger->log(
            'accreditation.updated',
            $accreditedResort,
            "Updated the accreditation record for {$accreditedResort->resort_name}",
        );

        // The correction may change who matches, and a waiting
        // application showing a finding from before it is worse than
        // no finding at all.
        $this->matcher->recheckPending();

        return back()->with('success', "{$accreditedResort->resort_name} has been updated.");
    }

    public function destroy(AccreditedResort $accreditedResort): RedirectResponse
    {
        $this->authorize('delete', $accreditedResort);

        $name = $accreditedResort->resort_name;

        /*
         * Applications matched to this row keep their finding: the
         * foreign key is nulled rather than cascaded, and the
         * accreditation number was copied onto the application when the
         * match was made. Removing a resort from the register must not
         * rewrite the history of a decision already taken.
         */
        $accreditedResort->delete();

        $this->logger->log(
            'accreditation.deleted',
            null,
            "Removed {$name} from the accredited resorts list",
            ['resort_name' => $name],
        );

        $this->matcher->recheckPending();

        return back()->with('success', "{$name} has been removed from the list.");
    }

    /* Internals ---------------------------------------------------------- */

    /**
     * @param  array{created: int, updated: int, skipped: int}  $result
     */
    private function summarise(array $result, int $rechecked): string
    {
        $parts = [];
        $parts[] = $result['created'].' '.Str::plural('record', $result['created']).' added';
        $parts[] = $result['updated'].' updated';

        if ($result['skipped'] > 0) {
            $parts[] = $result['skipped'].' skipped';
        }

        $summary = 'Accredited resorts list imported: '.implode(', ', $parts).'.';

        if ($rechecked > 0) {
            $summary .= ' '.$rechecked.' waiting '.Str::plural('application', $rechecked)
                .' '.($rechecked === 1 ? 'was' : 'were').' checked against the new list.';
        }

        return $summary;
    }

    /**
     * Clears staged uploads nobody confirmed.
     *
     * Done here rather than on a schedule because this is the only
     * place that creates them, and a file abandoned at the preview
     * screen would otherwise sit on disk indefinitely.
     */
    private function sweepStaging(): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->files(self::STAGING) as $file) {
            if ($disk->lastModified($file) < now()->subDay()->getTimestamp()) {
                $disk->delete($file);
            }
        }
    }
}
