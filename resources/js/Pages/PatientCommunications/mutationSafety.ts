export type PatientCommunicationMutationKind =
    "claim" | "reply" | "close" | "release" | "reassign" | "reroute";

export interface PatientCommunicationMutationCommand {
    readonly kind: PatientCommunicationMutationKind;
    readonly workItemUuid: string;
    readonly url: string;
    readonly payload: Readonly<Record<string, string | number>>;
    readonly idempotencyKey: string;
    readonly successMessage: string;
}

export function createMutationCommand(
    kind: PatientCommunicationMutationKind,
    workItemUuid: string,
    url: string,
    payload: Record<string, string | number>,
    successMessage: string,
    uuid: () => string = () => crypto.randomUUID(),
): PatientCommunicationMutationCommand {
    return Object.freeze({
        kind,
        workItemUuid,
        url,
        payload: Object.freeze({ ...payload }),
        idempotencyKey: uuid(),
        successMessage,
    });
}

export function mutationRequest(command: PatientCommunicationMutationCommand) {
    return {
        url: command.url,
        payload: command.payload,
        headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            "Idempotency-Key": command.idempotencyKey,
        },
    } as const;
}

/** A transport failure or 5xx cannot prove whether the transaction committed. */
export function isUnknownMutationOutcome(status: number | null): boolean {
    return status === null || status >= 500;
}
