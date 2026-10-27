<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Owner\ResortRequest;

/**
 * The office edits the same resort fields an owner can, with the same rules.
 * Status, owner, and the review fields are not part of this form: those
 * change only through the approve, reject, activate, and deactivate actions.
 */
class UpdateResortRequest extends ResortRequest
{
    //
}
