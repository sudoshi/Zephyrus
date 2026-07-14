<?php

declare(strict_types=1);

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The controlled-substance operational view is a diversion-ADJACENT surface. It
 * is gated by the dedicated `viewControlledSubstanceOperations` capability
 * (deployment-governance restriction). An unauthorized user is denied here —
 * before the service ever runs — so no controlled data can leak in the response.
 */
final class PharmacyControlledRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewControlledSubstanceOperations') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // The view takes no client-supplied dimensions; it is aggregate-only.
        return [];
    }
}
