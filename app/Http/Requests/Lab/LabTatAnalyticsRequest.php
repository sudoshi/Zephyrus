<?php

declare(strict_types=1);

namespace App\Http\Requests\Lab;

use App\Models\Lab\LabTestCatalog;
use App\Services\Lab\LabTatAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LabTatAnalyticsRequest extends FormRequest
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
            'priority' => ['sometimes', 'nullable', 'string', Rule::in(LabTatAnalyticsService::PRIORITIES)],
            'testFamily' => [
                'sometimes', 'nullable', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/',
                Rule::exists(LabTestCatalog::class, 'test_family')
                    ->where(fn ($query) => $query->where('is_active', true)->whereNotIn('department', ['microbiology', 'pathology', 'blood_bank'])),
            ],
            'patientClass' => ['sometimes', 'nullable', 'string', Rule::in(LabTatAnalyticsService::PATIENT_CLASSES)],
            'shift' => ['sometimes', 'nullable', 'string', Rule::in(LabTatAnalyticsService::SHIFTS)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.LabTatAnalyticsService::MAX_LIMIT],
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
                if ($start->diffInDays($end) >= LabTatAnalyticsService::MAX_RANGE_DAYS) {
                    $validator->errors()->add('dateTo', 'The Laboratory TAT study range may not exceed '.LabTatAnalyticsService::MAX_RANGE_DAYS.' inclusive days.');
                }
            } catch (\Throwable) {
                // The date_format rules own malformed-date messages.
            }
        });
    }
}
