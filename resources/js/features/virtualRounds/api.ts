// Thin axios transport for /api/rounds/*. Returns `unknown`; callers parse
// with the Zod schemas at the boundary. Mutations pass an Idempotency-Key
// and the expected version where the API requires one; 409 responses carry
// `current` (the fresh board) for recovery.
import axios from "axios";

function idempotencyHeaders(): Record<string, string> {
    const key =
        typeof crypto !== "undefined" && "randomUUID" in crypto
            ? crypto.randomUUID()
            : `${Date.now()}-${Math.random().toString(36).slice(2)}`;

    return { "Idempotency-Key": key };
}

export async function fetchRoundTemplates(): Promise<unknown> {
    const res = await axios.get("/api/rounds/templates");
    return res.data;
}

export async function fetchRoundScopes(): Promise<unknown> {
    const res = await axios.get("/api/rounds/scopes");
    return res.data;
}

export async function fetchRoundRuns(params?: {
    scope?: string;
    status?: string;
    date?: string;
}): Promise<unknown> {
    const res = await axios.get("/api/rounds/runs", { params });
    return res.data;
}

export async function fetchRoundBoard(runUuid: string): Promise<unknown> {
    const res = await axios.get(`/api/rounds/runs/${runUuid}/board`);
    return res.data;
}

// F-2 ruling: persona forwards the page lens so aggregate personas
// (patient_dots = none) receive the centroid-redacted projection.
export async function fetchRoundScene(
    runUuid: string,
    persona?: string,
): Promise<unknown> {
    const res = await axios.get(`/api/rounds/runs/${runUuid}/scene`, {
        params: persona ? { persona } : {},
    });
    return res.data;
}

export async function fetchRoundPatient(
    roundPatientUuid: string,
): Promise<unknown> {
    const res = await axios.get(`/api/rounds/patients/${roundPatientUuid}`);
    return res.data;
}

export async function fetchAvailablePatientQuestions(
    roundPatientUuid: string,
): Promise<unknown> {
    const res = await axios.get(
        `/api/rounds/patients/${roundPatientUuid}/patient-question-threads`,
    );
    return res.data;
}

export async function createRoundRun(input: {
    template_uuid: string;
    scope_type: string;
    scope_key: string;
    mode?: string;
}): Promise<unknown> {
    const res = await axios.post("/api/rounds/runs", input, {
        headers: idempotencyHeaders(),
    });
    return res.data;
}

export type RunLifecycleAction =
    "start" | "pause" | "resume" | "complete" | "cancel";

export async function runLifecycle(
    runUuid: string,
    action: RunLifecycleAction,
    body: { exception_reason?: string; reason?: string } = {},
): Promise<unknown> {
    const res = await axios.post(
        `/api/rounds/runs/${runUuid}/${action}`,
        body,
        {
            headers: idempotencyHeaders(),
        },
    );
    return res.data;
}

export async function reconcileCohort(
    runUuid: string,
    body: { add?: number[]; remove?: string[]; reason?: string } = {},
): Promise<unknown> {
    const res = await axios.post(
        `/api/rounds/runs/${runUuid}/cohort/reconcile`,
        body,
        {
            headers: idempotencyHeaders(),
        },
    );
    return res.data;
}

export async function reorderQueue(
    runUuid: string,
    order: string[],
    expectedQueueVersion: number,
): Promise<unknown> {
    const res = await axios.patch(
        `/api/rounds/runs/${runUuid}/queue`,
        { order, expected_queue_version: expectedQueueVersion },
        { headers: idempotencyHeaders() },
    );
    return res.data;
}

export type PatientTransitionAction =
    "mark-ready" | "complete" | "reopen" | "defer" | "skip";

export async function transitionPatient(
    roundPatientUuid: string,
    action: PatientTransitionAction,
    body: {
        expected_version?: number;
        reason?: string;
        exception_reason?: string;
    } = {},
): Promise<unknown> {
    const res = await axios.post(
        `/api/rounds/patients/${roundPatientUuid}/${action}`,
        body,
        {
            headers: idempotencyHeaders(),
        },
    );
    return res.data;
}

export async function pinPatient(
    roundPatientUuid: string,
    pinned: boolean,
    reason: string,
    expectedQueueVersion: number,
): Promise<unknown> {
    const res = await axios.post(
        `/api/rounds/patients/${roundPatientUuid}/pin`,
        { pinned, reason, expected_queue_version: expectedQueueVersion },
        { headers: idempotencyHeaders() },
    );
    return res.data;
}

export async function composeContribution(
    roundPatientUuid: string,
    body: {
        section_code: string;
        author_role?: string;
        structured_data?: Record<string, unknown>;
        summary?: string;
        submit?: boolean;
    },
): Promise<unknown> {
    const res = await axios.post(
        `/api/rounds/patients/${roundPatientUuid}/contributions`,
        body,
        {
            headers: idempotencyHeaders(),
        },
    );
    return res.data;
}

export async function createQuestion(
    roundPatientUuid: string,
    body: { question_text: string; target_role?: string; due_at?: string },
): Promise<unknown> {
    const res = await axios.post(
        `/api/rounds/patients/${roundPatientUuid}/questions`,
        body,
    );
    return res.data;
}

export async function promotePatientQuestion(
    roundPatientUuid: string,
    input: {
        thread_uuid: string;
        thread_version: number;
        message_uuid: string;
    },
): Promise<unknown> {
    const res = await axios.post(
        `/api/rounds/patients/${roundPatientUuid}/patient-question-threads/${input.thread_uuid}/promote`,
        {
            message_uuid: input.message_uuid,
            thread_version: input.thread_version,
        },
        { headers: idempotencyHeaders() },
    );
    return res.data;
}

export async function createTask(
    roundPatientUuid: string,
    body: {
        title: string;
        detail?: string;
        category?: string;
        owner_role?: string;
        due_at?: string;
    },
): Promise<unknown> {
    const res = await axios.post(
        `/api/rounds/patients/${roundPatientUuid}/tasks`,
        body,
    );
    return res.data;
}

export async function transitionTask(
    taskUuid: string,
    status: string,
): Promise<unknown> {
    const res = await axios.post(`/api/rounds/tasks/${taskUuid}/transition`, {
        status,
    });
    return res.data;
}
