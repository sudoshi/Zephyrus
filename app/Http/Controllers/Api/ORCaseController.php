<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ORCase;
use App\Models\Reference\CaseStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ORCaseController extends Controller
{
    protected function validateCase(Request $request)
    {
        return Validator::make($request->all(), [
            'patient_name' => 'required|string|max:255',
            'mrn' => 'required|string|max:50',
            'procedure_name' => 'required|string|max:255',
            'service_id' => ['required', Rule::exists($this->validationTable('prod.services'), 'service_id')],
            'room_id' => ['required', Rule::exists($this->validationTable('prod.rooms'), 'room_id')],
            'location_id' => ['nullable', Rule::exists($this->validationTable('prod.locations'), 'location_id')],
            'primary_surgeon_id' => ['required', Rule::exists($this->validationTable('prod.providers'), 'provider_id')],
            'surgery_date' => 'required|date',
            'scheduled_start_time' => 'required|date_format:H:i',
            'estimated_duration' => 'required|integer|min:15',
            'case_class' => 'required|in:Elective,Urgent,Emergency',
            'asa_rating_id' => ['nullable', Rule::exists($this->validationTable('prod.asa_ratings'), 'asa_id')],
            'case_type_id' => ['nullable', Rule::exists($this->validationTable('prod.case_types'), 'case_type_id')],
            'case_class_id' => ['nullable', Rule::exists($this->validationTable('prod.case_classes'), 'case_class_id')],
            'patient_class_id' => ['nullable', Rule::exists($this->validationTable('prod.patient_classes'), 'patient_class_id')],
            'notes' => 'nullable|string',
        ]);
    }

    public function store(Request $request)
    {
        $validator = $this->validateCase($request);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $case = DB::transaction(function () use ($request): ORCase {
            $case = new ORCase($this->caseAttributes($request));
            $case->is_deleted = false;
            $case->save();

            $this->upsertCaseLog($case, $request);

            return $case->fresh() ?? $case;
        });

        return response()->json($this->casePayload($case), 201);
    }

    public function update(Request $request, $id)
    {
        $validator = $this->validateCase($request);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $case = DB::transaction(function () use ($id, $request): ORCase {
            $case = ORCase::findOrFail($id);
            $case->fill($this->caseAttributes($request, false));
            $case->save();

            $this->upsertCaseLog($case, $request);

            return $case->fresh() ?? $case;
        });

        return response()->json($this->casePayload($case));
    }

    public function index(Request $request)
    {
        try {
            $query = $this->caseQueryWithProcedureName()
                ->with(['surgeon', 'room', 'service', 'status'])
                ->where('prod.or_cases.is_deleted', false);

            // Apply filters
            if ($request->has('date')) {
                $query->where('prod.or_cases.surgery_date', $request->date);
            }

            if ($request->has('status') && $request->status !== '') {
                $query->where('prod.or_cases.status_id', $request->status);
            }

            if ($request->has('service') && $request->service !== '') {
                $query->where('prod.or_cases.case_service_id', $request->service);
            }

            if ($request->has('room') && $request->room !== '') {
                $query->where('prod.or_cases.room_id', $request->room);
            }

            $cases = $query
                ->orderBy('prod.or_cases.surgery_date', 'desc')
                ->orderBy('prod.or_cases.scheduled_start_time', 'asc')
                ->get();

            return response()->json($cases);
        } catch (\Exception $e) {
            Log::error('Error in index: '.$e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'error' => 'Internal server error',
            ], 500);
        }
    }

    public function todaysCases()
    {
        try {
            Log::info('Fetching today\'s cases for date: '.now()->toDateString());

            $cases = $this->caseQueryWithProcedureName()
                ->with(['surgeon', 'room', 'service', 'status'])
                ->where('prod.or_cases.surgery_date', now()->toDateString())
                ->where('prod.or_cases.is_deleted', false)
                ->orderBy('prod.or_cases.scheduled_start_time', 'asc')
                ->get();

            Log::info('Found '.$cases->count().' cases for today');

            return response()->json($cases);
        } catch (\Exception $e) {
            Log::error('Error in todaysCases: '.$e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'error' => 'Internal server error',
            ], 500);
        }
    }

    public function metrics()
    {
        try {
            Log::info('Fetching metrics for last 7 days');

            $utilization = DB::table('prod.case_metrics')
                ->join('prod.or_cases', 'case_metrics.case_id', '=', 'or_cases.case_id')
                ->where('or_cases.surgery_date', '>=', now()->subDays(7)->toDateString())
                ->select(
                    'or_cases.surgery_date',
                    DB::raw('AVG(utilization_percentage) as utilization'),
                    DB::raw('AVG(turnover_time) as avg_turnover'),
                    DB::raw('COUNT(*) as case_count')
                )
                ->groupBy('or_cases.surgery_date')
                ->orderBy('or_cases.surgery_date')
                ->get();

            Log::info('Found metrics for '.$utilization->count().' days');

            return response()->json([
                'utilization' => $utilization,
                'summary' => [
                    'avg_utilization' => $utilization->avg('utilization'),
                    'avg_turnover' => $utilization->avg('avg_turnover'),
                    'total_cases' => $utilization->sum('case_count'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error in metrics: '.$e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'error' => 'Internal server error',
            ], 500);
        }
    }

    public function roomStatus()
    {
        try {
            Log::info('Fetching room status');

            // Get all active rooms
            $rooms = DB::table('prod.rooms')
                ->where('active_status', true)
                ->orderBy('name')
                ->get();

            Log::info('Found '.$rooms->count().' active rooms');

            // Get current cases and logs
            $currentCases = DB::table('prod.or_cases as c')
                ->join('prod.or_logs as l', 'c.case_id', '=', 'l.case_id')
                ->join('prod.providers as p', 'c.primary_surgeon_id', '=', 'p.provider_id')
                ->join('prod.services as s', 'c.case_service_id', '=', 's.service_id')
                ->select(
                    'c.room_id',
                    'c.case_id',
                    'l.primary_procedure as procedure_name',
                    'c.scheduled_duration',
                    'p.name as surgeon_name',
                    's.name as service_name',
                    'l.or_in_time',
                    'l.or_out_time',
                    DB::raw("
                        CASE 
                            WHEN l.or_in_time IS NOT NULL AND l.or_out_time IS NULL THEN 'In Progress'
                            WHEN l.or_out_time IS NOT NULL THEN 'Turnover'
                            ELSE 'Available'
                        END as status
                    ")
                )
                ->where('c.surgery_date', now()->toDateString())
                ->where('c.is_deleted', false)
                ->get();

            Log::info('Found '.$currentCases->count().' current cases');

            // Map room status
            $status = $rooms->map(function ($room) use ($currentCases) {
                $currentCase = $currentCases->firstWhere('room_id', $room->room_id);

                return [
                    'room_id' => $room->room_id,
                    'room_name' => $room->name,
                    'case_id' => $currentCase ? $currentCase->case_id : null,
                    'procedure_name' => $currentCase ? $currentCase->procedure_name : null,
                    'surgeon_name' => $currentCase ? $currentCase->surgeon_name : null,
                    'service_name' => $currentCase ? $currentCase->service_name : null,
                    'scheduled_duration' => $currentCase ? $currentCase->scheduled_duration : null,
                    'or_in_time' => $currentCase ? $currentCase->or_in_time : null,
                    'or_out_time' => $currentCase ? $currentCase->or_out_time : null,
                    'status' => $currentCase ? $currentCase->status : 'Available',
                ];
            });

            return response()->json($status);
        } catch (\Exception $e) {
            Log::error('Error in roomStatus: '.$e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'error' => 'Internal server error',
            ], 500);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function caseAttributes(Request $request, bool $creating = true): array
    {
        $surgeryDate = CarbonImmutable::parse((string) $request->input('surgery_date'));
        $scheduledStart = CarbonImmutable::parse($surgeryDate->toDateString().' '.$request->input('scheduled_start_time'));
        $caseClass = (string) $request->input('case_class');

        $attributes = [
            'patient_id' => (string) $request->input('mrn'),
            'surgery_date' => $surgeryDate->toDateString(),
            'scheduled_start_time' => $scheduledStart,
            'scheduled_duration' => $request->integer('estimated_duration'),
            'room_id' => $request->integer('room_id'),
            'location_id' => $request->integer('location_id') ?: $this->roomLocationId($request->integer('room_id')),
            'primary_surgeon_id' => $request->integer('primary_surgeon_id'),
            'case_service_id' => $request->integer('service_id'),
            'status_id' => CaseStatus::where('code', 'SCHED')->value('status_id') ?? $this->requiredReferenceId('prod.case_statuses', 'status_id', ['SCHED'], ['Scheduled']),
            'asa_rating_id' => $request->integer('asa_rating_id') ?: $this->requiredReferenceId('prod.asa_ratings', 'asa_id', ['ASA2', '2'], ['ASA II']),
            'case_type_id' => $request->integer('case_type_id') ?: $this->caseTypeId($caseClass),
            'case_class_id' => $request->integer('case_class_id') ?: $this->requiredReferenceId('prod.case_classes', 'case_class_id', ['INP', 'IP', 'ELECTIVE'], ['Inpatient', 'Elective']),
            'patient_class_id' => $request->integer('patient_class_id') ?: $this->requiredReferenceId('prod.patient_classes', 'patient_class_id', ['INP', 'IP'], ['Inpatient']),
            'modified_by' => $request->user()?->username ?? $request->user()?->email,
        ];

        if ($creating) {
            $attributes['record_create_date'] = now();
            $attributes['created_by'] = $request->user()?->username ?? $request->user()?->email;
        }

        return $attributes;
    }

    private function roomLocationId(int $roomId): int
    {
        $locationId = DB::table('prod.rooms')->where('room_id', $roomId)->value('location_id');

        if ($locationId === null) {
            throw ValidationException::withMessages(['room_id' => 'The selected room has no location.']);
        }

        return (int) $locationId;
    }

    private function validationTable(string $schemaTable): string
    {
        return config('database.default').'.'.$schemaTable;
    }

    private function upsertCaseLog(ORCase $case, Request $request): void
    {
        $now = now();
        $user = $request->user()?->username ?? $request->user()?->email;
        $existingLogId = DB::table('prod.or_logs')
            ->where('case_id', $case->case_id)
            ->where('is_deleted', false)
            ->orderByDesc('log_id')
            ->value('log_id');

        $attributes = [
            'tracking_date' => $case->surgery_date,
            'primary_procedure' => (string) $request->input('procedure_name'),
            'modified_by' => $user,
            'updated_at' => $now,
        ];

        if ($existingLogId !== null) {
            DB::table('prod.or_logs')->where('log_id', $existingLogId)->update($attributes);

            return;
        }

        DB::table('prod.or_logs')->insert($attributes + [
            'case_id' => $case->case_id,
            'created_by' => $user,
            'created_at' => $now,
            'is_deleted' => false,
        ]);
    }

    private function casePayload(ORCase $case): array
    {
        $payload = $case->toArray();
        $payload['procedure_name'] = DB::table('prod.or_logs')
            ->where('case_id', $case->case_id)
            ->where('is_deleted', false)
            ->orderByDesc('log_id')
            ->value('primary_procedure');

        return $payload;
    }

    private function caseQueryWithProcedureName()
    {
        $latestLogs = DB::table('prod.or_logs')
            ->select('case_id', DB::raw('MAX(log_id) as log_id'))
            ->where('is_deleted', false)
            ->groupBy('case_id');

        return ORCase::query()
            ->leftJoinSub($latestLogs, 'latest_case_logs', function ($join): void {
                $join->on('prod.or_cases.case_id', '=', 'latest_case_logs.case_id');
            })
            ->leftJoin('prod.or_logs as procedure_log', 'latest_case_logs.log_id', '=', 'procedure_log.log_id')
            ->select('prod.or_cases.*', 'procedure_log.primary_procedure as procedure_name');
    }

    private function caseTypeId(string $caseClass): int
    {
        $codes = match ($caseClass) {
            'Emergency' => ['EMERG', 'EMER', 'STAT'],
            'Urgent' => ['URG', 'URGENT'],
            default => ['ELEC', 'ELECTIVE'],
        };

        return $this->requiredReferenceId('prod.case_types', 'case_type_id', $codes, [$caseClass]);
    }

    /** @param list<string> $codes @param list<string> $names */
    private function requiredReferenceId(string $table, string $key, array $codes, array $names): int
    {
        $id = $this->referenceId($table, $key, $codes, $names);

        if ($id === null) {
            throw ValidationException::withMessages([
                $key => "Required OR case reference data is missing for {$table}.",
            ]);
        }

        return $id;
    }

    /** @param list<string> $codes @param list<string> $names */
    private function referenceId(string $table, string $key, array $codes, array $names): ?int
    {
        $query = DB::table($table);

        $id = (clone $query)
            ->whereIn('code', $codes)
            ->value($key);

        if ($id === null && $names !== []) {
            $id = (clone $query)
                ->whereIn('name', $names)
                ->value($key);
        }

        return $id !== null ? (int) $id : null;
    }
}
