import Foundation

typealias PatientTodayProjectionEnvelope = PatientEnvelope<PatientProjectionData<PatientTodayContent>>
typealias PatientPathwayProjectionEnvelope = PatientEnvelope<PatientProjectionData<PatientPathwayContent>>
typealias PatientPathwayEventsProjectionEnvelope = PatientEnvelope<PatientProjectionData<PatientPathwayEventsContent>>
typealias PatientDischargeReadinessProjectionEnvelope = PatientEnvelope<PatientProjectionData<PatientDischargeReadinessContent>>
typealias PatientRoundsSummaryProjectionEnvelope = PatientEnvelope<PatientProjectionData<PatientRoundsSummaryContent>>
typealias PatientCareTeamProjectionEnvelope = PatientEnvelope<PatientProjectionData<PatientCareTeamContent>>

struct PatientExperienceSnapshot: Equatable {
    let patientName: String
    let encounterLabel: String
    let asOf: Date?
    let isStale: Bool
    let sourceDescription: String
    let sourceLimitation: String
    let isSynthetic: Bool
    let encounterUUID: String?
    let encounterScopes: [String]
    /// Account-scoped display and delivery choices. These are not care-plan data.
    let preferences: PatientPreferences

    let hasTodayProjection: Bool
    let todayHeadline: String
    let todaySummary: String
    let todayItems: [PatientPlanItem]
    let todayNextSteps: [String]
    let todayNotices: [String]
    let todayRevisionNotice: PatientProjectionRevisionNotice?

    let hasPathwayProjection: Bool
    let pathwayHeadline: String
    let pathwaySummary: String
    let pathwayStages: [PatientPathwayStage]
    let pathwayMilestones: [PatientReleasedPathwayMilestone]
    let pathwayGoals: [PatientReleasedGoal]
    let pathwayEducation: [PatientReleasedEducation]
    let pathwayNotices: [String]
    let pathwayRevisionNotice: PatientProjectionRevisionNotice?
    let pathwayEvents: PatientPathwayEventsContent?
    let pathwayEventsProvenance: String?
    let dischargeReadiness: PatientDischargeReadinessContent?
    let dischargeReadinessProvenance: String?
    let roundsSummary: PatientRoundsSummaryContent?
    let roundsSummaryProvenance: String?

    let hasCareTeamProjection: Bool
    let careTeamHeadline: String
    let careTeamSummary: String
    let careTeam: [PatientCareTeamMember]
    let careTeamNotices: [String]
    let careTeamRevisionNotice: PatientProjectionRevisionNotice?

    var canReadMessaging: Bool {
        encounterUUID != nil && encounterScopes.contains("messaging:read")
    }

    var canWriteMessaging: Bool {
        canReadMessaging && encounterScopes.contains("messaging:write") && !isSynthetic
    }

