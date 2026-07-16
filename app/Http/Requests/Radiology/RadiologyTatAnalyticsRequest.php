<?php

declare(strict_types=1);

namespace App\Http\Requests\Radiology;

use App\Models\Radiology\Modality;
use App\Services\Radiology\RadiologyTatAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RadiologyTatAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dateFrom' => ['sometimes', 'date_format:Y-m-d'],
            'dateTo' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:dateFrom'],
            'priority' => ['sometimes', 'nullable', 'string', Rule::in(RadiologyTatAnalyticsService::PRIORITIES)],
            'modality' => ['sometimes', 'nullable', 'string', 'max:16', 'regex:/^[A-Z0-9_]+$/', Rule::exists(Modality::class, 'code')],
            'patientClass' => ['sometimes', 'nullable', 'string', Rule::in(RadiologyTatAnalyticsService::PATIENT_CLASSES)],
            'shift' => ['sometimes', 'nullable', 'string', Rule::in(RadiologyTatAnalyticsService::SHIFTS)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.RadiologyTatAnalyticsService::MAX_LIMIT],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $from = $this->input('dateFrom', now()->subDays(29)->toDateString());
            $to = $this->input('dateTo', now()->toDateString());
            if (! is_string($from) || ! is_string($to)
                || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
                || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                return;
            }
            try {
                $start = CarbonImmutable::createFromFormat('!Y-m-d', $from);
                $end = CarbonImmutable::createFromFormat('!Y-m-d', $to);
                if ($end->lessThan($start)) {
                    $validator->errors()->add('dateTo', 'The through date must be on or after the from date.');

                    return;
                }
                $days = $start->diffInDays($end);
                if ($days >= RadiologyTatAnalyticsService::MAX_RANGE_DAYS) {
                    $validator->errors()->add('dateTo', 'The Radiology TAT study range may not exceed '.RadiologyTatAnalyticsService::MAX_RANGE_DAYS.' inclusive days.');
                }
            } catch (\Throwable) {
                // The date_format rules own malformed-date messages.
            }
        });
    }
}
