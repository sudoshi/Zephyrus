// Virtual Rounds — the unit rounds board (plan §14). The board is the primary
// operational surface: dense, quiet, optimized for repeated scanning. All
// server state flows through TanStack Query as `unknown` and is parsed with
// Zod at this boundary; a malformed payload degrades to an inline card.
import { useEffect, useMemo, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import RoundsBoard from '@/Components/VirtualRounds/RoundsBoard';
import RoundsCommandBar from '@/Components/VirtualRounds/RoundsCommandBar';
import ParticipantRail from '@/Components/VirtualRounds/ParticipantRail';
import RoundPatientWorkspace from '@/Components/VirtualRounds/RoundPatientWorkspace';
import {
  useComposeContribution,
  useCreateRun,
  usePatientTransition,
  usePinPatient,
  useRoundBoard,
  useRoundPatient,
  useRoundRuns,
  useRoundScopes,
  useRoundTemplates,
  useRunLifecycle,
} from '@/features/virtualRounds/hooks';
import {
  boardSchema,
  conflictResponseSchema,
  patientDetailSchema,
  runsResponseSchema,
  scopesResponseSchema,
  templatesResponseSchema,
} from '@/features/virtualRounds/schemas';
import type { PatientTransitionAction } from '@/features/virtualRounds/api';

function ErrorCard({ message }: { message: string }) {
  return (
    <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 text-sm text-healthcare-text-secondary shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-secondary-dark">
      {message}
    </div>
  );
}

export default function VirtualRounds() {
  const queryClient = useQueryClient();

  const [scopeKey, setScopeKey] = useState<string | null>(null);
  const [templateUuid, setTemplateUuid] = useState<string | null>(null);
  const [runUuid, setRunUuid] = useState<string | null>(null);
  // R-2: 4D ring → board deep link (?patient={round_patient_uuid}); the
  // workspace fetches by uuid directly, so this works regardless of scope.
  const [selectedPatientUuid, setSelectedPatientUuid] = useState<string | null>(() => {
    if (typeof window === 'undefined') return null;
    return new URLSearchParams(window.location.search).get('patient');
  });
  const [conflictMessage, setConflictMessage] = useState<string | null>(null);

  const templatesQuery = useRoundTemplates();
  const scopesQuery = useRoundScopes();

  const templatesResponse = useMemo(() => {
    if (templatesQuery.data === undefined) return null;
    const parsed = templatesResponseSchema.safeParse(templatesQuery.data);
    return parsed.success ? parsed.data : null;
  }, [templatesQuery.data]);

  const scopes = useMemo(() => {
    if (scopesQuery.data === undefined) return [];
    const parsed = scopesResponseSchema.safeParse(scopesQuery.data);
    return parsed.success ? parsed.data.data : [];
  }, [scopesQuery.data]);

  const effectiveScopeKey = scopeKey ?? scopes[0]?.scope_key ?? null;
  const effectiveTemplateUuid = templateUuid ?? templatesResponse?.data[0]?.template_uuid ?? null;

  const runsQuery = useRoundRuns(effectiveScopeKey ? `unit:${effectiveScopeKey}` : undefined);
  const runs = useMemo(() => {
    if (runsQuery.data === undefined) return [];
    const parsed = runsResponseSchema.safeParse(runsQuery.data);
    return parsed.success ? parsed.data.data : [];
  }, [runsQuery.data]);

  const openRun = runs.find((r) => !['completed', 'cancelled'].includes(r.status));
  const effectiveRunUuid = runUuid ?? openRun?.run_uuid ?? runs[0]?.run_uuid ?? null;

  const boardQuery = useRoundBoard(effectiveRunUuid);
  const board = useMemo(() => {
    if (boardQuery.data === undefined) return null;
    const parsed = boardSchema.safeParse(boardQuery.data);
    return parsed.success ? parsed.data : null;
  }, [boardQuery.data]);

  // A deep-linked uuid that is not on the displayed run's board (other
  // scope, stale run) must not pin a foreign patient beside this board —
  // pin/transition would submit THIS board's queue version for it. Fall back
  // to the board's own first patient instead.
  useEffect(() => {
    if (!selectedPatientUuid || !board) return;
    const onBoard = board.data.patients.some(
      (patient) => patient.round_patient_uuid === selectedPatientUuid,
    );
    if (!onBoard) setSelectedPatientUuid(null);
  }, [board, selectedPatientUuid]);

  const effectiveSelectedUuid =
    selectedPatientUuid ?? board?.data.patients[0]?.round_patient_uuid ?? null;

  const patientQuery = useRoundPatient(effectiveSelectedUuid);
  const patientDetail = useMemo(() => {
    if (patientQuery.data === undefined) return null;
    const parsed = patientDetailSchema.safeParse(patientQuery.data);
    return parsed.success ? parsed.data : null;
  }, [patientQuery.data]);

  const createRun = useCreateRun();
  const lifecycle = useRunLifecycle(effectiveRunUuid);
  const patientTransition = usePatientTransition();
  const pinPatient = usePinPatient();
  const compose = useComposeContribution();

  const busy =
    createRun.isPending ||
    lifecycle.isPending ||
    patientTransition.isPending ||
    pinPatient.isPending ||
    compose.isPending;

  // 409 recovery: the server sends the current projection alongside the
  // conflict — install it into the cache and tell the user what happened.
  const handleMutationError = (error: unknown) => {
    if (error instanceof AxiosError && error.response?.status === 409) {
      const parsed = conflictResponseSchema.safeParse(error.response.data);
      if (parsed.success) {
        setConflictMessage(`${parsed.data.error.message} The view has been refreshed — please retry.`);
        if (parsed.data.current && effectiveRunUuid) {
          queryClient.setQueryData(['rounds', 'board', effectiveRunUuid], parsed.data.current);
        }
        return;
      }
    }
    if (error instanceof AxiosError && error.response?.status === 422) {
      const message = (error.response.data as { error?: { message?: string } })?.error?.message;
      setConflictMessage(message ?? 'The server rejected that change.');
      return;
    }
    setConflictMessage('That change did not go through. Please retry.');
  };

  const clearConflictThen = { onSuccess: () => setConflictMessage(null), onError: handleMutationError };

  const flagDisabled =
    templatesQuery.error instanceof AxiosError && templatesQuery.error.response?.status === 404;

  return (
    <RTDCPageLayout
      title="Virtual Rounds"
      subtitle="Asynchronous multidisciplinary rounds — shared queue, role inputs, explainable order"
    >
      {flagDisabled ? (
        <ErrorCard message="Virtual Rounds is not enabled in this environment (VIRTUAL_ROUNDS_ENABLED)." />
      ) : (
        <>
          <RoundsCommandBar
            scopes={scopes}
            templates={templatesResponse?.data ?? []}
            runs={runs}
            selectedScopeKey={effectiveScopeKey}
            selectedTemplateUuid={effectiveTemplateUuid}
            selectedRun={board?.data.run ?? runs.find((r) => r.run_uuid === effectiveRunUuid) ?? null}
            busy={busy}
            onScopeChange={(key) => {
              setScopeKey(key);
              setRunUuid(null);
              setSelectedPatientUuid(null);
            }}
            onTemplateChange={setTemplateUuid}
            onRunChange={(uuid) => {
              setRunUuid(uuid);
              setSelectedPatientUuid(null);
            }}
            onCreateRun={() => {
              if (effectiveScopeKey && effectiveTemplateUuid) {
                createRun.mutate(
                  { template_uuid: effectiveTemplateUuid, scope_type: 'unit', scope_key: effectiveScopeKey },
                  {
                    ...clearConflictThen,
                    onSuccess: (data) => {
                      setConflictMessage(null);
                      const parsed = boardSchema.safeParse(data);
                      if (parsed.success) {
                        setRunUuid(parsed.data.data.run.run_uuid);
                      }
                    },
                  },
                );
              }
            }}
            onLifecycle={(action) => lifecycle.mutate({ action }, clearConflictThen)}
          />

          {board && (
            <div className="flex flex-wrap items-center gap-3 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              <span>
                Progress:{' '}
                <span className="font-medium tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  {board.data.progress.rounded}/{board.data.progress.total}
                </span>{' '}
                rounded
              </span>
              <span>
                Queue version <span className="tabular-nums">{board.meta.version}</span>
              </span>
              <span>
                Generated{' '}
                <span className="tabular-nums">
                  {new Date(board.meta.generated_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                </span>
              </span>
            </div>
          )}

          {boardQuery.isLoading && effectiveRunUuid && <ErrorCard message="Loading round…" />}
          {!effectiveRunUuid && !boardQuery.isLoading && (
            <ErrorCard message="No round yet for this unit today. Pick a template and start today's run." />
          )}
          {boardQuery.data !== undefined && board === null && (
            <ErrorCard message="The round projection could not be read. Refresh to retry." />
          )}

          {board && (
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr,26rem]">
              <div className="space-y-3">
                <RoundsBoard
                  patients={board.data.patients}
                  selectedUuid={effectiveSelectedUuid}
                  lens={board.meta.lens}
                  onSelect={(uuid) => {
                    setSelectedPatientUuid(uuid);
                    setConflictMessage(null);
                  }}
                />
                <ParticipantRail
                  participants={board.data.participants}
                  roles={templatesResponse?.meta.roles ?? {}}
                />
              </div>

              <div>
                {patientDetail ? (
                  <RoundPatientWorkspace
                    key={effectiveSelectedUuid ?? 'none'}
                    detail={patientDetail}
                    sections={templatesResponse?.meta.sections ?? []}
                    roles={templatesResponse?.meta.roles ?? {}}
                    busy={busy}
                    conflictMessage={conflictMessage}
                    onTransition={(action: PatientTransitionAction, body) =>
                      patientTransition.mutate(
                        { roundPatientUuid: effectiveSelectedUuid as string, action, body },
                        clearConflictThen,
                      )
                    }
                    onPin={(pinned, reason) =>
                      pinPatient.mutate(
                        {
                          roundPatientUuid: effectiveSelectedUuid as string,
                          pinned,
                          reason,
                          expectedQueueVersion: board.meta.version,
                        },
                        clearConflictThen,
                      )
                    }
                    onContribute={(input) =>
                      compose.mutate(
                        { roundPatientUuid: effectiveSelectedUuid as string, body: input },
                        clearConflictThen,
                      )
                    }
                  />
                ) : (
                  <ErrorCard message="Select a patient to open the round workspace." />
                )}
              </div>
            </div>
          )}
        </>
      )}
    </RTDCPageLayout>
  );
}
