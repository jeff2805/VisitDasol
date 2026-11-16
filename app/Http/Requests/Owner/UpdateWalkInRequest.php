<?php

namespace App\Http\Requests\Owner;

/**
 * Correcting a walk-in the resort still owns.
 *
 * The same shape as recording one, because a correction is the same record
 * written again. Whether this record may be corrected AT ALL is a question
 * about its submission status, not about the fields — BookingPolicy answers
 * it before the controller gets here, and WalkInBookingService asks it again
 * under the row lock.
 */
class UpdateWalkInRequest extends StoreWalkInRequest
{
}
