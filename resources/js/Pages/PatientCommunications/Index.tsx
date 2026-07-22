import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import {
    createMutationCommand,
    isUnknownMutationOutcome,
    mutationRequest,
    type PatientCommunicationMutationCommand,
    type PatientCommunicationMutationKind,
} from "@/Pages/PatientCommunications/mutationSafety";
import {
    parsePatientCommunicationRouteCandidates,
    type PatientCommunicationRouteCandidates,
} from "@/Pages/PatientCommunications/routingPolicy";
import RoutingControls, {
    type RoutingIntent,
} from "@/Pages/PatientCommunications/RoutingControls";
import type { PageProps } from "@/types";
import { Head, usePage } from "@inertiajs/react";
import axios, { AxiosError } from "axios";
import {
    AlertCircle,
    CheckCircle2,
    Clock3,
    Inbox,
    LoaderCircle,
    LockKeyhole,
    MessageSquare,
    RefreshCw,
    Search,
    Send,
    ShieldAlert,
    UserCheck,
} from "lucide-react";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";

interface CommunicationTopic {
    code: string;
    label: string;
}

interface CommunicationUnit {
    id: number;
    label: string;
}

interface CommunicationPool {
    pool_uuid: string;
    label: string;
}

interface CommunicationMessage {
    message_uuid: string;
    sender_display_role: string;
    visibility: string;
    message_kind: string;
    body: string | null;
    delivery_state: string;
    sent_at: string | null;
}

interface CommunicationWorkItem {
    work_item_uuid: string;
    thread_uuid: string;
    patient_context_ref: string | null;
    topic: CommunicationTopic;
    unit: CommunicationUnit | null;
    pool: CommunicationPool;
    status: string;
    ownership_state: string;
    assigned_to_me: boolean;
    work_item_version: number;
    thread_version: number;
    last_message_at: string | null;
    due_at: string | null;
    escalate_at: string | null;
    is_response_due: boolean;
    is_escalation_due: boolean;
    closed_at: string | null;
    messages?: CommunicationMessage[];
    has_earlier_messages?: boolean;
}

interface CommunicationInbox {
    items: CommunicationWorkItem[];
    count: number;
}

interface CommunicationEndpoints {
    inbox: string;
    thread: string;
    claim: string;
    reply: string;
    close: string;
    routeCandidates: string;
    release: string;
    reassign: string;
    reroute: string;
}

interface CommunicationPageProps extends PageProps {
    initialInbox: CommunicationInbox;
    endpoints: CommunicationEndpoints;
}

interface DataEnvelope<T> {
    data: T;
    meta: {
        as_of: string;
        classification: string;
        offline_writes_allowed: false;
    };
}

interface CommunicationMutationOutcome {
    work_item: CommunicationWorkItem | null;
    message: CommunicationMessage | null;
    event_uuid: string | null;
    replayed: boolean;
}

class UnverifiedMutationOutcomeError extends Error {
    constructor() {
        super("The mutation response could not be verified.");
        this.name = "UnverifiedMutationOutcomeError";
    }
}

type QueueFilter = "all" | "escalated" | "due" | "unassigned" | "mine";

const INBOX_POLL_INTERVAL_MS = 20_000;

const CLOSE_REASONS = [
    ["question_answered", "Question answered"],
    ["duplicate", "Duplicate"],
    ["transferred", "Transferred to another team"],
    ["patient_requested", "Patient requested closure"],
    ["other", "Other"],
] as const;

function endpointFor(template: string, workItemUuid: string): string {
    return template.replace(
        "__WORK_ITEM_UUID__",
        encodeURIComponent(workItemUuid),
    );
}

function formatTimestamp(value: string | null): string {
    if (!value) return "Not available";
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return "Not available";

    return new Intl.DateTimeFormat(undefined, {
        month: "short",
        day: "numeric",
        hour: "numeric",
        minute: "2-digit",
    }).format(parsed);
}

function contextLabel(value: string | null): string {
    if (!value) return "Patient context unavailable";
    return `Patient context …${value.slice(-6)}`;
}

function isClaimable(item: CommunicationWorkItem): boolean {
    return (
        !item.assigned_to_me &&
        ["pool_owned", "rerouted", "escalated"].includes(item.ownership_state)
    );
}

function hasSelectedProjectionDrift(
    previous: CommunicationWorkItem,
    next: CommunicationWorkItem,
): boolean {
    return (
        previous.work_item_uuid !== next.work_item_uuid ||
        previous.thread_uuid !== next.thread_uuid ||
        previous.patient_context_ref !== next.patient_context_ref ||
        previous.topic.code !== next.topic.code ||
        previous.unit?.id !== next.unit?.id ||
        previous.pool.pool_uuid !== next.pool.pool_uuid ||
        previous.status !== next.status ||
        previous.ownership_state !== next.ownership_state ||
        previous.assigned_to_me !== next.assigned_to_me ||
        previous.work_item_version !== next.work_item_version ||
        previous.thread_version !== next.thread_version
    );
}

function responseError(error: unknown): {
    status: number | null;
    code: string | null;
    message: string;
} {
    if (error instanceof AxiosError) {
        const payload = error.response?.data as
            | { error?: { code?: string; message?: string }; message?: string }
            | undefined;

        return {
            status: error.response?.status ?? null,
            code: payload?.error?.code ?? null,
            message:
                payload?.error?.message ??
                payload?.message ??
                "The request could not be completed.",
        };
    }

    return {
        status: null,
        code: null,
        message: "The request could not be completed.",
    };
}

function isVerifiedMutationOutcome(
    value: unknown,
    command: PatientCommunicationMutationCommand,
): value is CommunicationMutationOutcome {
    if (value === null || typeof value !== "object" || Array.isArray(value))
        return false;

    const outcome = value as Record<string, unknown>;
    const keys = Object.keys(outcome).sort();
    if (
        keys.length !== 4 ||
        keys.join("|") !==
            ["event_uuid", "message", "replayed", "work_item"]
                .sort()
                .join("|") ||
        typeof outcome.replayed !== "boolean"
    )
        return false;

    const canonicalEventUuid =
        typeof outcome.event_uuid === "string" &&
        /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(
            outcome.event_uuid,
        );

    if (outcome.work_item === null) {
        return (
            command.kind === "reroute" &&
            outcome.replayed === true &&
            outcome.message === null &&
            canonicalEventUuid
        );
    }

    if (
        typeof outcome.work_item !== "object" ||
        Array.isArray(outcome.work_item)
    )
        return false;

    const workItem = outcome.work_item as Record<string, unknown>;
    const rawWorkItemVersion = command.payload.work_item_version;
    const rawThreadVersion = command.payload.thread_version;
    const submittedWorkItemVersion =
        typeof rawWorkItemVersion === "number" &&
        Number.isSafeInteger(rawWorkItemVersion)
            ? rawWorkItemVersion
            : null;
    const submittedThreadVersion =
        typeof rawThreadVersion === "number" &&
        Number.isSafeInteger(rawThreadVersion)
            ? rawThreadVersion
            : null;
    if (submittedWorkItemVersion === null || submittedThreadVersion === null)
        return false;

    const responseWorkItemVersion = workItem.work_item_version;
    const responseThreadVersion = workItem.thread_version;
    if (
        typeof responseWorkItemVersion !== "number" ||
        !Number.isSafeInteger(responseWorkItemVersion) ||
        typeof responseThreadVersion !== "number" ||
        !Number.isSafeInteger(responseThreadVersion)
    )
        return false;

    const verifiedProjectionIdentity =
        workItem.work_item_uuid === command.workItemUuid;
    const verifiedVersions =
        verifiedProjectionIdentity &&
        (outcome.replayed
            ? responseWorkItemVersion >= submittedWorkItemVersion + 1 &&
              responseThreadVersion >= submittedThreadVersion + 1
            : responseWorkItemVersion === submittedWorkItemVersion + 1 &&
              responseThreadVersion === submittedThreadVersion + 1);
    const verifiedMessage =
        command.kind === "reply"
            ? typeof outcome.message === "object" &&
              outcome.message !== null &&
              !Array.isArray(outcome.message)
            : outcome.message === null;

    if (command.kind === "reroute") {
        return (
            verifiedProjectionIdentity &&
            outcome.replayed === false &&
            outcome.message === null &&
            canonicalEventUuid &&
            responseWorkItemVersion === submittedWorkItemVersion + 1 &&
            responseThreadVersion === submittedThreadVersion + 1
        );
    }

    return verifiedVersions && verifiedMessage && canonicalEventUuid;
}

