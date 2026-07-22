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
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

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

const sourceUuid = "019f0000-0000-7000-8000-000000000101";
const destinationUuid = "019f0000-0000-7000-8000-000000000201";
const sourcePoolUuid = "019f0000-0000-7000-8000-000000000301";
const destinationPoolUuid = "019f0000-0000-7000-8000-000000000401";

const sourceItem = {
    work_item_uuid: sourceUuid,
    thread_uuid: "019f0000-0000-7000-8000-000000000102",
    patient_context_ref: "ptok_transition_source_1234",
    topic: { code: "care_question", label: "Source care question" },
    unit: { id: 85, label: "5 East — Medical/Surgical" },
    pool: { pool_uuid: sourcePoolUuid, label: "5 East Care Team" },
    status: "open",
    ownership_state: "acknowledged",
    assigned_to_me: true,
    work_item_version: 4,
    thread_version: 7,
    last_message_at: "2026-07-20T12:00:00Z",
    due_at: "2026-07-20T12:30:00Z",
    escalate_at: "2026-07-20T13:00:00Z",
    is_response_due: false,
    is_escalation_due: false,
    closed_at: null,
};

const sourceDetail = {
    ...sourceItem,
    messages: [
        {
            message_uuid: "019f0000-0000-7000-8000-000000000103",
            sender_display_role: "Patient",
            visibility: "patient_visible",
            message_kind: "message",
            body: "Source detail must not survive a server transition.",
            delivery_state: "sent",
            sent_at: "2026-07-20T12:00:00Z",
        },
    ],
    has_earlier_messages: false,
};

const destinationItem = {
    ...sourceItem,
    work_item_uuid: destinationUuid,
    pool: {
        pool_uuid: destinationPoolUuid,
        label: "Hospital Medicine Care Team",
    },
    ownership_state: "rerouted",
    assigned_to_me: false,
    work_item_version: 5,
    thread_version: 8,
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
    work_item_uuid: sourceUuid,
    work_item_version: sourceItem.work_item_version,
    thread_version: sourceItem.thread_version,
    actions: { can_release: false, can_reassign: false, can_reroute: true },
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
            pool_uuid: destinationPoolUuid,
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

function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (reason?: unknown) => void;
    const promise = new Promise<T>((resolvePromise, rejectPromise) => {
        resolve = resolvePromise;
        reject = rejectPromise;
    });
    return { promise, resolve, reject };
}

function renderWorkspace(items = [sourceItem]) {
    return render(
        <PatientCommunicationsIndex
            initialInbox={{ items, count: items.length }}
            endpoints={endpoints}
            auth={{ user: null }}
        />,
    );
}

function inactiveTab() {
    const focus = vi.spyOn(document, "hasFocus").mockReturnValue(false);
    const visibility = vi
        .spyOn(document, "visibilityState", "get")
        .mockReturnValue("visible");
    return {
        activate() {
            focus.mockReturnValue(true);
            window.dispatchEvent(new Event("focus"));
        },
        focus,
        visibility,
    };
}