    static func live(
        profile: PatientEnvelope<PatientProfile>,
        encounters: PatientEnvelope<PatientEncounterCollection>,
        today: PatientTodayProjectionEnvelope? = nil,
        pathway: PatientPathwayProjectionEnvelope? = nil,
        pathwayEvents: PatientPathwayEventsProjectionEnvelope? = nil,
        dischargeReadiness: PatientDischargeReadinessProjectionEnvelope? = nil,
        roundsSummary: PatientRoundsSummaryProjectionEnvelope? = nil,
        careTeam: PatientCareTeamProjectionEnvelope? = nil
    ) -> PatientExperienceSnapshot {
        let activeCount = encounters.data.encounters.count
        let selectedEncounter = encounters.data.encounters.first
        let projections = [
            today?.projectionContext,
            pathway?.projectionContext,
            pathwayEvents?.projectionContext,
            dischargeReadiness?.projectionContext,
            roundsSummary?.projectionContext,
            careTeam?.projectionContext,
        ]
            .compactMap { $0 }
        let provenance = projections.map(\.provenance).filter { !$0.isEmpty }
        let uncertainty = projections.map(\.uncertainty).filter { !$0.isEmpty }
        let projectionDates = projections.compactMap(\.asOf)

        let todayContent = today?.data.content
        var todayItems = (todayContent?.schedule ?? []).map { item in
            PatientPlanItem(
                id: UUID(uuidString: item.itemUUID) ?? UUID(),
                title: item.label,
                timeLabel: item.timeWindow,
                detail: [item.detail, item.preparation]
                    .compactMap { $0 }
                    .filter { !$0.isEmpty }
                    .joined(separator: " ")
                    .nonEmpty ?? "Your care team has released this step for today.",
                certainty: PatientCertainty(timingConfidence: item.timingConfidence, status: item.status),
                provenance: today?.data.provenance.patientDescription ?? "Released patient projection"
            )
        }
        if let outlook = todayContent?.dischargeOutlook {
            todayItems.append(
                PatientPlanItem(
                    title: "Planning for leaving the hospital",
                    timeLabel: outlook.estimatedRange,
                    detail: (outlook.remainingSteps ?? outlook.readinessTopics ?? []).joined(separator: " ").nonEmpty
                        ?? "Your team is reviewing what needs to happen before you leave.",
                    certainty: PatientCertainty(timingConfidence: outlook.confidence, status: nil),
                    provenance: today?.data.provenance.patientDescription ?? "Released patient projection"
                )
            )
        }

        let pathwayContent = pathway?.data.content
        let pathwayStages = (pathwayContent?.stages ?? []).map { stage in
            PatientPathwayStage(
                id: UUID(uuidString: stage.stageUUID) ?? UUID(),
                title: stage.title,
                detail: [stage.summary, stage.expectedRange]
                    .compactMap { $0 }
                    .filter { !$0.isEmpty }
                    .joined(separator: " "),
                state: PatientPathwayState(releasedStatus: stage.status),
                provenance: pathway?.data.provenance.patientDescription ?? "Released patient projection"
            )
        }
        let pathwayMilestones = pathwayContent?.milestones ?? []
        let pathwayGoals = pathwayContent?.goals ?? []
        let pathwayEducation = pathwayContent?.education ?? []
        let pathwayEventsContent = pathwayEvents?.data.content
        let dischargeContent = dischargeReadiness?.data.content
        let roundsContent = roundsSummary?.data.content
        let pathwayRevisionNotice = [
            pathway?.data.revisionNotice,
            pathwayEvents?.data.revisionNotice,
            dischargeReadiness?.data.revisionNotice,
            roundsSummary?.data.revisionNotice,
        ]
            .compactMap { $0 }
            .first

        let careContent = careTeam?.data.content
        let careMembers = (careContent?.members ?? []).map { member in
            PatientCareTeamMember(
                id: UUID(uuidString: member.memberUUID) ?? UUID(),
                name: member.displayName,
                role: [member.role, member.service]
                    .compactMap { $0 }
                    .filter { !$0.isEmpty }
                    .joined(separator: " · "),
                availability: member.responsibilities.joined(separator: " ").nonEmpty
                    ?? member.contactRoute.patientContactGuidance,
                provenance: careTeam?.data.provenance.patientDescription ?? "Released patient projection"
            )
        }

        return PatientExperienceSnapshot(
            patientName: profile.data.displayName,
            encounterLabel: todayContent?.careLocation?.patientLabel
                ?? (activeCount > 0
                    ? "\(activeCount) active care connection\(activeCount == 1 ? "" : "s")"
                    : "No active inpatient care connection"),
            asOf: projectionDates.max() ?? encounters.meta.asOfDate ?? profile.meta.asOfDate,
            isStale: projections.contains(where: \.isStale)
                || encounters.meta.stale
                || profile.meta.stale,
            sourceDescription: provenance.uniqued.joined(separator: " · ").nonEmpty
                ?? "Zephyrus patient access service",
            sourceLimitation: uncertainty.uniqued.joined(separator: " ").nonEmpty
                ?? (activeCount > 0
                    ? "Only information released to this patient view is shown."
                    : "No active encounter has been released to this account."),
            isSynthetic: false,
            encounterUUID: selectedEncounter?.encounterUUID,
            encounterScopes: selectedEncounter?.scopes ?? [],
            preferences: profile.data.preferences,
            hasTodayProjection: today != nil,
            todayHeadline: todayContent?.headline ?? "Today’s care view",
            todaySummary: todayContent?.summary
                ?? "No patient-facing plan has been released for today.",
            todayItems: todayItems,
            todayNextSteps: (todayContent?.nextSteps ?? []) + (todayContent?.questions ?? []),
            todayNotices: todayContent?.notices ?? [],
            todayRevisionNotice: today?.data.revisionNotice,
            hasPathwayProjection: pathway != nil,
            pathwayHeadline: pathwayContent?.headline ?? "My Path",
            pathwaySummary: pathwayContent?.summary
                ?? "No patient-facing pathway has been released for this stay.",
            pathwayStages: pathwayStages,
            pathwayMilestones: pathwayMilestones,
            pathwayGoals: pathwayGoals,
            pathwayEducation: pathwayEducation,
            pathwayNotices: pathwayContent?.notices ?? [],
            pathwayRevisionNotice: pathwayRevisionNotice,
            pathwayEvents: pathwayEventsContent,
            pathwayEventsProvenance: pathwayEvents?.data.provenance.patientDescription,
            dischargeReadiness: dischargeContent,
            dischargeReadinessProvenance: dischargeReadiness?.data.provenance.patientDescription,
            roundsSummary: roundsContent,
            roundsSummaryProvenance: roundsSummary?.data.provenance.patientDescription,
            hasCareTeamProjection: careTeam != nil,
            careTeamHeadline: careContent?.headline ?? "Care Team",
            careTeamSummary: careContent?.summary
                ?? "No patient-facing care-team details have been released for this stay.",
            careTeam: careMembers,
            careTeamNotices: careContent?.notices ?? [],
            careTeamRevisionNotice: careTeam?.data.revisionNotice
        )
    }

