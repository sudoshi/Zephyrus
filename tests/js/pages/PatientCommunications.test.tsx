import PatientCommunicationsIndex from "@/Pages/PatientCommunications/Index";
import { usePage } from "@inertiajs/react";
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from "@testing-library/react";
import axios, { AxiosError } from "axios";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("@/Layouts/AuthenticatedLayout", () => ({
    default: ({
        header,
        children,
    }: {
        header?: ReactNode;
        children: ReactNode;
    }) => (
        <div>
            {header}
            <main>{children}</main>
        </div>
    ),
}));

const workItem = {
    work_item_uuid: "019f0000-0000-7000-8000-000000000001",
    thread_uuid: "019f0000-0000-7000-8000-000000000002",
    patient_context_ref: "ptok_abcdef1234567890abcdef12",
    topic: { code: "care_question", label: "Question for my care team" },
    unit: { id: 85, label: "5 East — Medical/Surgical" },
    pool: {
        pool_uuid: "019f0000-0000-7000-8000-000000000003",
        label: "5 East Care Team",
    },
    status: "open",
    ownership_state: "pool_owned",
    assigned_to_me: false,
    work_item_version: 1,
    thread_version: 2,
    last_message_at: "2026-07-20T12:00:00Z",
    due_at: "2026-07-20T12:30:00Z",
    escalate_at: "2026-07-20T13:00:00Z",
    is_response_due: false,
    is_escalation_due: false,
    closed_at: null,
};

const detail = {
    ...workItem,
    messages: [
        {
            message_uuid: "019f0000-0000-7000-8000-000000000004",
            sender_display_role: "Patient",
            visibility: "patient_visible",
            message_kind: "message",
            body: "Could someone explain the next care step?",
            delivery_state: "sent",
            sent_at: "2026-07-20T12:00:00Z",
        },
    ],
    has_earlier_messages: false,
};

const endpoints = {
    inbox: "/patient-communications/inbox",
    thread: "/patient-communications/threads/__WORK_ITEM_UUID__",
    claim: "/patient-communications/threads/__WORK_ITEM_UUID__/claim",
    reply: "/patient-communications/threads/__WORK_ITEM_UUID__/reply",
    close: "/patient-communications/threads/__WORK_ITEM_UUID__/close",
    routeCandidates:
        "/patient-communications/threads/__WORK_ITEM_UUID__/route-candidates",
    release: "/patient-communications/threads/__WORK_ITEM_UUID__/release",
    reassign: "/patient-communications/threads/__WORK_ITEM_UUID__/reassign",
    reroute: "/patient-communications/threads/__WORK_ITEM_UUID__/reroute",
};

const routeCandidates = {
    work_item_uuid: workItem.work_item_uuid,
    work_item_version: workItem.work_item_version,
    thread_version: workItem.thread_version,
    actions: {
        can_release: false,
        can_reassign: false,
        can_reroute: true,
    },
    reason_options: {
        release: [{ code: "return_to_team", label: "Return to team queue" }],
        reassign: [
            { code: "supervisor_assignment", label: "Supervisor assignment" },
        ],
        reroute: [{ code: "wrong_team", label: "Wrong team" }],
    },
    reassign_candidates: [],
    reroute_candidates: [
        {
            pool_uuid: "019f0000-0000-7000-8000-000000000006",
            label: "Hospital Medicine Care Team",
            scope_type: "unit",
            unit: { id: 85, label: "5 East — Medical/Surgical" },
        },
    ],
};

function envelope<T>(data: T) {
    return {
        data: {
            data,
            meta: {
                as_of: "2026-07-20T12:01:00Z",
                classification: "patient_communication_restricted",
                offline_writes_allowed: false,
            },
        },
    };
}

function httpError(status: number, message: string): AxiosError {
    const error = new AxiosError(message);
    Object.defineProperty(error, "response", {
        value: {
            status,
            data: { error: { code: "request_failed", message } },
        },
    });
    return error;
}

