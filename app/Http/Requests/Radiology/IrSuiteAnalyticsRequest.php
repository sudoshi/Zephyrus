<?php

declare(strict_types=1);

namespace App\Http\Requests\Radiology;

use App\Models\Radiology\Scanner;
use App\Services\Radiology\IrSuiteAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IrSuiteAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'dateFrom' => ['sometimes', 'date_format:Y-m-d'],
            'dateTo' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:dateFrom'],
            'roomUuid' => [
                'sometimes', 'nullable', 'uuid',
                Rule::exists(Scanner::class, 'scanner_uuid')->where(fn ($query) => $query
                    ->where('modality_code', 'IR')
                    ->whereRaw("COALESCE(metadata->>'ir_suite_declared', 'false') = 'true'")),
            ],
            'patientClass' => ['sometimes', 'nullable', 'string', Rule::in(IrSuiteAnalyticsService::PATIENT_CLASSES)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.IrSuiteAnalyticsService::MAX_LIMIT],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $from = $this->input('dateFrom', now()->subDays(6)->toDateString());
            $to = $this->input('dateTo', now()->toDateString());
            if (! is_string($from) || ! is_string($to)
                || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
                || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                return;
            }
            try {
                $start = CarbonImmutable::createFromFormat('!Y-m-d', $from);
                $end = CarbonImmutable::createFromFormat('!Y-m-d', $to);
                if ($start !== false && $end !== false && $start->diffInDays($end, false) >= IrSuiteAnalyticsService::MAX_RANGE_DAYS) {
                    $validator->errors()->add('dateTo', 'The IR Suite Study range may not exceed '.IrSuiteAnalyticsService::MAX_RANGE_DAYS.' inclusive days.');
                }
            } catch (\Throwable) {
                // date_format owns malformed-date messages.
            }
        });
    }
}