    #if DEBUG
    static func syntheticReference(now: Date) -> PatientExperienceSnapshot {
        PatientExperienceSnapshot(
            patientName: "Alex Morgan",
            encounterLabel: "5 East · Reference room",
            asOf: now.addingTimeInterval(-4 * 60),
            isStale: false,
            sourceDescription: "Synthetic Zephyrus patient projection",
            sourceLimitation: "Reference-only data. Plans and timing may change after your team reassesses you.",
            isSynthetic: true,
            encounterUUID: "019f0000-0000-7000-8000-000000000060",
            encounterScopes: ["today:read", "pathway:read", "care_team:read", "messaging:read"],
            preferences: PatientPreferences(
                textSize: .standard,
                reducedMotion: false,
                highContrast: false,
                notificationPreview: .hidden,
                preferredChannel: .push
            ),
            hasTodayProjection: true,
            todayHeadline: "Your plan for today",
            todaySummary: "A calm, plain-language view of the steps your reference care team has released.",
            todayItems: [
                PatientPlanItem(
                    title: "Morning care review",
                    timeLabel: "Completed at 8:20 AM",
                    detail: "Your bedside nurse reviewed comfort, medications, and today’s goals with the hospital medicine team.",
                    certainty: .confirmed,
                    provenance: "Synthetic care-plan update · bedside team"
                ),
                PatientPlanItem(
                    title: "Walk with the mobility team",
                    timeLabel: "Expected late morning",
                    detail: "Timing depends on how you feel and the mobility team’s availability.",
                    certainty: .expected,
                    provenance: "Synthetic mobility plan · scheduling estimate"
                ),
                PatientPlanItem(
                    title: "Next-setting discussion",
                    timeLabel: "Timing is being clarified",
                    detail: "Your team is still reviewing what support would be safest after this stay.",
                    certainty: .beingClarified,
                    provenance: "Synthetic interdisciplinary plan · not a discharge order"
                ),
            ],
            todayNextSteps: ["Write down questions for care team rounds."],
            todayNotices: ["Plans may change after your team reassesses you."],
            todayRevisionNotice: nil,
            hasPathwayProjection: true,
            pathwayHeadline: "My Path",
            pathwaySummary: "See what is complete, what is happening now, and what remains uncertain.",
            pathwayStages: [
                PatientPathwayStage(
                    title: "Arrived and assessed",
                    detail: "Your initial assessment and medication review are complete.",
                    state: .complete,
                    provenance: "Synthetic admission projection"
                ),
                PatientPathwayStage(
                    title: "Recovering on 5 East",
                    detail: "Your team is monitoring symptoms and helping you regain strength.",
                    state: .current,
                    provenance: "Synthetic care-plan projection"
                ),
                PatientPathwayStage(
                    title: "Preparing for the next setting",
                    detail: "This step is expected, but its timing and destination are not confirmed.",
                    state: .expected,
                    provenance: "Synthetic interdisciplinary estimate"
                ),
                PatientPathwayStage(
                    title: "Leaving the hospital",
                    detail: "No discharge time is confirmed. Your clinician will decide when it is safe.",
                    state: .notScheduled,
                    provenance: "No discharge order in synthetic projection"
                ),
            ],
            pathwayMilestones: [
                PatientReleasedPathwayMilestone(
                    milestoneUUID: "019f0000-0000-7000-8000-000000000071",
                    title: "Review medicines before your next setting",
                    status: "planned",
                    detail: "Your care team will explain which medicines continue, change, or stop before you leave.",
                    timing: "Timing will be confirmed with your care team",
                    timingConfidence: "estimated",
                    canChange: true
                )
            ],
            pathwayGoals: [
                PatientReleasedGoal(
                    goalUUID: "019f0000-0000-7000-8000-000000000072",
                    authorType: "care_team",
                    label: "Walk safely with support",
                    explanation: "The mobility team will help you build confidence with the support that is right for you.",
                    status: "in_progress",
                    targetRange: nil
                ),
                PatientReleasedGoal(
                    goalUUID: "019f0000-0000-7000-8000-000000000073",
                    authorType: "patient",
                    label: "Understand the next step before leaving",
                    explanation: "Tell your care team what you want explained or what would help you feel ready.",
                    status: "planned",
                    targetRange: nil
                )
            ],
            pathwayEducation: [
                PatientReleasedEducation(
                    itemUUID: "019f0000-0000-7000-8000-000000000074",
                    title: "Preparing for the next setting",
                    summary: "Review the support, medicines, follow-up, and warning signs that matter before you leave the hospital."
                )
            ],
            pathwayNotices: ["Expected timing is an estimate, not a promise."],
            pathwayRevisionNotice: PatientProjectionRevisionNotice(
                kind: .correction,
                message: "Your care team updated this information. Please use the details shown here."
            ),
            pathwayEvents: PatientPathwayEventsContent(
                headline: "What has happened so far",
                summary: "A simple timeline of key moments in your hospital stay. Timing is approximate.",
                events: [
                    PatientReleasedPathwayEvent(
                        eventUUID: "019f0000-0000-7000-8000-000000000080",
                        title: "Admitted to the hospital",
                        when: "Two days ago",
                        status: "completed",
                        detail: "Your care team reviewed your history and started your plan.",
                        category: .other
                    ),
                    PatientReleasedPathwayEvent(
                        eventUUID: "019f0000-0000-7000-8000-000000000081",
                        title: "Initial tests completed",
                        when: "Two days ago",
                        status: "completed",
                        detail: nil,
                        category: .test
                    ),
                    PatientReleasedPathwayEvent(
                        eventUUID: "019f0000-0000-7000-8000-000000000082",
                        title: "Preparing for a bedside procedure",
                        when: "Today",
                        status: "current",
                        detail: "Your team will explain what to expect and how to prepare before it happens.",
                        category: .procedure
                    ),
                    PatientReleasedPathwayEvent(
                        eventUUID: "019f0000-0000-7000-8000-000000000083",
                        title: "Planning transportation after you leave",
                        when: "When you are ready",
                        status: "planned",
                        detail: "Your team will confirm whether you need a ride or other transportation support.",
                        category: .transport
                    ),
                ],
                notices: ["This timeline is a summary and may not include every detail."]
            ),
            pathwayEventsProvenance: "Synthetic released pathway timeline",
            dischargeReadiness: PatientDischargeReadinessContent(
                headline: "Getting ready to leave",
                summary: "Your team will confirm the details before you leave. Timing can change.",
                estimatedRange: "The next day or two",
                estimatedConfidence: "estimated",
                criteria: [
                    PatientDischargeCriterion(
                        itemUUID: "019f0000-0000-7000-8000-000000000075",
                        label: "Comfortable with your pain plan",
                        status: "met",
                        detail: nil
                    ),
                    PatientDischargeCriterion(
                        itemUUID: "019f0000-0000-7000-8000-000000000076",
                        label: "Moving safely with the support you need",
                        status: "pending",
                        detail: "Your care team will review this with you each day."
                    ),
                ],
                unresolvedNeeds: ["A ride home arranged for the day you leave."],
                medications: [
                    PatientDischargeMedication(
                        itemUUID: "019f0000-0000-7000-8000-000000000077",
                        name: "Your updated medicine list",
                        purpose: "Your team will review each medicine with you before you leave."
                    ),
                ],
                followUp: [
                    PatientDischargeFollowUp(
                        itemUUID: "019f0000-0000-7000-8000-000000000078",
                        label: "Follow-up visit with your care team",
                        when: "Within a week or two of leaving"
                    ),
                ],
                warningSigns: ["Call your care team if symptoms get worse after you go home."],
                contacts: [
                    PatientDischargeContact(
                        itemUUID: "019f0000-0000-7000-8000-000000000079",
                        label: "Your care team",
                        route: "speak_with_bedside_staff"
                    ),
                ],
                questions: [],
                notices: ["This is a summary; your team will confirm the details before you leave."]
            ),
            dischargeReadinessProvenance: "Synthetic discharge-planning projection",
            roundsSummary: PatientRoundsSummaryContent(
                headline: "Your care-team conversation",
                summary: "A plain-language summary your team released after reviewing your care. It is not a complete clinical record.",
                roundWindow: "Earlier today",
                topics: [
                    PatientReleasedRoundsTopic(
                        topicUUID: "019f0000-0000-7000-8000-000000000083",
                        title: "How you are feeling and responding to care",
                        summary: "Your team reviewed your progress and will keep checking how you are doing.",
                        status: "current"
                    ),
                    PatientReleasedRoundsTopic(
                        topicUUID: "019f0000-0000-7000-8000-000000000084",
                        title: "Moving safely",
                        summary: "Your team plans to support safe movement as you regain strength.",
                        status: "planned"
                    ),
                ],
                nextSteps: ["Tell your bedside team what you would like explained or what matters most to you today."],
                questions: ["Use Messages for non-urgent questions, or speak with bedside staff sooner if you need help."],
                notices: ["This summary can change after your team reassesses you. It does not replace a conversation with your care team."]
            ),
            roundsSummaryProvenance: "Synthetic released care-conversation summary",
            hasCareTeamProjection: true,
            careTeamHeadline: "Your care team",
            careTeamSummary: "These reference roles are involved in care today.",
            careTeam: [
                PatientCareTeamMember(
                    name: "Jordan Lee, RN",
                    role: "Bedside nurse",
                    availability: "On your unit today",
                    provenance: "Synthetic shift assignment"
                ),
                PatientCareTeamMember(
                    name: "Maya Patel, MD",
                    role: "Hospital medicine clinician",
                    availability: "Leading today’s medical plan",
                    provenance: "Synthetic attending assignment"
                ),
                PatientCareTeamMember(
                    name: "Taylor Brooks, PT",
                    role: "Mobility therapist",
                    availability: "Visit expected; timing may change",
                    provenance: "Synthetic therapy schedule"
                ),
                PatientCareTeamMember(
                    name: "Case management team",
                    role: "Next-setting planning",
                    availability: "Review in progress",
                    provenance: "Synthetic interdisciplinary plan"
                ),
            ],
            careTeamNotices: ["Messages are for nonurgent questions and are never an emergency service or live chat."],
            careTeamRevisionNotice: nil
        )
    }
    #endif
}