describe("Patient Communications workspace", () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        vi.mocked(usePage).mockReturnValue({
            props: {
                auth: {
                    user: null,
                    can: { respond_patient_communications: true },
                },
            },
        } as ReturnType<typeof usePage>);
    });

    it("renders explicit authorized unit and responsible-team filters", () => {
        render(
            <PatientCommunicationsIndex
                initialInbox={{ items: [workItem], count: 1 }}
                endpoints={endpoints}
                auth={{ user: null }}
            />,
        );

        expect(screen.getByLabelText("Unit")).toHaveValue("all");
        expect(
            screen.getByRole("option", { name: workItem.unit.label }),
        ).toBeInTheDocument();
        expect(screen.getByLabelText("Responsible team")).toHaveValue("all");
        expect(
            screen.getByRole("option", { name: workItem.pool.label }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /Facility and service-line filters remain unavailable/i,
            ),
        ).toBeInTheDocument();
    });

    it("locks an unknown claim outcome and retries the exact command without changing its key or payload", async () => {
        const get = vi.spyOn(axios, "get");
        const post = vi.spyOn(axios, "post");
        get.mockResolvedValueOnce(envelope(detail))
            .mockResolvedValueOnce(
                envelope({
                    items: [
                        {
                            ...workItem,
                            assigned_to_me: true,
                            ownership_state: "acknowledged",
                        },
                    ],
                    count: 1,
                }),
            )
            .mockResolvedValueOnce(
                envelope({
                    ...detail,
                    assigned_to_me: true,
                    ownership_state: "acknowledged",
                }),
            );
        post.mockRejectedValueOnce(
            new AxiosError("The response was lost."),
        ).mockResolvedValueOnce(
            envelope({
                work_item: {
                    ...detail,
                    ownership_state: "acknowledged",
                    assigned_to_me: true,
                    work_item_version: 2,
                    thread_version: 3,
                },
                message: null,
                event_uuid: "019f0000-0000-7000-8000-000000000009",
                replayed: true,
            }),
        );
        vi.spyOn(globalThis.crypto, "randomUUID").mockReturnValue(
            "019f0000-0000-7000-8000-000000000005",
        );

        render(
            <PatientCommunicationsIndex
                initialInbox={{ items: [workItem], count: 1 }}
                endpoints={endpoints}
                auth={{ user: null }}
            />,
        );

        fireEvent.click(
            screen.getByRole("button", { name: /Question for my care team/i }),
        );
        fireEvent.click(
            await screen.findByRole("button", { name: "Assign to me" }),
        );

        const retry = await screen.findByRole("button", {
            name: "Retry exact request",
        });
        expect(
            screen.getByText(/New actions and edits are locked/i),
        ).toBeInTheDocument();
        const firstAttempt = post.mock.calls[0];

        fireEvent.click(retry);

        await waitFor(() => expect(post).toHaveBeenCalledTimes(2));
        expect(post.mock.calls[1]).toEqual(firstAttempt);
        expect(firstAttempt[2]?.headers?.["Idempotency-Key"]).toBe(
            "019f0000-0000-7000-8000-000000000005",
        );
        expect(
            await screen.findByText(
                "The communication is now assigned to you.",
            ),
        ).toBeInTheDocument();
    });

    it("rejects a null work item for a non-reroute mutation and keeps the exact retry lock", async () => {
        vi.spyOn(axios, "get").mockResolvedValueOnce(envelope(detail));
        vi.spyOn(axios, "post").mockResolvedValueOnce(
            envelope({
                work_item: null,
                message: null,
                event_uuid: "019f0000-0000-7000-8000-000000000009",
                replayed: true,
            }),
        );
        vi.spyOn(globalThis.crypto, "randomUUID").mockReturnValue(
            "019f0000-0000-7000-8000-000000000005",
        );

        render(
            <PatientCommunicationsIndex
                initialInbox={{ items: [workItem], count: 1 }}
                endpoints={endpoints}
                auth={{ user: null }}
            />,
        );

        fireEvent.click(
            screen.getByRole("button", { name: /Question for my care team/i }),
        );
        fireEvent.click(
            await screen.findByRole("button", { name: "Assign to me" }),
        );

        expect(
            await screen.findByRole("button", { name: "Retry exact request" }),
        ).toBeInTheDocument();
        expect(
            screen.getByText("Could someone explain the next care step?"),
        ).toBeInTheDocument();
        expect(
            screen.queryByText("The communication is now assigned to you."),
        ).not.toBeInTheDocument();
    });

    it("rejects an unadvanced successful projection and keeps the exact retry lock", async () => {
        vi.spyOn(axios, "get").mockResolvedValueOnce(envelope(detail));
        vi.spyOn(axios, "post").mockResolvedValueOnce(
            envelope({
                work_item: {
                    ...detail,
                    assigned_to_me: true,
                    ownership_state: "acknowledged",
                },
                message: null,
                event_uuid: "019f0000-0000-7000-8000-000000000009",
                replayed: false,
            }),
        );

        render(
            <PatientCommunicationsIndex
                initialInbox={{ items: [workItem], count: 1 }}
                endpoints={endpoints}
                auth={{ user: null }}
            />,
        );

        fireEvent.click(
            screen.getByRole("button", { name: /Question for my care team/i }),
        );
        fireEvent.click(
            await screen.findByRole("button", { name: "Assign to me" }),
        );

        expect(
            await screen.findByRole("button", { name: "Retry exact request" }),
        ).toBeInTheDocument();
        expect(
            screen.queryByText("The communication is now assigned to you."),
        ).not.toBeInTheDocument();
    });

    it("purges inbox and decrypted detail when a mutation loses authentication", async () => {
        vi.spyOn(axios, "get").mockResolvedValueOnce(envelope(detail));
        vi.spyOn(axios, "post").mockRejectedValueOnce(
            httpError(401, "Your session expired."),
        );

        render(
            <PatientCommunicationsIndex
                initialInbox={{ items: [workItem], count: 1 }}
                endpoints={endpoints}
                auth={{ user: null }}
            />,
        );

        fireEvent.click(
            screen.getByRole("button", { name: /Question for my care team/i }),
        );
        fireEvent.click(
            await screen.findByRole("button", { name: "Assign to me" }),
        );

        await waitFor(() => {
            expect(
                screen.queryByText("Could someone explain the next care step?"),
            ).not.toBeInTheDocument();
        });
        expect(
            screen.queryByRole("button", {
                name: /Question for my care team/i,
            }),
        ).not.toBeInTheDocument();
        expect(screen.getByText("Your session expired.")).toBeInTheDocument();
    });

    it("purges stale detail when conflict reconciliation loses inbox authorization", async () => {
        vi.spyOn(axios, "get")
            .mockResolvedValueOnce(envelope(detail))
            .mockRejectedValueOnce(httpError(401, "Your session expired."));
        vi.spyOn(axios, "post").mockRejectedValueOnce(
            httpError(409, "The communication changed."),
        );

        render(
            <PatientCommunicationsIndex
                initialInbox={{ items: [workItem], count: 1 }}
                endpoints={endpoints}
                auth={{ user: null }}
            />,
        );

        fireEvent.click(
            screen.getByRole("button", { name: /Question for my care team/i }),
        );
        fireEvent.click(
            await screen.findByRole("button", { name: "Assign to me" }),
        );

        await waitFor(() => {
            expect(
                screen.queryByText("Could someone explain the next care step?"),
            ).not.toBeInTheDocument();
        });
        expect(screen.getByText("Your session expired.")).toBeInTheDocument();
        expect(
            screen.queryByText(/latest communication state has been loaded/i),
        ).not.toBeInTheDocument();
    });

    it("purges prior detail and draft state when a subsequent detail read is unauthorized", async () => {
        vi.spyOn(axios, "get")
            .mockResolvedValueOnce(envelope(detail))
            .mockRejectedValueOnce(httpError(403, "Access changed."));

        render(
            <PatientCommunicationsIndex
                initialInbox={{ items: [workItem], count: 1 }}
                endpoints={endpoints}
                auth={{ user: null }}
            />,
        );

        const row = screen.getByRole("button", {
            name: /Question for my care team/i,
        });
        fireEvent.click(row);
        expect(
            await screen.findByText(
                "Could someone explain the next care step?",
            ),
        ).toBeInTheDocument();
        fireEvent.click(row);

        await waitFor(() => {
            expect(
                screen.queryByText("Could someone explain the next care step?"),
            ).not.toBeInTheDocument();
        });
        expect(screen.getByText("Access changed.")).toBeInTheDocument();
        expect(
            screen.queryByRole("button", {
                name: /Question for my care team/i,
            }),
        ).not.toBeInTheDocument();
    });

    it("ignores a stale detail response after the user opens a newer communication", async () => {
        const secondItem = {
            ...workItem,
            work_item_uuid: "019f0000-0000-7000-8000-000000000010",
            thread_uuid: "019f0000-0000-7000-8000-000000000011",
            topic: { code: "discharge", label: "Discharge question" },
        };
        const secondDetail = {
            ...secondItem,
            messages: [
                {
                    ...detail.messages[0],
                    message_uuid: "019f0000-0000-7000-8000-000000000012",
                    body: "What should I prepare before I leave?",
                },
            ],
            has_earlier_messages: false,
        };
        let resolveFirst!: (value: ReturnType<typeof envelope>) => void;
        let resolveSecond!: (value: ReturnType<typeof envelope>) => void;
        const firstResponse = new Promise<ReturnType<typeof envelope>>(
            (resolve) => {
                resolveFirst = resolve;
            },
        );
        const secondResponse = new Promise<ReturnType<typeof envelope>>(
            (resolve) => {
                resolveSecond = resolve;
            },
        );
        vi.spyOn(axios, "get")
            .mockImplementationOnce(() => firstResponse)
            .mockImplementationOnce(() => secondResponse);

        render(
            <PatientCommunicationsIndex
                initialInbox={{ items: [workItem, secondItem], count: 2 }}
                endpoints={endpoints}
                auth={{ user: null }}
            />,
        );

        fireEvent.click(
            screen.getByRole("button", { name: /Question for my care team/i }),
        );
        fireEvent.click(
            screen.getByRole("button", { name: /Discharge question/i }),
        );

        await act(async () => {
            resolveSecond(envelope(secondDetail));
            await secondResponse;
        });
        expect(
            await screen.findByText("What should I prepare before I leave?"),
        ).toBeInTheDocument();

        await act(async () => {
            resolveFirst(envelope(detail));
            await firstResponse;
        });
        await waitFor(() =>
            expect(
                screen.queryByText("Could someone explain the next care step?"),
            ).not.toBeInTheDocument(),
        );
        expect(
            screen.getByText("What should I prepare before I leave?"),
        ).toBeInTheDocument();
    });

    it("does not offer claim when the communication is assigned to another responder", async () => {
        const assignedElsewhere = {
            ...detail,
            ownership_state: "acknowledged",
            assigned_to_me: false,
        };
        vi.spyOn(axios, "get").mockResolvedValueOnce(
            envelope(assignedElsewhere),
        );

        render(
            <PatientCommunicationsIndex
                initialInbox={{
                    items: [assignedElsewhere],
                    count: 1,
                }}
                endpoints={endpoints}
                auth={{ user: null }}
            />,
        );

        fireEvent.click(
            screen.getByRole("button", { name: /Question for my care team/i }),
        );

        expect(
            await screen.findByText(
                "This communication is assigned to another responder.",
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole("button", { name: "Assign to me" }),
        ).not.toBeInTheDocument();
    });

    it("loads governed routing candidates only on request and confirms an opaque reroute payload", async () => {
        const get = vi.spyOn(axios, "get");
        const post = vi.spyOn(axios, "post");
        get.mockResolvedValueOnce(envelope(detail))
            .mockResolvedValueOnce(envelope(routeCandidates))
            .mockResolvedValueOnce(envelope({ items: [], count: 0 }));
        post.mockResolvedValueOnce(
            envelope({
                work_item: {
                    ...detail,
                    pool: {
                        pool_uuid:
                            routeCandidates.reroute_candidates[0].pool_uuid,
                        label: routeCandidates.reroute_candidates[0].label,
                    },
                    ownership_state: "rerouted",
                    work_item_version: 2,
                    thread_version: 3,
                },
                message: null,
                event_uuid: "019f0000-0000-7000-8000-000000000008",
                replayed: false,
            }),
        );
        vi.spyOn(globalThis.crypto, "randomUUID").mockReturnValue(
            "019f0000-0000-7000-8000-000000000007",
        );

        render(
            <PatientCommunicationsIndex
                initialInbox={{ items: [workItem], count: 1 }}
                endpoints={endpoints}
                auth={{ user: null }}
            />,
        );

        fireEvent.click(
            screen.getByRole("button", { name: /Question for my care team/i }),
        );
        fireEvent.click(
            await screen.findByRole("button", { name: "Manage routing" }),
        );

        expect(get).toHaveBeenNthCalledWith(
            2,
            `/patient-communications/threads/${workItem.work_item_uuid}/route-candidates`,
            expect.objectContaining({
                headers: expect.objectContaining({
                    "Cache-Control": "no-store",
                    Pragma: "no-cache",
                }),
            }),
        );
        fireEvent.click(
            await screen.findByRole("button", { name: "Review reroute" }),
        );
        expect(
            screen.getByRole("alertdialog", {
                name: "Reroute to Hospital Medicine Care Team?",
            }),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole("button", { name: "Confirm reroute" }),
        );

        await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
        expect(post).toHaveBeenCalledWith(
            `/patient-communications/threads/${workItem.work_item_uuid}/reroute`,
            {
                work_item_version: workItem.work_item_version,
                thread_version: workItem.thread_version,
                target_pool_uuid:
                    routeCandidates.reroute_candidates[0].pool_uuid,
                reason_code: "wrong_team",
            },
            {
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "Idempotency-Key": "019f0000-0000-7000-8000-000000000007",
                },
            },
        );
        await waitFor(() => {
            expect(
                screen.queryByText("Could someone explain the next care step?"),
            ).not.toBeInTheDocument();
        });
    });

    it("accepts only a content-minimized exact reroute replay and purges decrypted detail", async () => {
        const get = vi.spyOn(axios, "get");
        const post = vi.spyOn(axios, "post");
        get.mockResolvedValueOnce(envelope(detail))
            .mockResolvedValueOnce(envelope(routeCandidates))
            .mockResolvedValueOnce(envelope({ items: [], count: 0 }));
        post.mockRejectedValueOnce(
            new AxiosError("The reroute response was lost."),
        ).mockResolvedValueOnce(
            envelope({
                work_item: null,
                message: null,
                event_uuid: "019f0000-0000-7000-8000-000000000008",
                replayed: true,
            }),
        );
        vi.spyOn(globalThis.crypto, "randomUUID").mockReturnValue(
            "019f0000-0000-7000-8000-000000000007",
        );

        render(
            <PatientCommunicationsIndex
                initialInbox={{ items: [workItem], count: 1 }}
                endpoints={endpoints}
                auth={{ user: null }}
            />,
        );

        fireEvent.click(
            screen.getByRole("button", { name: /Question for my care team/i }),
        );
        fireEvent.click(
            await screen.findByRole("button", { name: "Manage routing" }),
        );
        fireEvent.click(
            await screen.findByRole("button", { name: "Review reroute" }),
        );
        fireEvent.click(
            screen.getByRole("button", { name: "Confirm reroute" }),
        );

        const retry = await screen.findByRole("button", {
            name: "Retry exact request",
        });
        expect(
            screen.queryByText("Could someone explain the next care step?"),
        ).not.toBeInTheDocument();
        const firstAttempt = post.mock.calls[0];

        fireEvent.click(retry);

        await waitFor(() => expect(post).toHaveBeenCalledTimes(2));
        expect(post.mock.calls[1]).toEqual(firstAttempt);
        await waitFor(() => {
            expect(
                screen.queryByText("Could someone explain the next care step?"),
            ).not.toBeInTheDocument();
        });
        expect(
            await screen.findByText(
                "The communication was rerouted to the destination care team.",
            ),
        ).toBeInTheDocument();
    });

    it("purges an unverified reroute 2xx before replaying the identical opaque command", async () => {
        const get = vi.spyOn(axios, "get");
        const post = vi.spyOn(axios, "post");
        get.mockResolvedValueOnce(envelope(detail))
            .mockResolvedValueOnce(envelope(routeCandidates))
            .mockResolvedValueOnce(envelope({ items: [], count: 0 }));
        post.mockResolvedValueOnce(
            envelope({
                work_item: {
                    ...detail,
                    work_item_uuid:
                        "019f0000-0000-7000-8000-000000000099",
                    work_item_version: 1,
                    thread_version: 2,
                },
                message: null,
                event_uuid: "019f0000-0000-7000-8000-000000000008",
                replayed: false,
            }),
        ).mockResolvedValueOnce(
            envelope({
                work_item: null,
                message: null,
                event_uuid: "019f0000-0000-7000-8000-000000000008",
                replayed: true,
            }),
        );
        vi.spyOn(globalThis.crypto, "randomUUID").mockReturnValue(
            "019f0000-0000-7000-8000-000000000007",
        );

        render(
            <PatientCommunicationsIndex
                initialInbox={{ items: [workItem], count: 1 }}
                endpoints={endpoints}
                auth={{ user: null }}
            />,
        );

        fireEvent.click(
            screen.getByRole("button", { name: /Question for my care team/i }),
        );
        fireEvent.click(
            await screen.findByRole("button", { name: "Manage routing" }),
        );
        fireEvent.click(
            await screen.findByRole("button", { name: "Review reroute" }),
        );
        fireEvent.click(
            screen.getByRole("button", { name: "Confirm reroute" }),
        );

        const retry = await screen.findByRole("button", {
            name: "Retry exact request",
        });
        expect(
            screen.queryByText("Could someone explain the next care step?"),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText(routeCandidates.reroute_candidates[0].label),
        ).not.toBeInTheDocument();
        const firstAttempt = post.mock.calls[0];

        fireEvent.click(retry);

        await waitFor(() => expect(post).toHaveBeenCalledTimes(2));
        expect(post.mock.calls[1]).toEqual(firstAttempt);
        expect(
            await screen.findByText(
                "The communication was rerouted to the destination care team.",
            ),
        ).toBeInTheDocument();
    });
});