function StatusBadge({ item }: { item: CommunicationWorkItem }) {
    if (item.is_escalation_due || item.ownership_state === "escalated") {
        return (
            <span className="inline-flex items-center gap-1 rounded-full bg-healthcare-critical/10 px-2 py-1 text-xs font-semibold text-healthcare-critical dark:bg-healthcare-critical-dark/20 dark:text-healthcare-critical-dark">
                <ShieldAlert className="size-3.5" aria-hidden="true" />{" "}
                Escalated
            </span>
        );
    }
    if (item.is_response_due) {
        return (
            <span className="inline-flex items-center gap-1 rounded-full bg-healthcare-warning/10 px-2 py-1 text-xs font-semibold text-healthcare-warning dark:bg-healthcare-warning-dark/20 dark:text-healthcare-warning-dark">
                <Clock3 className="size-3.5" aria-hidden="true" /> Response due
            </span>
        );
    }
    if (item.assigned_to_me) {
        return (
            <span className="inline-flex items-center gap-1 rounded-full bg-healthcare-primary/10 px-2 py-1 text-xs font-semibold text-healthcare-primary dark:bg-healthcare-primary-dark/20 dark:text-healthcare-primary-dark">
                <UserCheck className="size-3.5" aria-hidden="true" /> Assigned
                to me
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-1 rounded-full bg-healthcare-background px-2 py-1 text-xs font-semibold text-healthcare-text-secondary dark:bg-white/5 dark:text-healthcare-text-secondary-dark">
            <Inbox className="size-3.5" aria-hidden="true" /> Team queue
        </span>
    );
}

export default function PatientCommunicationsIndex({
    initialInbox,
    endpoints,
}: CommunicationPageProps) {
    const page = usePage<PageProps>();
    const canRespond =
        page.props.auth.can?.respond_patient_communications === true;
    const [inbox, setInbox] = useState(initialInbox);
    const [selectedUuid, setSelectedUuid] = useState<string | null>(null);
    const [detail, setDetail] = useState<CommunicationWorkItem | null>(null);
    const [query, setQuery] = useState("");
    const [queueFilter, setQueueFilter] = useState<QueueFilter>("all");
    const [unitFilter, setUnitFilter] = useState("all");
    const [poolFilter, setPoolFilter] = useState("all");
    const [replyBody, setReplyBody] = useState("");
    const [closeReason, setCloseReason] =
        useState<(typeof CLOSE_REASONS)[number][0]>("question_answered");
    const [loadingInbox, setLoadingInbox] = useState(false);
    const [loadingDetail, setLoadingDetail] = useState(false);
    const [loadingRouting, setLoadingRouting] = useState(false);
    const [routingCandidates, setRoutingCandidates] =
        useState<PatientCommunicationRouteCandidates | null>(null);
    const [releaseReason, setReleaseReason] = useState("");
    const [reassignReason, setReassignReason] = useState("");
    const [rerouteReason, setRerouteReason] = useState("");
    const [targetMembershipUuid, setTargetMembershipUuid] = useState("");
    const [targetPoolUuid, setTargetPoolUuid] = useState("");
    const [routingIntent, setRoutingIntent] = useState<RoutingIntent | null>(
        null,
    );
    const [mutation, setMutation] =
        useState<PatientCommunicationMutationKind | null>(null);
    const [uncertainCommand, setUncertainCommand] =
        useState<PatientCommunicationMutationCommand | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    // A user can move between queue rows faster than the network responds.
    // Only the newest authorized detail request may populate the reading pane.
    const inboxRequestSequence = useRef(0);
    const detailRequestSequence = useRef(0);
    const routingRequestSequence = useRef(0);
    const mutationRequestSequence = useRef(0);
    const inboxRef = useRef(inbox);
    const selectedUuidRef = useRef(selectedUuid);
    const detailRef = useRef(detail);
    const uncertainCommandRef = useRef(uncertainCommand);
    const inFlightMutationCommandRef =
        useRef<PatientCommunicationMutationCommand | null>(null);

    inboxRef.current = inbox;
    selectedUuidRef.current = selectedUuid;
    detailRef.current = detail;
    uncertainCommandRef.current = uncertainCommand;

    const setRetainedUncertainCommand = useCallback(
        (command: PatientCommunicationMutationCommand | null): void => {
            uncertainCommandRef.current = command;
            setUncertainCommand(command);
        },
        [],
    );

    const clearRoutingState = useCallback((): void => {
        routingRequestSequence.current += 1;
        setRoutingCandidates(null);
        setReleaseReason("");
        setReassignReason("");
        setRerouteReason("");
        setTargetMembershipUuid("");
        setTargetPoolUuid("");
        setRoutingIntent(null);
        setLoadingRouting(false);
    }, []);

    const purgeCommunicationState = useCallback(
        (
            workItemUuid: string | null,
            purgeInbox: boolean,
            preserveSafeUncertainReroute = false,
        ): void => {
            inboxRequestSequence.current += 1;
            detailRequestSequence.current += 1;
            clearRoutingState();

            const nextInbox = (() => {
                if (purgeInbox) return { items: [], count: 0 };
                if (workItemUuid === null) return inboxRef.current;
                const items = inboxRef.current.items.filter(
                    (item) => item.work_item_uuid !== workItemUuid,
                );
                return { items, count: items.length };
            })();
            inboxRef.current = nextInbox;
            setInbox(nextInbox);

            const affectsSelection =
                purgeInbox ||
                workItemUuid === null ||
                selectedUuidRef.current === workItemUuid ||
                detailRef.current?.work_item_uuid === workItemUuid;
            if (affectsSelection) {
                selectedUuidRef.current = null;
                detailRef.current = null;
                setSelectedUuid(null);
                setDetail(null);
                setReplyBody("");
                setCloseReason("question_answered");
                setLoadingDetail(false);
                setNotice(null);
            }

            const pendingCommand = uncertainCommandRef.current;
            const inFlightCommand = inFlightMutationCommandRef.current;
            const commandIsAffected = (
                command: PatientCommunicationMutationCommand | null,
            ): command is PatientCommunicationMutationCommand =>
                command !== null &&
                (purgeInbox ||
                    workItemUuid === null ||
                    command.workItemUuid === workItemUuid);
            const affectedPending = commandIsAffected(pendingCommand);
            const affectedInFlight = commandIsAffected(inFlightCommand);

            if (affectedInFlight) {
                mutationRequestSequence.current += 1;
                inFlightMutationCommandRef.current = null;
                setMutation(null);
            }

            if (affectedPending || affectedInFlight) {
                let safeReroute: PatientCommunicationMutationCommand | null =
                    null;
                if (preserveSafeUncertainReroute) {
                    if (affectedPending && pendingCommand?.kind === "reroute") {
                        safeReroute = pendingCommand;
                    } else if (
                        affectedInFlight &&
                        inFlightCommand?.kind === "reroute"
                    ) {
                        safeReroute = inFlightCommand;
                    }
                }
                setRetainedUncertainCommand(safeReroute);
            }

            setLoadingInbox(false);
        },
        [clearRoutingState, setRetainedUncertainCommand],
    );

    const availableUnits = useMemo(
        () =>
            Array.from(
                new Map(
                    inbox.items
                        .filter((item) => item.unit !== null)
                        .map((item) => [
                            String(item.unit!.id),
                            item.unit!.label,
                        ]),
                ).entries(),
            ).sort((left, right) => left[1].localeCompare(right[1])),
        [inbox.items],
    );

    const availablePools = useMemo(
        () =>
            Array.from(
                new Map(
                    inbox.items.map((item) => [
                        item.pool.pool_uuid,
                        item.pool.label,
                    ]),
                ).entries(),
            ).sort((left, right) => left[1].localeCompare(right[1])),
        [inbox.items],
    );

    const filteredItems = useMemo(() => {
        const needle = query.trim().toLocaleLowerCase();

        return inbox.items.filter((item) => {
            const matchesFilter =
                queueFilter === "all" ||
                (queueFilter === "escalated" &&
                    (item.is_escalation_due ||
                        item.ownership_state === "escalated")) ||
                (queueFilter === "due" && item.is_response_due) ||
                (queueFilter === "unassigned" && isClaimable(item)) ||
                (queueFilter === "mine" && item.assigned_to_me);
            if (!matchesFilter) return false;
            if (
                unitFilter !== "all" &&
                String(item.unit?.id ?? "") !== unitFilter
            )
                return false;
            if (poolFilter !== "all" && item.pool.pool_uuid !== poolFilter)
                return false;
            if (!needle) return true;

            return [
                item.topic.label,
                item.unit?.label,
                item.pool.label,
                contextLabel(item.patient_context_ref),
            ].some((value) => value?.toLocaleLowerCase().includes(needle));
        });
    }, [inbox.items, poolFilter, query, queueFilter, unitFilter]);

    const loadDetail = useCallback(
        async (workItemUuid: string): Promise<boolean> => {
            const requestSequence = ++detailRequestSequence.current;
            clearRoutingState();
            selectedUuidRef.current = workItemUuid;
            setSelectedUuid(workItemUuid);
            setLoadingDetail(true);
            setError(null);
            setNotice(null);

            try {
                const response = await axios.get<
                    DataEnvelope<CommunicationWorkItem>
                >(endpointFor(endpoints.thread, workItemUuid), {
                    headers: { Accept: "application/json" },
                });
                if (detailRequestSequence.current !== requestSequence)
                    return false;
                detailRef.current = response.data.data;
                setDetail(response.data.data);
                return true;
            } catch (requestError) {
                if (detailRequestSequence.current !== requestSequence)
                    return false;
                const failure = responseError(requestError);
                if (failure.status === 401) {
                    purgeCommunicationState(null, true);
                } else if (failure.status === 403 || failure.status === 404) {
                    purgeCommunicationState(workItemUuid, false, true);
                } else {
                    detailRef.current = null;
                    setDetail(null);
                }
                setError(failure.message);
                return false;
            } finally {
                if (detailRequestSequence.current === requestSequence) {
                    setLoadingDetail(false);
                }
            }
        },
        [clearRoutingState, endpoints.thread, purgeCommunicationState],
    );

    const loadRoutingCandidates = useCallback(
        async (workItem: CommunicationWorkItem): Promise<void> => {
            const requestSequence = ++routingRequestSequence.current;
            setLoadingRouting(true);
            setRoutingCandidates(null);
            setRoutingIntent(null);
            setError(null);

            try {
                const response = await axios.get<DataEnvelope<unknown>>(
                    endpointFor(
                        endpoints.routeCandidates,
                        workItem.work_item_uuid,
                    ),
                    {
                        headers: {
                            Accept: "application/json",
                            "Cache-Control": "no-store",
                            Pragma: "no-cache",
                        },
                    },
                );
                if (routingRequestSequence.current !== requestSequence) return;

                const parsed = parsePatientCommunicationRouteCandidates(
                    response.data.data,
                    workItem.work_item_uuid,
                );
                if (
                    parsed === null ||
                    parsed.work_item_version !== workItem.work_item_version ||
                    parsed.thread_version !== workItem.thread_version
                ) {
                    setError(
                        "Routing controls could not be verified against the current communication. Refresh the conversation before trying again.",
                    );
                    return;
                }

                setRoutingCandidates(parsed);
                setReleaseReason(parsed.reason_options.release[0]?.code ?? "");
                setReassignReason(
                    parsed.reason_options.reassign[0]?.code ?? "",
                );
                setRerouteReason(parsed.reason_options.reroute[0]?.code ?? "");
                setTargetMembershipUuid(
                    parsed.reassign_candidates[0]?.membership_uuid ?? "",
                );
                setTargetPoolUuid(
                    parsed.reroute_candidates[0]?.pool_uuid ?? "",
                );
            } catch (requestError) {
                if (routingRequestSequence.current !== requestSequence) return;
                const failure = responseError(requestError);
                setRoutingCandidates(null);
                setRoutingIntent(null);
                if (failure.status === 401) {
                    purgeCommunicationState(null, true);
                } else if (failure.status === 403 || failure.status === 404) {
                    purgeCommunicationState(
                        workItem.work_item_uuid,
                        false,
                        true,
                    );
                }
                setError(failure.message);
            } finally {
                if (routingRequestSequence.current === requestSequence) {
                    setLoadingRouting(false);
                }
            }
        },
        [endpoints.routeCandidates, purgeCommunicationState],
    );

    const clearRetainedProjectionForRefresh = useCallback(
        (workItemUuid: string): void => {
            detailRequestSequence.current += 1;
            clearRoutingState();
            detailRef.current = null;
            setDetail(null);
            setReplyBody("");
            setCloseReason("question_answered");
            setLoadingDetail(false);
            setNotice(null);

            const inFlightCommand = inFlightMutationCommandRef.current;
            if (inFlightCommand?.workItemUuid === workItemUuid) {
                mutationRequestSequence.current += 1;
                inFlightMutationCommandRef.current = null;
                setMutation(null);
                if (uncertainCommandRef.current === null) {
                    setRetainedUncertainCommand(inFlightCommand);
                }
            }
        },
        [clearRoutingState, setRetainedUncertainCommand],
    );

    const refreshInbox = useCallback(
        async (
            refreshSelectedDetail = false,
        ): Promise<CommunicationInbox | null> => {
            const requestSequence = ++inboxRequestSequence.current;
            setLoadingInbox(true);
            setError(null);

            const selectedAtRequest = selectedUuidRef.current;
            const previousSelectedProjection = selectedAtRequest
                ? detailRef.current?.work_item_uuid === selectedAtRequest
                    ? detailRef.current
                    : (inboxRef.current.items.find(
                          (item) => item.work_item_uuid === selectedAtRequest,
                      ) ?? null)
                : null;

            try {
                const response = await axios.get<
                    DataEnvelope<CommunicationInbox>
                >(endpoints.inbox, {
                    headers: {
                        Accept: "application/json",
                        "Cache-Control": "no-store",
                        Pragma: "no-cache",
                    },
                });
                if (inboxRequestSequence.current !== requestSequence)
                    return null;
                const nextInbox = response.data.data;
                inboxRef.current = nextInbox;
                setInbox(nextInbox);

                const currentSelectedUuid = selectedUuidRef.current;
                if (currentSelectedUuid) {
                    const retainedItem = nextInbox.items.find(
                        (item) => item.work_item_uuid === currentSelectedUuid,
                    );
                    if (!retainedItem) {
                        // Omission can mean discharge, transfer, closure, or a
                        // source-pool access change. Purge every affected
                        // projection; only an already-retained content-free
                        // uncertain reroute command is safe to keep.
                        purgeCommunicationState(
                            currentSelectedUuid,
                            false,
                            true,
                        );
                    } else {
                        const comparisonProjection =
                            currentSelectedUuid === selectedAtRequest
                                ? previousSelectedProjection
                                : detailRef.current?.work_item_uuid ===
                                    currentSelectedUuid
                                  ? detailRef.current
                                  : (inboxRef.current.items.find(
                                        (item) =>
                                            item.work_item_uuid ===
                                            currentSelectedUuid,
                                    ) ?? null);
                        const projectionDrifted =
                            comparisonProjection !== null &&
                            hasSelectedProjectionDrift(
                                comparisonProjection,
                                retainedItem,
                            );
                        if (projectionDrifted) {
                            clearRetainedProjectionForRefresh(
                                currentSelectedUuid,
                            );
                            await loadDetail(currentSelectedUuid);
                        } else if (refreshSelectedDetail) {
                            await loadDetail(currentSelectedUuid);
                        }
                    }
                }

                return nextInbox;
            } catch (requestError) {
                if (inboxRequestSequence.current !== requestSequence)
                    return null;
                const failure = responseError(requestError);
                if (failure.status === 401) {
                    purgeCommunicationState(null, true);
                } else if (failure.status === 403 || failure.status === 404) {
                    purgeCommunicationState(null, true);
                }
                setError(failure.message);
                return null;
            } finally {
                if (inboxRequestSequence.current === requestSequence) {
                    setLoadingInbox(false);
                }
            }
        },
        [
            clearRetainedProjectionForRefresh,
            endpoints.inbox,
            loadDetail,
            purgeCommunicationState,
        ],
    );

    useEffect(() => {
        let disposed = false;
        let pollTimer: number | null = null;
        let immediateTimer: number | null = null;
        let automatedRequest: Promise<CommunicationInbox | null> | null = null;
        let refreshRequestedAfterCurrent = false;

        const isActiveVisibleTab = (): boolean =>
            document.visibilityState === "visible" && document.hasFocus();

        const clearPollTimer = (): void => {
            if (pollTimer !== null) {
                window.clearTimeout(pollTimer);
                pollTimer = null;
            }
        };

        const clearImmediateTimer = (): void => {
            if (immediateTimer !== null) {
                window.clearTimeout(immediateTimer);
                immediateTimer = null;
            }
        };

        const schedulePoll = (): void => {
            clearPollTimer();
            if (disposed || !isActiveVisibleTab()) return;
            pollTimer = window.setTimeout(() => {
                pollTimer = null;
                triggerAutomatedRefresh();
            }, INBOX_POLL_INTERVAL_MS);
        };

        const triggerAutomatedRefresh = (): void => {
            if (disposed || !isActiveVisibleTab()) return;
            if (automatedRequest !== null) {
                refreshRequestedAfterCurrent = true;
                return;
            }

            const request = refreshInbox();
            automatedRequest = request;
            void request.finally(() => {
                if (automatedRequest === request) automatedRequest = null;
                if (disposed) return;
                if (refreshRequestedAfterCurrent && isActiveVisibleTab()) {
                    refreshRequestedAfterCurrent = false;
                    triggerAutomatedRefresh();
                    return;
                }
                refreshRequestedAfterCurrent = false;
                schedulePoll();
            });
        };

        const requestImmediateRefresh = (): void => {
            clearPollTimer();
            if (disposed || !isActiveVisibleTab() || immediateTimer !== null)
                return;
            // Focus and visibilitychange commonly arrive together. Coalesce
            // them into one immediate read without losing a refresh requested
            // while the previous automated read is still in flight.
            immediateTimer = window.setTimeout(() => {
                immediateTimer = null;
                triggerAutomatedRefresh();
            }, 0);
        };

        const pauseAutomatedRefresh = (): void => {
            clearPollTimer();
            clearImmediateTimer();
            refreshRequestedAfterCurrent = false;
            // A response initiated while this tab was active cannot publish
            // after the page becomes hidden or loses focus.
            inboxRequestSequence.current += 1;
            setLoadingInbox(false);
        };

        const handleVisibilityChange = (): void => {
            if (isActiveVisibleTab()) requestImmediateRefresh();
            else pauseAutomatedRefresh();
        };

        window.addEventListener("focus", requestImmediateRefresh);
        window.addEventListener("blur", pauseAutomatedRefresh);
        document.addEventListener("visibilitychange", handleVisibilityChange);
        schedulePoll();

        return () => {
            disposed = true;
            clearPollTimer();
            clearImmediateTimer();
            refreshRequestedAfterCurrent = false;
            inboxRequestSequence.current += 1;
            window.removeEventListener("focus", requestImmediateRefresh);
            window.removeEventListener("blur", pauseAutomatedRefresh);
            document.removeEventListener(
                "visibilitychange",
                handleVisibilityChange,
            );
        };
    }, [refreshInbox]);

    const reconcileAfterConflict = useCallback(
        async (workItemUuid: string): Promise<boolean> => {
            // A 409 is never blindly retried. Re-read both projections so the clinician
            // must make a fresh decision against the newest versions.
            const nextInbox = await refreshInbox(true);
            return (
                nextInbox !== null &&
                (selectedUuidRef.current === null ||
                    nextInbox.items.some(
                        (item) => item.work_item_uuid === workItemUuid,
                    ))
            );
        },
        [refreshInbox],
    );

    const executeMutation = useCallback(
        async (command: PatientCommunicationMutationCommand): Promise<void> => {
            if (
                inFlightMutationCommandRef.current !== null ||
                (uncertainCommandRef.current !== null &&
                    uncertainCommandRef.current !== command)
            )
                return;

            const mutationSequence = ++mutationRequestSequence.current;
            inFlightMutationCommandRef.current = command;
            const request = mutationRequest(command);
            setMutation(command.kind);
            setError(null);
            setNotice(null);

            try {
                const response = await axios.post<DataEnvelope<unknown>>(
                    request.url,
                    request.payload,
                    { headers: request.headers },
                );
                if (mutationRequestSequence.current !== mutationSequence)
                    return;
                if (!isVerifiedMutationOutcome(response.data.data, command)) {
                    throw new UnverifiedMutationOutcomeError();
                }
                inFlightMutationCommandRef.current = null;
                setRetainedUncertainCommand(null);
                clearRoutingState();
                if (command.kind === "reply") setReplyBody("");
                if (command.kind === "reroute") {
                    // Crossing responsibility pools is an access-revocation
                    // boundary. Never keep decrypted detail in memory while a
                    // fresh authorized inbox read determines current access.
                    detailRequestSequence.current += 1;
                    selectedUuidRef.current = null;
                    detailRef.current = null;
                    setSelectedUuid(null);
                    setDetail(null);
                    setReplyBody("");
                    const items = inboxRef.current.items.filter(
                        (item) => item.work_item_uuid !== command.workItemUuid,
                    );
                    inboxRef.current = { items, count: items.length };
                    setInbox(inboxRef.current);
                }

                const nextInbox = await refreshInbox(
                    command.kind !== "close" && command.kind !== "reroute",
                );
                if (mutationRequestSequence.current !== mutationSequence)
                    return;
                if (
                    command.kind === "close" ||
                    command.kind === "reroute" ||
                    !nextInbox?.items.some(
                        (item) => item.work_item_uuid === command.workItemUuid,
                    )
                ) {
                    detailRequestSequence.current += 1;
                    selectedUuidRef.current = null;
                    detailRef.current = null;
                    setSelectedUuid(null);
                    setDetail(null);
                }
                setNotice(command.successMessage);
            } catch (requestError) {
                if (mutationRequestSequence.current !== mutationSequence)
                    return;
                if (requestError instanceof UnverifiedMutationOutcomeError) {
                    if (command.kind === "reroute") {
                        // A malformed 2xx cannot prove whether the cross-pool
                        // transfer committed. Treat possible source-access
                        // revocation as a privacy boundary while retaining only
                        // the content-free exact command tuple for explicit retry.
                        purgeCommunicationState(
                            command.workItemUuid,
                            false,
                            true,
                        );
                    } else {
                        inFlightMutationCommandRef.current = null;
                        setRetainedUncertainCommand(command);
                    }
                    clearRoutingState();
                    setError(
                        "The outcome could not be confirmed. Actions are locked until you retry this exact request with its original replay key.",
                    );
                    return;
                }
                const failure = responseError(requestError);
                if (failure.status === 409) {
                    inFlightMutationCommandRef.current = null;
                    setRetainedUncertainCommand(null);
                    clearRoutingState();
                    const reconciled = await reconcileAfterConflict(
                        command.workItemUuid,
                    );
                    if (mutationRequestSequence.current !== mutationSequence)
                        return;
                    if (reconciled) {
                        setError(
                            `${failure.message} The latest communication state has been loaded; review it before acting again.`,
                        );
                    }
                } else if (isUnknownMutationOutcome(failure.status)) {
                    if (command.kind === "reroute") {
                        // A transport/5xx loss may follow a committed reroute.
                        // Do not retain the old pool's decrypted projection while
                        // its exact idempotent receipt remains unresolved.
                        purgeCommunicationState(
                            command.workItemUuid,
                            false,
                            true,
                        );
                    } else {
                        inFlightMutationCommandRef.current = null;
                        setRetainedUncertainCommand(command);
                    }
                    clearRoutingState();
                    setError(
                        "The outcome could not be confirmed. Actions are locked until you retry this exact request with its original replay key.",
                    );
                } else {
                    inFlightMutationCommandRef.current = null;
                    setRetainedUncertainCommand(null);
                    if (failure.status === 401) {
                        purgeCommunicationState(null, true);
                    } else if (
                        failure.status === 403 ||
                        failure.status === 404
                    ) {
                        purgeCommunicationState(command.workItemUuid, false);
                        await refreshInbox();
                    }
                    setError(failure.message);
                }
            } finally {
                if (mutationRequestSequence.current === mutationSequence) {
                    if (inFlightMutationCommandRef.current === command) {
                        inFlightMutationCommandRef.current = null;
                    }
                    setMutation(null);
                }
            }
        },
        [
            clearRoutingState,
            purgeCommunicationState,
            reconcileAfterConflict,
            refreshInbox,
            setRetainedUncertainCommand,
        ],
    );

    const performMutation = useCallback(
        (
            kind: PatientCommunicationMutationKind,
            payload: Record<string, string | number>,
            successMessage: string,
        ): void => {
            if (!detail || mutation !== null || uncertainCommand !== null)
                return;

            const command = createMutationCommand(
                kind,
                detail.work_item_uuid,
                endpointFor(endpoints[kind], detail.work_item_uuid),
                payload,
                successMessage,
            );
            void executeMutation(command);
        },
        [detail, endpoints, executeMutation, mutation, uncertainCommand],
    );

    const claim = (): void => {
        if (!detail) return;
        void performMutation(
            "claim",
            {
                work_item_version: detail.work_item_version,
                thread_version: detail.thread_version,
            },
            "The communication is now assigned to you.",
        );
    };

    const reply = (): void => {
        if (!detail || !replyBody.trim()) return;
        void performMutation(
            "reply",
            {
                work_item_version: detail.work_item_version,
                thread_version: detail.thread_version,
                message: replyBody.trim(),
                client_message_uuid: crypto.randomUUID(),
            },
            "Your patient-visible response was sent.",
        );
    };

    const close = (): void => {
        if (!detail) return;
        void performMutation(
            "close",
            {
                work_item_version: detail.work_item_version,
                thread_version: detail.thread_version,
                reason_code: closeReason,
            },
            "The communication was closed.",
        );
    };

    const reviewRelease = (): void => {
        if (!routingCandidates || !releaseReason) return;
        setRoutingIntent({
            kind: "release",
            title: "Release to the team queue?",
            description:
                "The current individual assignment will be removed. The conversation remains open and visible to eligible responders on the same team.",
            payload: {
                work_item_version: routingCandidates.work_item_version,
                thread_version: routingCandidates.thread_version,
                reason_code: releaseReason,
            },
            successMessage: "The communication was released to the team queue.",
        });
    };

    const reviewReassign = (): void => {
        if (!routingCandidates || !targetMembershipUuid || !reassignReason)
            return;
        const target = routingCandidates.reassign_candidates.find(
            (candidate) => candidate.membership_uuid === targetMembershipUuid,
        );
        if (!target) return;
        setRoutingIntent({
            kind: "reassign",
            title: `Reassign to ${target.label}?`,
            description:
                "This changes the accountable responder within the current team. The patient conversation and its audit history remain intact.",
            payload: {
                work_item_version: routingCandidates.work_item_version,
                thread_version: routingCandidates.thread_version,
                target_membership_uuid: target.membership_uuid,
                reason_code: reassignReason,
            },
            successMessage: `The communication was reassigned to ${target.label}.`,
        });
    };

    const reviewReroute = (): void => {
        if (!routingCandidates || !targetPoolUuid || !rerouteReason) return;
        const target = routingCandidates.reroute_candidates.find(
            (candidate) => candidate.pool_uuid === targetPoolUuid,
        );
        if (!target) return;
        setRoutingIntent({
            kind: "reroute",
            title: `Reroute to ${target.label}?`,
            description:
                "The current assignment will be removed and response timers will restart under the selected team's governed policy. You may lose access after confirmation.",
            payload: {
                work_item_version: routingCandidates.work_item_version,
                thread_version: routingCandidates.thread_version,
                target_pool_uuid: target.pool_uuid,
                reason_code: rerouteReason,
            },
            successMessage:
                "The communication was rerouted to the destination care team.",
        });
    };

    const confirmRoutingIntent = (): void => {
        if (!routingIntent) return;
        performMutation(
            routingIntent.kind,
            routingIntent.payload,
            routingIntent.successMessage,
        );
    };

    const retryUncertainMutation = (): void => {
        if (uncertainCommand && mutation === null) {
            void executeMutation(uncertainCommand);
        }
    };

    const refreshAll = (): void => {
        void refreshInbox(true);
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            Patient Communications
                        </h1>
                        <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Accountable care-team responses to non-urgent
                            inpatient questions
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={refreshAll}
                        disabled={loadingInbox || loadingDetail}
                        className="inline-flex min-h-10 items-center gap-2 rounded-md border border-healthcare-border bg-healthcare-surface px-3 py-2 text-sm font-semibold text-healthcare-text-primary hover:bg-healthcare-background disabled:cursor-not-allowed disabled:opacity-60 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark dark:hover:bg-white/5"
                    >
                        <RefreshCw
                            className={`size-4 ${loadingInbox || loadingDetail ? "animate-spin" : ""}`}
                            aria-hidden="true"
                        />
                        Refresh
                    </button>
                </div>
            }
        >
            <Head title="Patient Communications" />

            <div className="space-y-4 p-4 sm:p-6">
                <section
                    className="flex items-start gap-3 rounded-md border border-healthcare-warning/30 bg-healthcare-warning/5 p-4 text-sm text-healthcare-text-primary dark:border-healthcare-warning-dark/30 dark:bg-healthcare-warning-dark/10 dark:text-healthcare-text-primary-dark"
                    aria-label="Clinical escalation reminder"
                >
                    <ShieldAlert
                        className="mt-0.5 size-5 shrink-0 text-healthcare-warning dark:text-healthcare-warning-dark"
                        aria-hidden="true"
                    />
                    <div>
                        <div className="font-semibold">
                            This inbox is not an emergency channel.
                        </div>
                        <p className="mt-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Continue to use approved bedside and clinical
                            escalation pathways for urgent needs.
                            Patient-visible replies must not include staff-only
                            notes.
                        </p>
                    </div>
                </section>

                {(error || notice) && (
                    <div
                        role={error ? "alert" : "status"}
                        aria-live="polite"
                        className={`rounded-md border p-3 text-sm ${
                            error
                                ? "border-healthcare-critical/30 bg-healthcare-critical/5 text-healthcare-critical dark:border-healthcare-critical-dark/30 dark:bg-healthcare-critical-dark/10 dark:text-healthcare-critical-dark"
                                : "border-healthcare-success/30 bg-healthcare-success/5 text-healthcare-success dark:border-healthcare-success-dark/30 dark:bg-healthcare-success-dark/10 dark:text-healthcare-success-dark"
                        }`}
                    >
                        <div className="flex items-start gap-2">
                            {error ? (
                                <AlertCircle
                                    className="mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                            ) : (
                                <CheckCircle2
                                    className="mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                            )}
                            <span>{error ?? notice}</span>
                        </div>
                    </div>
                )}

                {uncertainCommand && (
                    <section
                        role="alert"
                        className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-healthcare-critical/30 bg-healthcare-critical/5 p-4 dark:border-healthcare-critical-dark/30 dark:bg-healthcare-critical-dark/10"
                    >
                        <div className="flex min-w-0 items-start gap-3">
                            <LockKeyhole
                                className="mt-0.5 size-5 shrink-0 text-healthcare-critical dark:text-healthcare-critical-dark"
                                aria-hidden="true"
                            />
                            <div>
                                <div className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    Unconfirmed {uncertainCommand.kind} request
                                </div>
                                <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    New actions and edits are locked. Exact
                                    retry reuses the original payload, client
                                    message UUID, and idempotency key so a
                                    committed response cannot be duplicated.
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={retryUncertainMutation}
                            disabled={mutation !== null}
                            className="inline-flex min-h-10 shrink-0 items-center gap-2 rounded-md bg-healthcare-primary px-4 py-2 text-sm font-semibold text-white hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {mutation ? (
                                <LoaderCircle
                                    className="size-4 animate-spin"
                                    aria-hidden="true"
                                />
                            ) : (
                                <RefreshCw
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            )}
                            Retry exact request
                        </button>
                    </section>
                )}

                <div className="grid min-h-[38rem] gap-4 lg:grid-cols-[minmax(19rem,0.8fr)_minmax(28rem,1.4fr)]">
                    <section
                        className="overflow-hidden rounded-md border border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"
                        aria-labelledby="queue-heading"
                    >
                        <div className="border-b border-healthcare-border p-4 dark:border-healthcare-border-dark">
                            <div className="flex items-center justify-between gap-3">
                                <h2
                                    id="queue-heading"
                                    className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
                                >
                                    Team inbox
                                </h2>
                                <span className="rounded-full bg-healthcare-background px-2 py-1 text-xs font-semibold text-healthcare-text-secondary dark:bg-white/5 dark:text-healthcare-text-secondary-dark">
                                    {inbox.count} open
                                </span>
                            </div>
                            <label className="relative mt-3 block">
                                <span className="sr-only">
                                    Search patient communications
                                </span>
                                <Search
                                    className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                    aria-hidden="true"
                                />
                                <input
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    placeholder="Search topic, unit, or team"
                                    className="min-h-10 w-full rounded-md border border-healthcare-border bg-healthcare-background py-2 pl-9 pr-3 text-sm text-healthcare-text-primary placeholder:text-healthcare-text-secondary focus:border-healthcare-primary focus:ring-healthcare-primary dark:border-healthcare-border-dark dark:bg-white/5 dark:text-healthcare-text-primary-dark dark:placeholder:text-healthcare-text-secondary-dark"
                                />
                            </label>
                            <label className="mt-3 block text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                Queue view
                                <select
                                    value={queueFilter}
                                    onChange={(event) =>
                                        setQueueFilter(
                                            event.target.value as QueueFilter,
                                        )
                                    }
                                    className="mt-1 min-h-10 w-full rounded-md border border-healthcare-border bg-healthcare-surface px-3 py-2 text-sm text-healthcare-text-primary focus:border-healthcare-primary focus:ring-healthcare-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark"
                                >
                                    <option value="all">
                                        All open communications
                                    </option>
                                    <option value="escalated">Escalated</option>
                                    <option value="due">Response due</option>
                                    <option value="unassigned">
                                        Unassigned
                                    </option>
                                    <option value="mine">Assigned to me</option>
                                </select>
                            </label>
                            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                <label className="block text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    Unit
                                    <select
                                        value={unitFilter}
                                        onChange={(event) =>
                                            setUnitFilter(event.target.value)
                                        }
                                        className="mt-1 min-h-10 w-full rounded-md border border-healthcare-border bg-healthcare-surface px-3 py-2 text-sm text-healthcare-text-primary focus:border-healthcare-primary focus:ring-healthcare-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark"
                                    >
                                        <option value="all">
                                            All authorized units
                                        </option>
                                        {availableUnits.map(([id, label]) => (
                                            <option key={id} value={id}>
                                                {label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="block text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    Responsible team
                                    <select
                                        value={poolFilter}
                                        onChange={(event) =>
                                            setPoolFilter(event.target.value)
                                        }
                                        className="mt-1 min-h-10 w-full rounded-md border border-healthcare-border bg-healthcare-surface px-3 py-2 text-sm text-healthcare-text-primary focus:border-healthcare-primary focus:ring-healthcare-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark"
                                    >
                                        <option value="all">
                                            All authorized teams
                                        </option>
                                        {availablePools.map(([uuid, label]) => (
                                            <option key={uuid} value={uuid}>
                                                {label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                            <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                Facility and service-line filters remain
                                unavailable until those governed fields are
                                added to the content-free staff projection.
                            </p>
                        </div>

                        <div
                            className="max-h-[31rem] overflow-y-auto"
                            aria-busy={loadingInbox}
                        >
                            {filteredItems.length === 0 && (
                                <div className="p-8 text-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    <Inbox
                                        className="mx-auto mb-3 size-8 opacity-60"
                                        aria-hidden="true"
                                    />
                                    {inbox.count === 0
                                        ? "No open communications are routed to your current teams."
                                        : "No communications match these filters."}
                                </div>
                            )}
                            {filteredItems.map((item) => (
                                <button
                                    key={item.work_item_uuid}
                                    type="button"
                                    onClick={() =>
                                        void loadDetail(item.work_item_uuid)
                                    }
                                    aria-current={
                                        selectedUuid === item.work_item_uuid
                                            ? "true"
                                            : undefined
                                    }
                                    className={`block w-full border-b border-healthcare-border p-4 text-left transition-colors last:border-b-0 dark:border-healthcare-border-dark ${
                                        selectedUuid === item.work_item_uuid
                                            ? "bg-healthcare-primary/10 dark:bg-healthcare-primary-dark/15"
                                            : "hover:bg-healthcare-background dark:hover:bg-white/5"
                                    }`}
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <div className="truncate font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                {item.topic.label}
                                            </div>
                                            <div className="mt-1 truncate text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                {contextLabel(
                                                    item.patient_context_ref,
                                                )}{" "}
                                                ·{" "}
                                                {item.unit?.label ??
                                                    "Unit unavailable"}
                                            </div>
                                        </div>
                                        <StatusBadge item={item} />
                                    </div>
                                    <div className="mt-3 flex items-center justify-between gap-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        <span className="truncate">
                                            {item.pool.label}
                                        </span>
                                        <time
                                            dateTime={
                                                item.last_message_at ??
                                                undefined
                                            }
                                        >
                                            {formatTimestamp(
                                                item.last_message_at,
                                            )}
                                        </time>
                                    </div>
                                </button>
                            ))}
                        </div>
                    </section>

                    <section
                        className="rounded-md border border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"
                        aria-labelledby="conversation-heading"
                    >
                        {!selectedUuid && (
                            <div className="flex min-h-[38rem] flex-col items-center justify-center p-8 text-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                <MessageSquare
                                    className="mb-4 size-10 opacity-60"
                                    aria-hidden="true"
                                />
                                <h2
                                    id="conversation-heading"
                                    className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
                                >
                                    Select a communication
                                </h2>
                                <p className="mt-2 max-w-md text-sm">
                                    Message content is loaded only after you
                                    open a communication routed to one of your
                                    active responsibility pools.
                                </p>
                            </div>
                        )}

                        {selectedUuid && loadingDetail && (
                            <div
                                className="flex min-h-[38rem] items-center justify-center gap-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                aria-live="polite"
                            >
                                <LoaderCircle
                                    className="size-5 animate-spin"
                                    aria-hidden="true"
                                />{" "}
                                Loading authorized conversation…
                            </div>
                        )}

                        {selectedUuid && !loadingDetail && detail && (
                            <div className="flex min-h-[38rem] flex-col">
                                <div className="border-b border-healthcare-border p-4 dark:border-healthcare-border-dark">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h2
                                                id="conversation-heading"
                                                className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
                                            >
                                                {detail.topic.label}
                                            </h2>
                                            <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                {contextLabel(
                                                    detail.patient_context_ref,
                                                )}{" "}
                                                ·{" "}
                                                {detail.unit?.label ??
                                                    "Unit unavailable"}{" "}
                                                · {detail.pool.label}
                                            </p>
                                        </div>
                                        <StatusBadge item={detail} />
                                    </div>
                                    <div className="mt-3 grid gap-2 text-xs text-healthcare-text-secondary sm:grid-cols-2 dark:text-healthcare-text-secondary-dark">
                                        <span>
                                            Response target:{" "}
                                            {formatTimestamp(detail.due_at)}
                                        </span>
                                        <span>
                                            Escalation target:{" "}
                                            {formatTimestamp(
                                                detail.escalate_at,
                                            )}
                                        </span>
                                    </div>
                                </div>

                                <div
                                    className="max-h-[24rem] flex-1 space-y-3 overflow-y-auto bg-healthcare-background/60 p-4 dark:bg-black/10"
                                    aria-label="Conversation messages"
                                >
                                    {detail.has_earlier_messages && (
                                        <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-2 text-center text-xs text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-secondary-dark">
                                            Earlier messages are not included in
                                            this safety-bounded view.
                                        </div>
                                    )}
                                    {(detail.messages ?? []).map((message) => {
                                        const fromCareTeam =
                                            message.sender_display_role.startsWith(
                                                "Care team",
                                            );
                                        return (
                                            <article
                                                key={message.message_uuid}
                                                className={`max-w-[88%] rounded-md border p-3 ${
                                                    fromCareTeam
                                                        ? "ml-auto border-healthcare-primary/25 bg-healthcare-primary/10 dark:bg-healthcare-primary-dark/15"
                                                        : "border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"
                                                }`}
                                            >
                                                <div className="flex flex-wrap items-center justify-between gap-2 text-xs">
                                                    <span className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                        {
                                                            message.sender_display_role
                                                        }
                                                    </span>
                                                    <time
                                                        className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                                        dateTime={
                                                            message.sent_at ??
                                                            undefined
                                                        }
                                                    >
                                                        {formatTimestamp(
                                                            message.sent_at,
                                                        )}
                                                    </time>
                                                </div>
                                                <p className="mt-2 whitespace-pre-wrap break-words text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {message.body ??
                                                        "Message content is unavailable."}
                                                </p>
                                                <div className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    {message.visibility ===
                                                    "patient_visible"
                                                        ? "Patient visible"
                                                        : "Care-team internal"}{" "}
                                                    · {message.delivery_state}
                                                </div>
                                            </article>
                                        );
                                    })}
                                </div>

                                <div className="space-y-3 border-t border-healthcare-border p-4 dark:border-healthcare-border-dark">
                                    {!canRespond && (
                                        <div className="flex items-center gap-2 rounded-md bg-healthcare-background p-3 text-sm text-healthcare-text-secondary dark:bg-white/5 dark:text-healthcare-text-secondary-dark">
                                            <LockKeyhole
                                                className="size-4"
                                                aria-hidden="true"
                                            />{" "}
                                            Your role has read-only access to
                                            this communication.
                                        </div>
                                    )}

                                    {canRespond &&
                                        isClaimable(detail) &&
                                        detail.status === "open" && (
                                            <button
                                                type="button"
                                                onClick={claim}
                                                disabled={
                                                    mutation !== null ||
                                                    uncertainCommand !== null
                                                }
                                                className="inline-flex min-h-10 items-center gap-2 rounded-md bg-healthcare-primary px-4 py-2 text-sm font-semibold text-white hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {mutation === "claim" ? (
                                                    <LoaderCircle
                                                        className="size-4 animate-spin"
                                                        aria-hidden="true"
                                                    />
                                                ) : (
                                                    <UserCheck
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                )}
                                                Assign to me
                                            </button>
                                        )}

                                    {canRespond &&
                                        !detail.assigned_to_me &&
                                        !isClaimable(detail) &&
                                        detail.status === "open" && (
                                            <div className="flex items-center gap-2 rounded-md bg-healthcare-background p-3 text-sm text-healthcare-text-secondary dark:bg-white/5 dark:text-healthcare-text-secondary-dark">
                                                <LockKeyhole
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />{" "}
                                                This communication is assigned
                                                to another responder.
                                            </div>
                                        )}

                                    {canRespond && detail.status === "open" && (
                                        <RoutingControls
                                            candidates={routingCandidates}
                                            loading={loadingRouting}
                                            blocked={uncertainCommand !== null}
                                            mutation={mutation}
                                            intent={routingIntent}
                                            releaseReason={releaseReason}
                                            reassignReason={reassignReason}
                                            rerouteReason={rerouteReason}
                                            targetMembershipUuid={
                                                targetMembershipUuid
                                            }
                                            targetPoolUuid={targetPoolUuid}
                                            onLoad={() =>
                                                void loadRoutingCandidates(
                                                    detail,
                                                )
                                            }
                                            onHide={() => {
                                                routingRequestSequence.current += 1;
                                                setLoadingRouting(false);
                                                setRoutingCandidates(null);
                                                setRoutingIntent(null);
                                            }}
                                            onReleaseReason={setReleaseReason}
                                            onReassignReason={setReassignReason}
                                            onRerouteReason={setRerouteReason}
                                            onTargetMembership={
                                                setTargetMembershipUuid
                                            }
                                            onTargetPool={setTargetPoolUuid}
                                            onReviewRelease={reviewRelease}
                                            onReviewReassign={reviewReassign}
                                            onReviewReroute={reviewReroute}
                                            onCancelIntent={() =>
                                                setRoutingIntent(null)
                                            }
                                            onConfirmIntent={
                                                confirmRoutingIntent
                                            }
                                        />
                                    )}

                                    {canRespond &&
                                        detail.assigned_to_me &&
                                        detail.status === "open" && (
                                            <>
                                                <label className="block text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    Patient-visible response
                                                    <textarea
                                                        value={replyBody}
                                                        onChange={(event) =>
                                                            setReplyBody(
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        disabled={
                                                            uncertainCommand !==
                                                            null
                                                        }
                                                        maxLength={4000}
                                                        rows={4}
                                                        placeholder="Write a clear, non-urgent response for the patient…"
                                                        className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-surface px-3 py-2 text-sm font-normal text-healthcare-text-primary placeholder:text-healthcare-text-secondary focus:border-healthcare-primary focus:ring-healthcare-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark dark:placeholder:text-healthcare-text-secondary-dark"
                                                    />
                                                </label>
                                                <div className="flex flex-wrap items-center justify-between gap-3">
                                                    <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                        {replyBody.length.toLocaleString()}
                                                        /4,000 characters · sent
                                                        exactly as
                                                        patient-visible text
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={reply}
                                                        disabled={
                                                            mutation !== null ||
                                                            uncertainCommand !==
                                                                null ||
                                                            replyBody.trim()
                                                                .length === 0
                                                        }
                                                        className="inline-flex min-h-10 items-center gap-2 rounded-md bg-healthcare-primary px-4 py-2 text-sm font-semibold text-white hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        {mutation ===
                                                        "reply" ? (
                                                            <LoaderCircle
                                                                className="size-4 animate-spin"
                                                                aria-hidden="true"
                                                            />
                                                        ) : (
                                                            <Send
                                                                className="size-4"
                                                                aria-hidden="true"
                                                            />
                                                        )}
                                                        Send response
                                                    </button>
                                                </div>

                                                <div className="flex flex-wrap items-end gap-3 border-t border-healthcare-border pt-3 dark:border-healthcare-border-dark">
                                                    <label className="min-w-60 flex-1 text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                        Closure reason
                                                        <select
                                                            value={closeReason}
                                                            onChange={(event) =>
                                                                setCloseReason(
                                                                    event.target
                                                                        .value as (typeof CLOSE_REASONS)[number][0],
                                                                )
                                                            }
                                                            disabled={
                                                                uncertainCommand !==
                                                                null
                                                            }
                                                            className="mt-1 min-h-10 w-full rounded-md border border-healthcare-border bg-healthcare-surface px-3 py-2 text-sm text-healthcare-text-primary focus:border-healthcare-primary focus:ring-healthcare-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark"
                                                        >
                                                            {CLOSE_REASONS.map(
                                                                ([
                                                                    value,
                                                                    label,
                                                                ]) => (
                                                                    <option
                                                                        key={
                                                                            value
                                                                        }
                                                                        value={
                                                                            value
                                                                        }
                                                                    >
                                                                        {label}
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </label>
                                                    <button
                                                        type="button"
                                                        onClick={close}
                                                        disabled={
                                                            mutation !== null ||
                                                            uncertainCommand !==
                                                                null ||
                                                            detail.ownership_state !==
                                                                "responded"
                                                        }
                                                        title={
                                                            detail.ownership_state !==
                                                            "responded"
                                                                ? "Send a patient-visible response before closing."
                                                                : undefined
                                                        }
                                                        className="inline-flex min-h-10 items-center gap-2 rounded-md border border-healthcare-border px-4 py-2 text-sm font-semibold text-healthcare-text-primary hover:bg-healthcare-background disabled:cursor-not-allowed disabled:opacity-50 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-white/5"
                                                    >
                                                        {mutation ===
                                                        "close" ? (
                                                            <LoaderCircle
                                                                className="size-4 animate-spin"
                                                                aria-hidden="true"
                                                            />
                                                        ) : (
                                                            <CheckCircle2
                                                                className="size-4"
                                                                aria-hidden="true"
                                                            />
                                                        )}
                                                        Close communication
                                                    </button>
                                                </div>
                                            </>
                                        )}
                                </div>
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