describe("Patient Communications server-driven transition polling", () => {
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

    afterEach(() => {
        vi.useRealTimers();
    });

    it("purges a discharged or source-omitted item with its detail, draft, and routing projection", async () => {
        const tab = inactiveTab();
        const get = vi.spyOn(axios, "get").mockImplementation((url) => {
            if (url === endpoints.inbox)
                return Promise.resolve(envelope({ items: [], count: 0 }));
            if (String(url).endsWith("/route-candidates"))
                return Promise.resolve(envelope(routeCandidates));
            return Promise.resolve(envelope(sourceDetail));
        });
        const post = vi.spyOn(axios, "post");

        renderWorkspace();
        fireEvent.click(
            screen.getByRole("button", { name: /Source care question/i }),
        );
        expect(
            await screen.findByText(
                "Source detail must not survive a server transition.",
            ),
        ).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText("Patient-visible response"), {
            target: { value: "A draft that must be purged." },
        });
        fireEvent.click(screen.getByRole("button", { name: "Manage routing" }));
        expect(
            await screen.findByRole("button", { name: "Review reroute" }),
        ).toBeInTheDocument();

        tab.activate();

        await waitFor(() =>
            expect(
                screen.queryByText(
                    "Source detail must not survive a server transition.",
                ),
            ).not.toBeInTheDocument(),
        );
        expect(
            screen.queryByRole("button", { name: /Source care question/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByDisplayValue("A draft that must be purged."),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole("button", { name: "Review reroute" }),
        ).not.toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();
        expect(
            get.mock.calls.filter(([url]) => url === endpoints.inbox),
        ).toHaveLength(1);
    });

    it("detects retained same-UUID dual-pool drift and refetches detail exactly once", async () => {
        const tab = inactiveTab();
        const refreshedDetail = deferred<ReturnType<typeof envelope>>();
        const driftedItem = {
            ...sourceItem,
            pool: {
                pool_uuid: destinationPoolUuid,
                label: "Hospital Medicine Care Team",
            },
            ownership_state: "pool_owned",
            assigned_to_me: false,
            work_item_version: 5,
            thread_version: 8,
        };
        const companionItem = {
            ...destinationItem,
            work_item_uuid: "019f0000-0000-7000-8000-000000000202",
            topic: { code: "discharge", label: "Destination discharge item" },
        };
        let detailReads = 0;
        vi.spyOn(axios, "get").mockImplementation((url) => {
            if (url === endpoints.inbox) {
                return Promise.resolve(
                    envelope({ items: [driftedItem, companionItem], count: 2 }),
                );
            }
            detailReads += 1;
            return detailReads === 1
                ? Promise.resolve(envelope(sourceDetail))
                : refreshedDetail.promise;
        });

        renderWorkspace();
        fireEvent.click(
            screen.getByRole("button", { name: /Source care question/i }),
        );
        expect(
            await screen.findByText(
                "Source detail must not survive a server transition.",
            ),
        ).toBeInTheDocument();

        tab.activate();

        await waitFor(() => expect(detailReads).toBe(2));
        expect(
            screen.queryByText(
                "Source detail must not survive a server transition.",
            ),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole("button", { name: /Destination discharge item/i }),
        ).toBeInTheDocument();

        await act(async () => {
            refreshedDetail.resolve(
                envelope({
                    ...sourceDetail,
                    ...driftedItem,
                    messages: [
                        {
                            ...sourceDetail.messages[0],
                            body: "Fresh detail from the destination pool.",
                        },
                    ],
                }),
            );
            await refreshedDetail.promise;
        });
        expect(
            await screen.findByText("Fresh detail from the destination pool."),
        ).toBeInTheDocument();
        expect(detailReads).toBe(2);
    });

    it("shows a new destination row while fully purging the omitted source row", async () => {
        const tab = inactiveTab();
        const get = vi
            .spyOn(axios, "get")
            .mockImplementation((url) =>
                url === endpoints.inbox
                    ? Promise.resolve(
                          envelope({ items: [destinationItem], count: 1 }),
                      )
                    : Promise.resolve(envelope(sourceDetail)),
            );
        const post = vi.spyOn(axios, "post");

        renderWorkspace();
        fireEvent.click(
            screen.getByRole("button", { name: /Source care question/i }),
        );
        await screen.findByText(
            "Source detail must not survive a server transition.",
        );

        tab.activate();

        expect(
            await screen.findByRole("button", {
                name: /Source care question.*Hospital Medicine Care Team/i,
            }),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(
                "Source detail must not survive a server transition.",
            ),
        ).not.toBeInTheDocument();
        expect(
            get.mock.calls.filter(([url]) =>
                String(url).includes(destinationUuid),
            ),
        ).toHaveLength(0);
        expect(post).not.toHaveBeenCalled();
    });

    it("fences a stale in-flight detail response after source omission", async () => {
        const tab = inactiveTab();
        const staleDetail = deferred<ReturnType<typeof envelope>>();
        vi.spyOn(axios, "get").mockImplementation((url) =>
            url === endpoints.inbox
                ? Promise.resolve(envelope({ items: [], count: 0 }))
                : staleDetail.promise,
        );

        renderWorkspace();
        fireEvent.click(
            screen.getByRole("button", { name: /Source care question/i }),
        );
        expect(
            await screen.findByText("Loading authorized conversation…"),
        ).toBeInTheDocument();

        tab.activate();
        await waitFor(() =>
            expect(
                screen.queryByRole("button", { name: /Source care question/i }),
            ).not.toBeInTheDocument(),
        );

        await act(async () => {
            staleDetail.resolve(envelope(sourceDetail));
            await staleDetail.promise;
        });
        expect(
            screen.queryByText(
                "Source detail must not survive a server transition.",
            ),
        ).not.toBeInTheDocument();
        expect(screen.getByText("Select a communication")).toBeInTheDocument();
    });

    it("polls only while focused and visible, resumes immediately, and never overlaps", async () => {
        vi.useFakeTimers();
        let visible = true;
        let focused = true;
        vi.spyOn(document, "visibilityState", "get").mockImplementation(() =>
            visible ? "visible" : "hidden",
        );
        vi.spyOn(document, "hasFocus").mockImplementation(() => focused);
        const firstPoll = deferred<ReturnType<typeof envelope>>();
        const get = vi
            .spyOn(axios, "get")
            .mockImplementationOnce(() => firstPoll.promise)
            .mockResolvedValue(envelope({ items: [sourceItem], count: 1 }));

        renderWorkspace();
        await act(async () => {
            await vi.advanceTimersByTimeAsync(20_000);
        });
        expect(get).toHaveBeenCalledTimes(1);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(60_000);
            window.dispatchEvent(new Event("focus"));
            document.dispatchEvent(new Event("visibilitychange"));
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(get).toHaveBeenCalledTimes(1);

        visible = false;
        focused = false;
        document.dispatchEvent(new Event("visibilitychange"));
        await act(async () => {
            firstPoll.resolve(envelope({ items: [sourceItem], count: 1 }));
            await firstPoll.promise;
            await vi.advanceTimersByTimeAsync(60_000);
        });
        expect(get).toHaveBeenCalledTimes(1);

        visible = true;
        focused = true;
        await act(async () => {
            document.dispatchEvent(new Event("visibilitychange"));
            window.dispatchEvent(new Event("focus"));
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(get).toHaveBeenCalledTimes(2);
    });

    it("keeps only an uncertain reroute tuple through polling and never auto-writes it", async () => {
        const tab = inactiveTab();
        const get = vi.spyOn(axios, "get");
        get.mockResolvedValueOnce(envelope(sourceDetail))
            .mockResolvedValueOnce(envelope(routeCandidates))
            .mockResolvedValueOnce(
                envelope({ items: [destinationItem], count: 1 }),
            )
            .mockResolvedValueOnce(
                envelope({ items: [destinationItem], count: 1 }),
            );
        const post = vi.spyOn(axios, "post");
        post.mockRejectedValueOnce(
            new AxiosError("The reroute response was lost."),
        ).mockResolvedValueOnce(
            envelope({
                work_item: null,
                message: null,
                event_uuid: "019f0000-0000-7000-8000-000000000501",
                replayed: true,
            }),
        );
        vi.spyOn(globalThis.crypto, "randomUUID").mockReturnValue(
            "019f0000-0000-7000-8000-000000000502",
        );

        renderWorkspace();
        fireEvent.click(
            screen.getByRole("button", { name: /Source care question/i }),
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
        const firstAttempt = post.mock.calls[0];

        tab.activate();
        expect(
            await screen.findByRole("button", {
                name: /Source care question.*Hospital Medicine Care Team/i,
            }),
        ).toBeInTheDocument();
        expect(post).toHaveBeenCalledTimes(1);
        expect(retry).toBeInTheDocument();

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