private struct PatientProjectionContext {
    let asOf: Date?
    let isStale: Bool
    let provenance: String
    let uncertainty: String
}

private extension PatientEnvelope where Payload == PatientProjectionData<PatientTodayContent> {
    var projectionContext: PatientProjectionContext {
        PatientProjectionContext(
            asOf: meta.asOfDate,
            isStale: meta.stale,
            provenance: data.provenance.patientDescription,
            uncertainty: data.uncertainty.explanation
        )
    }
}

private extension PatientEnvelope where Payload == PatientProjectionData<PatientPathwayContent> {
    var projectionContext: PatientProjectionContext {
        PatientProjectionContext(
            asOf: meta.asOfDate,
            isStale: meta.stale,
            provenance: data.provenance.patientDescription,
            uncertainty: data.uncertainty.explanation
        )
    }
}

private extension PatientEnvelope where Payload == PatientProjectionData<PatientPathwayEventsContent> {
    var projectionContext: PatientProjectionContext {
        PatientProjectionContext(
            asOf: meta.asOfDate,
            isStale: meta.stale,
            provenance: data.provenance.patientDescription,
            uncertainty: data.uncertainty.explanation
        )
    }
}

private extension PatientEnvelope where Payload == PatientProjectionData<PatientDischargeReadinessContent> {
    var projectionContext: PatientProjectionContext {
        PatientProjectionContext(
            asOf: meta.asOfDate,
            isStale: meta.stale,
            provenance: data.provenance.patientDescription,
            uncertainty: data.uncertainty.explanation
        )
    }
}

