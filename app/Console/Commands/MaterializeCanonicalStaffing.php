<?php

namespace App\Console\Commands;

use App\Services\Staffing\StaffingShiftWindowService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class MaterializeCanonicalStaffing extends Command
{
    protected $signature = 'staffing:materialize-canonical
        {--from= : First local operating date (YYYY-MM-DD)}
        {--days= : Number of operating days to materialize (1-90)}
        {--facility= : Facility key used by canonical assignments}
        {--dry-run : Execute the full projection and roll it back after reporting counts}';

    protected $description = 'Idempotently project role qualifications and explicit availability windows from the canonical workforce roster.';

    public function __construct(private readonly StaffingShiftWindowService $shiftWindows)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $timezone = (string) config('staffing.default_timezone');
        $from = CarbonImmutable::parse($this->option('from') ?: now($timezone)->toDateString(), $timezone)->startOfDay();
        $days = max(1, min(90, (int) ($this->option('days') ?: config('staffing.materialization_days', 28))));
        $facility = (string) ($this->option('facility') ?: config('staffing.default_facility_key'));
        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();
        try {
            $qualificationCount = $this->materializeRoleQualifications($timezone, $facility);
            $availabilityCount = $this->materializeAvailability($from, $days, $timezone, $facility);
            $result = [
                'qualifications' => $qualificationCount,
                'availability_windows' => $availabilityCount,
                'facility' => $facility,
                'from' => $from->toDateString(),
                'through' => $from->addDays($days - 1)->toDateString(),
            ];
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }

        $this->info(sprintf(
            'Canonical staffing%s: %d effective qualifications; %d availability windows; %s through %s.',
            $dryRun ? ' dry run' : ' materialized',
            $result['qualifications'],
            $result['availability_windows'],
            $result['from'],
            $result['through'],
        ));

        return self::SUCCESS;
    }

    private function materializeRoleQualifications(string $timezone, string $facility): int
    {
        $roles = DB::table('hosp_ref.staff_roles')->orderBy('role_code')->get();
        foreach ($roles as $role) {
            $qualificationCode = 'role.'.Str::lower((string) $role->role_code);
            DB::table('hosp_ref.staff_qualifications')->updateOrInsert(
                ['qualification_code' => $qualificationCode],
                [
                    'display_name' => $role->display_name.' role qualification',
                    'qualification_type' => 'role',
                    'issuing_authority' => 'Canonical workforce assignment',
                    'is_regulated' => (bool) $role->is_regulated,
                    'metadata' => json_encode([
                        'managed_by' => 'staffing:materialize-canonical',
                        'role_code' => $role->role_code,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            DB::table('hosp_ref.staff_role_qualification_requirements')->updateOrInsert(
                [
                    'facility_key' => null,
                    'unit_id' => null,
                    'service_line_code' => null,
                    'role_code' => $role->role_code,
                    'qualification_code' => $qualificationCode,
                    'effective_start' => null,
                ],
                [
                    'effective_end' => null,
                    'is_required' => true,
                    'metadata' => json_encode(['managed_by' => 'staffing:materialize-canonical'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        DB::table('hosp_org.staff_member_qualifications')
            ->whereRaw("metadata->>'managed_by' = ?", ['staffing:materialize-canonical'])
            ->whereRaw("metadata->>'facility_key' = ?", [$facility])
            ->update([
                'status' => 'expired',
                'verified_at' => null,
                'expires_at' => now(),
                'updated_at' => now(),
            ]);

        $count = 0;
        $rows = [];
        DB::table('hosp_org.staff_assignments as sa')
            ->join('hosp_org.staff_members as sm', 'sm.staff_member_id', '=', 'sa.staff_member_id')
            ->join('hosp_ref.staff_roles as sr', 'sr.role_code', '=', 'sa.role_code')
            ->where('sa.is_active', true)
            ->where('sm.is_active', true)
            ->where('sa.facility_key', $facility)
            ->orderBy('sa.staff_assignment_id')
            ->select([
                'sa.staff_assignment_id', 'sa.staff_member_id', 'sa.role_code', 'sa.review_status',
                'sa.effective_start', 'sa.effective_end', 'sm.first_seen_at', 'sr.is_regulated',
            ])
            ->each(function (object $assignment) use ($timezone, $facility, &$count, &$rows): void {
                $qualificationCode = 'role.'.Str::lower((string) $assignment->role_code);
                $effectiveStart = CarbonImmutable::parse(
                    $assignment->effective_start ?: $assignment->first_seen_at ?: '2000-01-01',
                    $timezone,
                )->startOfDay()->utc();
                $effectiveEnd = $assignment->effective_end
                    ? CarbonImmutable::parse($assignment->effective_end, $timezone)->endOfDay()->utc()
                    : null;
                $verified = ! (bool) $assignment->is_regulated
                    || in_array($assignment->review_status, ['source_verified', 'client_verified'], true);

                $rows[] = [
                    'qualification_uuid' => $this->stableUuid("qualification:{$assignment->staff_assignment_id}"),
                    'staff_member_id' => $assignment->staff_member_id,
                    'staff_assignment_id' => $assignment->staff_assignment_id,
                    'qualification_code' => $qualificationCode,
                    'status' => $verified ? 'verified' : 'provisional',
                    'source' => "staff_assignment:{$assignment->staff_assignment_id}",
                    'verified_at' => $verified ? now() : null,
                    'effective_start' => $effectiveStart,
                    'effective_end' => $effectiveEnd,
                    'expires_at' => $effectiveEnd,
                    'identifier_hash' => hash('sha256', "assignment:{$assignment->staff_assignment_id}:{$qualificationCode}"),
                    'metadata' => json_encode([
                        'managed_by' => 'staffing:materialize-canonical',
                        'facility_key' => $facility,
                        'review_status' => $assignment->review_status,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if (count($rows) >= 500) {
                    $this->upsertMemberQualifications($rows);
                    $rows = [];
                }
                $count++;
            });
        if ($rows !== []) {
            $this->upsertMemberQualifications($rows);
        }

        return $count;
    }

    private function materializeAvailability(
        CarbonImmutable $from,
        int $days,
        string $timezone,
        string $facility,
    ): int {
        $rangeStart = $from->utc();
        $rangeEnd = $from->addDays($days)->utc();
        DB::table('prod.staff_availability_windows')
            ->where('source', 'canonical-materializer')
            ->where('external_key', 'like', "canonical:{$facility}:%")
            ->where('ends_at', '>', $rangeStart)
            ->delete();

        $rows = [];
        $members = DB::table('hosp_org.staff_members')
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('employment_status')->orWhereNotIn('employment_status', ['terminated']);
            })
            ->whereExists(function ($query) use ($facility, $from, $days): void {
                $query->selectRaw('1')
                    ->from('hosp_org.staff_assignments as sa')
                    ->whereColumn('sa.staff_member_id', 'hosp_org.staff_members.staff_member_id')
                    ->where('sa.facility_key', $facility)
                    ->where('sa.is_active', true)
                    ->where(function ($query) use ($from, $days): void {
                        $query->whereNull('sa.effective_start')
                            ->orWhereDate('sa.effective_start', '<=', $from->addDays($days - 1)->toDateString());
                    })
                    ->where(function ($query) use ($from): void {
                        $query->whereNull('sa.effective_end')->orWhereDate('sa.effective_end', '>=', $from->toDateString());
                    });
            })
            ->orderBy('staff_member_id')
            ->get(['staff_member_id', 'metadata']);
        foreach ($members as $member) {
            $metadata = $this->decodeMap($member->metadata);
            $state = (string) data_get($metadata, 'availability', '');
            $shift = (string) data_get($metadata, 'preferred_shift', '');
            if (! in_array($state, ['available', 'on_call', 'unavailable', 'leave'], true)) {
                continue;
            }
            if (in_array($state, ['available', 'on_call'], true) && ! array_key_exists($shift, (array) config('staffing.shifts'))) {
                continue;
            }

            for ($offset = 0; $offset < $days; $offset++) {
                $date = $from->addDays($offset)->toDateString();
                if (in_array($state, ['unavailable', 'leave'], true)) {
                    $startsAt = CarbonImmutable::parse("{$date} 00:00", $timezone)->utc();
                    $endsAt = CarbonImmutable::parse("{$date} 00:00", $timezone)->addDay()->utc();
                    $windowType = $state;
                    $windowShift = 'all_day';
                } else {
                    $window = $this->shiftWindows->forDateAndShift($date, $shift, $timezone);
                    $startsAt = $window['starts_at'];
                    $endsAt = $window['ends_at'];
                    $windowType = $state;
                    $windowShift = $shift;
                }
                $externalKey = "canonical:{$facility}:{$member->staff_member_id}:{$date}:{$windowShift}:{$windowType}";
                $rows[] = [
                    'availability_uuid' => $this->stableUuid("availability:{$externalKey}"),
                    'external_key' => $externalKey,
                    'staff_member_id' => $member->staff_member_id,
                    'window_type' => $windowType,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'timezone' => $timezone,
                    'source' => 'canonical-materializer',
                    'priority' => in_array($windowType, ['unavailable', 'leave'], true) ? 10 : 100,
                    'metadata' => json_encode([
                        'managed_by' => 'staffing:materialize-canonical',
                        'facility_key' => $facility,
                        'data_origin' => data_get($metadata, 'data_origin'),
                        'preferred_shift' => $shift ?: null,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if (count($rows) >= 1000) {
                    $this->upsertAvailability($rows);
                    $rows = [];
                }
            }
        }
        if ($rows !== []) {
            $this->upsertAvailability($rows);
        }

        return (int) DB::table('prod.staff_availability_windows')
            ->where('source', 'canonical-materializer')
            ->where('external_key', 'like', "canonical:{$facility}:%")
            ->where('starts_at', '<', $rangeEnd)
            ->where('ends_at', '>', $rangeStart)
            ->count();
    }

    /** @param list<array<string,mixed>> $rows */
    private function upsertMemberQualifications(array $rows): void
    {
        DB::table('hosp_org.staff_member_qualifications')->upsert(
            $rows,
            ['staff_assignment_id', 'qualification_code', 'effective_start'],
            [
                'qualification_uuid', 'staff_member_id', 'status', 'source', 'verified_at',
                'effective_end', 'expires_at', 'identifier_hash', 'metadata', 'updated_at',
            ],
        );
    }

    /** @param list<array<string,mixed>> $rows */
    private function upsertAvailability(array $rows): void
    {
        DB::table('prod.staff_availability_windows')->upsert(
            $rows,
            ['external_key'],
            ['window_type', 'starts_at', 'ends_at', 'timezone', 'source', 'priority', 'metadata', 'updated_at'],
        );
    }

    private function stableUuid(string $key): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "https://zephyrus.acumenus.net/staffing/{$key}")->toString();
    }

    /** @return array<string,mixed> */
    private function decodeMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }

        return is_string($value) ? (json_decode($value, true) ?: []) : [];
    }
}
