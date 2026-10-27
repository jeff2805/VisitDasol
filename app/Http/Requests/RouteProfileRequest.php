<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The sampled shape of a route, sent up so its height profile can be worked
 * out.
 *
 * Bounded on purpose. This endpoint stands in front of somebody else's free
 * height service, so it answers about roads a visitor to Dasol might drive
 * and not about the whole planet: a hundred points at a time, inside a box
 * around the Philippines.
 */
class RouteProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Public, like the map it serves.
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'points' => ['required', 'array', 'min:2', 'max:100'],
            'points.*.lat' => ['required', 'numeric', 'between:4.0,22.0'],
            'points.*.lng' => ['required', 'numeric', 'between:115.0,128.0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'points.max' => 'That is more of the road than this can measure at once.',
            'points.*.lat.between' => 'That point is outside the Philippines.',
            'points.*.lng.between' => 'That point is outside the Philippines.',
        ];
    }

    /**
     * @return list<array{lat: float, lng: float}>
     */
    public function path(): array
    {
        return array_map(
            fn (array $point): array => ['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']],
            $this->validated('points'),
        );
    }
}