private extension PatientEnvelope where Payload == PatientProjectionData<PatientRoundsSummaryContent> {
    var projectionContext: PatientProjectionContext {
        PatientProjectionContext(
            asOf: meta.asOfDate,
            isStale: meta.stale,
            provenance: data.provenance.patientDescription,
            uncertainty: data.uncertainty.explanation
        )
    }
}

private extension PatientEnvelope where Payload == PatientProjectionData<PatientCareTeamContent> {
    var projectionContext: PatientProjectionContext {
        PatientProjectionContext(
            asOf: meta.asOfDate,
            isStale: meta.stale,
            provenance: data.provenance.patientDescription,
            uncertainty: data.uncertainty.explanation
        )
    }
}

struct PatientPlanItem: Identifiable, Equatable {
    let id: UUID
    let title: String
    let timeLabel: String
    let detail: String
    let certainty: PatientCertainty
    let provenance: String

    init(
        id: UUID = UUID(),
        title: String,
        timeLabel: String,
        detail: String,
        certainty: PatientCertainty,
        provenance: String
    ) {
        self.id = id
        self.title = title
        self.timeLabel = timeLabel
        self.detail = detail
        self.certainty = certainty
        self.provenance = provenance
    }
}

enum PatientCertainty: String, Equatable {
    case confirmed = "Confirmed"
    case expected = "Expected"
    case beingClarified = "Being clarified"

