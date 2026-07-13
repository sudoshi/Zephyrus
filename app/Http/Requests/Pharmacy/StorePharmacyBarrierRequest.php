<?php

namespace App\Http\Requests\Pharmacy;

use App\Models\Ancillary\AncillaryOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class StorePharmacyBarrierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manageAncillaryBarriers');
    }

    public function rules(): array
    {
        return [
            'orderUuid' => ['required', 'uuid', Rule::exists(AncillaryOrder::class, 'order_uuid')->where('department', 'rx')],
            'reasonCode' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! DB::table('hosp_ref.ancillary_barrier_reasons')->where('department', 'rx')->where('reason_code', $value)->where('is_active', true)->exists()) {
                    $fail('The selected Pharmacy barrier reason is not active.');
                }
            }],
            'description' => ['nullable', 'string', 'max:500'],
            'owner' => ['nullable', 'string', 'max:100'],
        ];
    }
}
