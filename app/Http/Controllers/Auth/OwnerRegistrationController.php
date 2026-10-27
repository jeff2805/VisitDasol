<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\OwnerRegistrationRequest;
use App\Models\OwnerProfile;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class OwnerRegistrationController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function create(): View
    {
        return view('auth.register-owner', [
            'positions' => array_combine(OwnerProfile::POSITIONS, OwnerProfile::POSITIONS),
        ]);
    }

    /**
     * Creates a PENDING resort owner account and its application profile.
     * The applicant is NOT signed in; the MTO must approve the account first.
     * The optional permit document is stored on the private disk.
     */
    public function store(OwnerRegistrationRequest $request): RedirectResponse
    {
        $storedPath = null;
        $owner = null;

        try {
            DB::transaction(function () use ($request, &$storedPath, &$owner): void {
                $owner = new User($request->safe()->only([
                    'first_name', 'last_name', 'email', 'phone', 'password',
                ]));
                $owner->role = UserRole::ResortOwner;
                $owner->status = UserStatus::Pending;
                $owner->save();

                $profile = new OwnerProfile($request->safe()->only([
                    'business_name', 'position', 'business_permit_no',
                ]));
                $profile->user()->associate($owner);

                if ($request->hasFile('permit_document')) {
                    $folder = config('visitdasol.uploads.folders.owner_documents').'/'.$owner->id;
                    $storedPath = $request->file('permit_document')->store($folder, 'local');
                    $profile->permit_document_path = $storedPath;
                }

                $profile->save();
            });
        } catch (Throwable $e) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $e;
        }

        $this->logger->log(
            'owner.applied',
            $owner ?? null,
            "Applied to list a resort: {$request->validated('business_name')}",
            [],
            // The applicant is not signed in, so they are named as the actor here.
            $owner ?? null,
        );

        return redirect()->route('register.owner.submitted')->with('application_submitted', true);
    }

    public function submitted(): View
    {
        return view('auth.owner-submitted');
    }
}