    init(timingConfidence: String?, status: String?) {
        if status == "completed" || timingConfidence == "confirmed" {
            self = .confirmed
        } else if ["planned", "in_progress"].contains(status) || timingConfidence == "estimated" {
            self = .expected
        } else {
            self = .beingClarified
        }
    }
}

struct PatientPathwayStage: Identifiable, Equatable {
    let id: UUID
    let title: String
    let detail: String
    let state: PatientPathwayState
    let provenance: String

    init(
        id: UUID = UUID(),
        title: String,
        detail: String,
        state: PatientPathwayState,
        provenance: String
    ) {
        self.id = id
        self.title = title
        self.detail = detail
        self.state = state
        self.provenance = provenance
    }
}

enum PatientPathwayState: String, Equatable {
    case complete = "Complete"
    case current = "Happening now"
    case expected = "Planned"
    case delayed = "Delayed"
    case notScheduled = "No longer planned"
    case notAvailable = "Status being confirmed"

    init(releasedStatus: String) {
        switch releasedStatus {
        case "completed": self = .complete
        case "current": self = .current
        case "planned": self = .expected
        case "delayed": self = .delayed
        case "canceled": self = .notScheduled
        default: self = .notAvailable
        }
    }
}

struct PatientCareTeamMember: Identifiable, Equatable {
    let id: UUID
    let name: String
    let role: String
    let availability: String
    let provenance: String

    init(
        id: UUID = UUID(),
        name: String,
        role: String,
        availability: String,
        provenance: String
    ) {
        self.id = id
        self.name = name
        self.role = role
        self.availability = availability
        self.provenance = provenance
    }
}

private extension PatientCareLocation {
    var patientLabel: String? {
        [unitDisplayName, roomDisplayName, facilityDisplayName]
            .compactMap { $0 }
            .filter { !$0.isEmpty }
            .uniqued
            .joined(separator: " · ")
            .nonEmpty
    }
}

private extension String {
    var nonEmpty: String? {
        isEmpty ? nil : self
    }

    var patientContactGuidance: String {
        switch self {
        case "call_button_for_urgent_help":
            "Use your bedside call button for urgent help."
        default:
            "Speak with bedside staff to connect with this team member."
        }
    }
}

private extension Array where Element: Hashable {
    var uniqued: [Element] {
        var seen: Set<Element> = []
        return filter { seen.insert($0).inserted }
    }
}
