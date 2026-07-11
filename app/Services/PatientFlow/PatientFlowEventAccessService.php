<?php

namespace App\Services\PatientFlow;

use App\Models\PatientFlow\FlowEvent;
use App\Services\Flow\FlowLensService;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * One authorization/redaction path for web Patient Flow event surfaces.
 *
 * Raw flow identifiers are used only long enough to enforce the resolved
 * scope/depth. Every surviving row is then passed through FlowLensService,
 * which emits opaque patient/encounter context refs.
 */
class PatientFlowEventAccessService
{
    public function __construct(
        private readonly FlowEventRepository $events,
        private readonly FlowLensService $lens,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function rows(Request $request, array $filters = []): array
    {
        $patientContextRef = $this->patientFilter($request);
        unset($filters['patient']);

        $context = $this->context($request);
        $rows = $this->events->serializeEvents($this->events->filteredEvents($filters));
        $authorized = [];

        foreach ($rows as $row) {
            if (! $this->eventKindAllowed($row, $context['lens'])
                || ! $this->lens->canViewPatientRow(
                    $row,
                    $context['depth'],
                    $context['scope'],
                    $context['visible_unit_ids'],
                    $context['task_refs'],
                )) {
                continue;
            }

            $redacted = $this->lens->redactRow(
                $row,
                $context['depth'],
                $context['scope'],
                $context['task_refs'],
                $context['visible_unit_ids'],
            );

            if ($patientContextRef !== null && ($redacted['patient_context_ref'] ?? null) !== $patientContextRef) {
                continue;
            }

            $authorized[] = $redacted;
        }

        return $authorized;
    }

    /** @return array<string, mixed>|null */
    public function event(Request $request, FlowEvent $event): ?array
    {
        $context = $this->context($request);
        $row = $this->events->serializeEvent($event);

        if (! $this->eventKindAllowed($row, $context['lens'])
            || ! $this->lens->canViewPatientRow(
                $row,
                $context['depth'],
                $context['scope'],
                $context['visible_unit_ids'],
                $context['task_refs'],
            )) {
            return null;
        }

        return $this->lens->redactRow(
            $row,
            $context['depth'],
            $context['scope'],
            $context['task_refs'],
            $context['visible_unit_ids'],
        );
    }

    /**
     * @return array{
     *   lens: array<string, mixed>,
     *   scope: array<string, mixed>,
     *   depth: string,
     *   task_refs: list<string>,
     *   visible_unit_ids: list<int>
     * }
     */
    public function context(Request $request): array
    {
        $lens = $request->attributes->get('flow_lens');
        $scope = $request->attributes->get('flow_scope');
        $depth = $request->attributes->get('flow_patient_depth');

        if (! is_array($lens) || ! is_array($scope) || ! is_string($depth)) {
            throw new \LogicException('Patient Flow scope was not resolved before controller execution.');
        }

        return [
            'lens' => $lens,
            'scope' => $scope,
            'depth' => $depth,
            'task_refs' => array_values(array_filter(
                (array) $request->attributes->get('flow_task_patient_refs', []),
                'is_string',
            )),
            'visible_unit_ids' => array_values(array_map(
                'intval',
                (array) $request->attributes->get('flow_visible_unit_ids', []),
            )),
        ];
    }

    /** @param array<string, mixed> $row */
    private function eventKindAllowed(array $row, array $lens): bool
    {
        $allowed = is_array($lens['event_kinds'] ?? null) ? $lens['event_kinds'] : [];

        return $allowed === [] || in_array((string) ($row['event_type'] ?? ''), $allowed, true);
    }

    private function patientFilter(Request $request): ?string
    {
        $value = $request->query('patient');
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! preg_match('/^ptok_[A-Za-z0-9]{24}$/', $value)) {
            throw new InvalidArgumentException('patient must be an opaque ptok_ context ref.');
        }

        return $value;
    }
}
