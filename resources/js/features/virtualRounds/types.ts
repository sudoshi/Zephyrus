import { z } from "zod";
import {
    boardPatientSchema,
    boardSchema,
    detailContributionSchema,
    participantSchema,
    patientDetailSchema,
    patientQuestionCandidateSchema,
    patientStatusSchema,
    priorityReasonSchema,
    requirementsSchema,
    runStatusSchema,
    runSummarySchema,
    scopeSchema,
    sectionSchema,
    templateSchema,
    templatesResponseSchema,
} from "./schemas";

export type RoundsBoard = z.infer<typeof boardSchema>;
export type BoardPatient = z.infer<typeof boardPatientSchema>;
export type PatientDetail = z.infer<typeof patientDetailSchema>;
export type PatientQuestionCandidate = z.infer<
    typeof patientQuestionCandidateSchema
>;
export type DetailContribution = z.infer<typeof detailContributionSchema>;
export type RunSummary = z.infer<typeof runSummarySchema>;
export type RunStatus = z.infer<typeof runStatusSchema>;
export type PatientStatus = z.infer<typeof patientStatusSchema>;
export type PriorityReason = z.infer<typeof priorityReasonSchema>;
export type Requirements = z.infer<typeof requirementsSchema>;
export type Participant = z.infer<typeof participantSchema>;
export type RoundTemplate = z.infer<typeof templateSchema>;
export type RoundSection = z.infer<typeof sectionSchema>;
export type RoundScope = z.infer<typeof scopeSchema>;
export type TemplatesResponse = z.infer<typeof templatesResponseSchema>;
