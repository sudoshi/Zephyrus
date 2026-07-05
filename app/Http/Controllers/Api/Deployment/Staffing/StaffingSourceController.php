<?php

namespace App\Http\Controllers\Api\Deployment\Staffing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Deployment\Staffing\StoreStaffingSourceRequest;
use App\Models\Org\StaffingSource;
use App\Services\Staffing\StaffingConnectorFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Phase F4 (§8): manage staffing connector sources + probe/discover them. Gated by
 * manageDeploymentConfig. Connector secrets are never accepted or returned — the
 * shipped file/FHIR path uploads content per request and stores none; API-connector
 * secrets (deferred, §12) live encrypted in integration.sources.
 */
class StaffingSourceController extends Controller
{
    public function __construct(private readonly StaffingConnectorFactory $factory) {}

    public function index(): JsonResponse
    {
        $sources = StaffingSource::query()
            ->orderBy('source_key')
            ->get()
            ->map(fn (StaffingSource $s): array => $this->present($s))
            ->all();

        return response()->json(['data' => $sources, 'meta' => ['count' => count($sources)]]);
    }

    public function store(StoreStaffingSourceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $metadata = [];
        if (! empty($data['default_facility_key'])) {
            $metadata['default_facility_key'] = $data['default_facility_key'];
        }

        $source = StaffingSource::query()->updateOrCreate(
            ['source_key' => $data['source_key']],
            array_filter([
                'display_name' => $data['display_name'] ?? null,
                'connector_type' => $data['connector_type'],
                'transport' => $data['transport'],
                'organization_id' => $data['organization_id'] ?? null,
                'mapping_template' => $data['mapping_template'] ?? null,
                'sync_schedule' => $data['sync_schedule'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ], fn ($v): bool => $v !== null),
        );

        if ($metadata !== []) {
            $source->metadata = array_merge($source->metadata ?? [], $metadata);
            $source->save();
        }

        return response()->json(['data' => $this->present($source->refresh())], 201);
    }

    public function test(Request $request, StaffingSource $source): JsonResponse
    {
        try {
            $connector = $this->connectorFor($source, $request);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $result = $connector->testConnection();

        return response()->json(['data' => [
            'ok' => $result->ok,
            'message' => $result->message,
            'details' => (object) $result->details,
        ]]);
    }

    public function discover(Request $request, StaffingSource $source): JsonResponse
    {
        try {
            $connector = $this->connectorFor($source, $request);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $fields = $connector->discoverSchema();

        return response()->json(['data' => [
            'fields' => $fields,
            // Cast to object so an empty map serializes as {} (not []) for a stable wire shape.
            'suggested_mapping' => (object) $this->suggestMapping($fields),
        ]]);
    }

    public function schedule(Request $request, StaffingSource $source): JsonResponse
    {
        $validated = $request->validate([
            'sync_schedule' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $source->fill($validated)->save();

        return response()->json(['data' => $this->present($source->refresh())]);
    }

    /**
     * Build the connector from request-supplied content (csv string / fhir bundle) +
     * an optional per-run mapping override.
     */
    private function connectorFor(StaffingSource $source, Request $request): \App\Services\Staffing\Contracts\StaffingConnector
    {
        return $this->factory->make($source, array_filter([
            'csv' => $request->input('csv'),
            'bundle' => $request->input('bundle'),
            'mapping' => $request->input('mapping'),
        ], fn ($v): bool => $v !== null));
    }

    /**
     * Heuristic column -> canonical-field suggestion for the mapping step. Only maps
     * confident matches; the reviewer confirms/edits the rest.
     *
     * @param  list<array<string,mixed>>  $fields
     * @return array<string,string>
     */
    private function suggestMapping(array $fields): array
    {
        $canonical = [
            'external_id' => ['external_id', 'employee_id', 'emp_id', 'id', 'person_id', 'staff_id', 'provider_id', 'npi'],
            'display_name' => ['display_name', 'name', 'full_name', 'employee_name', 'provider_name'],
            'email' => ['email', 'email_address', 'work_email'],
            'npi' => ['npi', 'npi_number'],
            'license_no' => ['license_no', 'license', 'license_number'],
            'employee_type' => ['employee_type', 'worker_type', 'emp_type'],
            'employment_status' => ['employment_status', 'status', 'emp_status'],
            'job_code' => ['job_code', 'position_code', 'job_id'],
            'job_title' => ['job_title', 'title', 'position', 'role'],
            'specialty' => ['specialty', 'speciality', 'clinical_specialty'],
            'department' => ['department', 'dept', 'cost_center_name'],
            'cost_center' => ['cost_center', 'costcenter', 'cc'],
            'home_unit' => ['home_unit', 'unit', 'primary_unit', 'default_unit'],
            'fte' => ['fte', 'fte_pct', 'full_time_equivalent'],
            'term_date' => ['term_date', 'termination_date', 'end_date'],
        ];

        $mapping = [];
        foreach ($fields as $field) {
            $column = (string) ($field['field'] ?? '');
            $norm = strtolower(str_replace([' ', '-'], '_', trim($column)));
            if ($column === '') {
                continue;
            }
            foreach ($canonical as $target => $aliases) {
                if (in_array($norm, $aliases, true) && ! in_array($target, $mapping, true)) {
                    $mapping[$column] = $target;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Safe projection — no secrets (there are none stored) and no raw metadata dump.
     *
     * @return array<string,mixed>
     */
    private function present(StaffingSource $source): array
    {
        return [
            'staffing_source_id' => (int) $source->staffing_source_id,
            'source_key' => $source->source_key,
            'display_name' => $source->display_name,
            'connector_type' => $source->connector_type,
            'transport' => $source->transport,
            'organization_id' => $source->organization_id !== null ? (int) $source->organization_id : null,
            'mapping_template' => (object) (is_array($source->mapping_template) ? $source->mapping_template : []),
            'default_facility_key' => $source->metadata['default_facility_key'] ?? null,
            'sync_schedule' => $source->sync_schedule,
            'is_active' => (bool) $source->is_active,
            'last_synced_at' => optional($source->last_synced_at)->toISOString(),
        ];
    }
}
