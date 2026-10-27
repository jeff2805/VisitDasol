<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Http\Requests\Concerns\NormalizesUserInput;
use App\Models\EnvironmentalFeeCheckpoint;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The office creating or amending an environmental fee staff account.
 *
 * NO PASSWORD FIELD, DELIBERATELY. The office never chooses somebody
 * else's password and never sees one: the account is created with an
 * unusable random secret and the person sets their own through an
 * expiring link. Nothing plaintext is stored and nothing permanent is
 * emailed.
 *
 * The role menu is built from UserRole::staffOptions(), which lists
 * only the three fee posts. Tourist and ResortOwner are not in it and
 * are refused by the rule below even if posted by hand — a staff
 * screen that could mint a resort owner would be a way to create a
 * business account nobody applied for.
 */
class EnvironmentalFeeStaffRequest extends FormRequest
{
    use NormalizesUserInput;

    public function authorize(): bool
    {
        // The controller authorizes against UserPolicy::createStaff /
        // updateStaff. Both run on the server.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeUserInput();

        $clean = [];

        if (is_string($this->input('employee_id'))) {
            $clean['employee_id'] = strtoupper(trim($this->input('employee_id'))) ?: null;
        }

        /*
         * Checkpoints are NOT silently cleared for a non-collector,
         * though that was the first instinct. Clearing them here would
         * defeat the rule in withValidator() that refuses the
         * combination, and the office would see a cashier saved with
         * their checkpoints quietly dropped instead of being told the
         * two do not go together.
         *
         * The controller is where an accepted non-collector ends up
         * with no assignments, because by then the pairing has been
         * approved rather than hidden.
         */
        $this->merge($clean);
    }

    /** The account being edited, or null when creating. */
    public function staff(): ?User
    {
        $staff = $this->route('staff');

        return $staff instanceof User ? $staff : null;
    }

    /**
     * The checkpoints to actually save.
     *
     * Empty for any post that does not stand at a gate. By the time
     * this is called the validator has already refused the pairing, so
     * this is belt and braces rather than the rule.
     *
     * @return list<int>
     */
    public function checkpointIds(): array
    {
        $role = UserRole::tryFrom((string) $this->validated('role'));

        if ($role?->takesCheckpoints() !== true) {
            return [];
        }

        return array_values(array_map('intval', $this->validated('checkpoints', [])));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $staff = $this->staff();

        return [
            ...$this->nameRules(),

            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($staff?->id),
            ],

            'phone' => ['nullable', ...$this->phoneRule()],

            /*
             * The municipality's own reference. Optional because the
             * office may not have one to hand, unique because issuing
             * the same number twice is the only thing that could go
             * wrong with it.
             */
            'employee_id' => [
                'nullable', 'string', 'max:40',
                Rule::unique('users', 'employee_id')->ignore($staff?->id),
            ],

            /*
             * THE RULE THAT STOPS PRIVILEGE ESCALATION BY POST.
             *
             * Only the three fee posts are acceptable. Anything else —
             * tourist, resort_owner, or a value invented in the
             * browser — is refused here, before the controller ever
             * sees it.
             */
            'role' => ['required', Rule::in(array_keys(UserRole::staffOptions()))],

            'checkpoints' => ['array'],
            'checkpoints.*' => [
                'integer',
                Rule::exists('environmental_fee_checkpoints', 'id'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $role = UserRole::tryFrom((string) $this->input('role'));
            $checkpoints = (array) $this->input('checkpoints', []);

            /*
             * A cashier or an administrator standing at a barrier is
             * not a thing this system models. Refusing it here keeps
             * "who may collect" answerable from the role alone.
             */
            if ($checkpoints !== [] && $role?->takesCheckpoints() !== true) {
                $validator->errors()->add(
                    'checkpoints',
                    'Only a collector is assigned to checkpoints. Change the role, or clear the checkpoints.'
                );

                return;
            }

            // A gate the office has switched off cannot be staffed.
            if ($checkpoints !== []) {
                $inactive = EnvironmentalFeeCheckpoint::query()
                    ->whereIn('id', $checkpoints)
                    ->where('active', false)
                    ->pluck('name');

                if ($inactive->isNotEmpty()) {
                    $validator->errors()->add(
                        'checkpoints',
                        'These checkpoints are switched off: '.$inactive->implode(', ')
                        .'. Turn one on before putting somebody on it.'
                    );
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'An account with that email address already exists.',
            'employee_id.unique' => 'That employee number belongs to another account.',
            'role.required' => 'Choose what this person does.',
            'role.in' => 'Choose one of the environmental fee roles.',
            'checkpoints.*.exists' => 'One of those checkpoints does not exist.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'employee_id' => 'employee number',
            'checkpoints' => 'checkpoints',
        ];
    }
}
