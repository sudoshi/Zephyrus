package net.acumenus.hummingbird.patient

import net.acumenus.hummingbird.patient.data.PatientImmediateHelp
import net.acumenus.hummingbird.patient.data.PatientMessageThread
import net.acumenus.hummingbird.patient.data.PatientMessageThreadTopic
import net.acumenus.hummingbird.patient.data.PatientMessageTopic
import net.acumenus.hummingbird.patient.data.PatientPreferences
import net.acumenus.hummingbird.patient.data.PatientThreadMessage

/** Entirely absent from release: deterministic emulator/UI-test data only. */
internal object SyntheticReferencePatientScenario {
    fun noticeOrNull(): String = "Synthetic reference scenario — not a real patient record"

    fun previewSessionOrNull(preview: PatientLaunchPreview): PatientSessionState? = when (preview) {
        PatientLaunchPreview.NONE -> null
        PatientLaunchPreview.LOADING -> PatientSessionState.Loading(
            "Checking your secure patient session",
        )
        PatientLaunchPreview.EMPTY -> PatientSessionState.Empty(
            patientDisplayName = "Sample inpatient",
            message = "No active hospital stay is available in Hummingbird Patient.",
        )
        PatientLaunchPreview.UNAVAILABLE -> PatientSessionState.SignedOut(
            status = PatientAuthStatus.Unavailable(
                "Patient access is temporarily unavailable. Ask your care team for current information.",
            ),
        )
        PatientLaunchPreview.RECOVERABLE_ERROR -> PatientSessionState.SignedOut(
            status = PatientAuthStatus.Failure(
                "Hummingbird Patient could not connect securely. Check your connection and try again.",
            ),
        )
    }

    fun snapshotOrNull(): PatientSnapshot = PatientSnapshot(
        patientDisplayName = "Sample inpatient",
        heading = "Your hospital stay",
        asOfLabel = "Updated today at 8:42 AM",
        sourceLabel = "Source: inpatient care plan and care-team directory",
        uncertaintyNotice = "Some times and next steps are estimates. Your care team may update them as your needs change.",
        todayItems = listOf(
            PatientTodayItem(
                title = "Morning medicines",
                timing = "Completed at 8:10 AM",
                status = "Completed",
                explanation = "Your nurse recorded that your scheduled morning medicines were given.",
                provenance = "Source: medication administration record • updated 8:12 AM",
            ),
            PatientTodayItem(
                title = "Care-team rounds",
                timing = "Expected between 9:30 and 11:00 AM",
                status = "Estimated",
                explanation = "Your team plans to review your progress and today’s goals at the bedside.",
                provenance = "Source: current unit rounding plan • updated 8:35 AM",
            ),
            PatientTodayItem(
                title = "Walk with mobility team",
                timing = "Planned for this afternoon",
                status = "Planned",
                explanation = "The timing depends on staff availability and how you are feeling.",
                provenance = "Source: current inpatient care plan • updated 8:40 AM",
            ),
        ),
        pathway = listOf(
            PatientPathStep(
                title = "Understand what brought you in",
                state = "Completed",
                explanation = "Your admitting team documented the main concerns for this stay.",
                provenance = "Source: admission plan • confirmed yesterday",
            ),
            PatientPathStep(
                title = "Stabilize symptoms and review tests",
                state = "In progress",
                explanation = "Your team is watching how you respond and reviewing available results.",
                provenance = "Source: current care plan • updated 8:40 AM",
            ),
            PatientPathStep(
                title = "Prepare for a safe next step",
                state = "Not started",
                explanation = "Your team will confirm medicines, support needs, and follow-up before discharge or transfer.",
                provenance = "Source: discharge-planning pathway • timing not yet confirmed",
            ),
        ),
        pathwayMilestones = listOf(
            PatientMilestone(
                id = "01982e0c-709a-7ef0-9000-000000000011",
                title = "Review your medicines before your next setting",
                status = "Planned",
                detail = "Your care team will explain which medicines continue, change, or stop before you leave.",
                timing = "Timing will be confirmed with your care team",
                provenance = "Source: discharge-planning pathway • updated 8:40 AM",
            ),
        ),
        pathwayGoals = listOf(
            PatientGoal(
                id = "01982e0c-709a-7ef0-9000-000000000012",
                label = "Walk safely with support",
                authorLabel = "Care-team goal",
                status = "In progress",
                detail = "The mobility team will help you build confidence with the support that is right for you.",
                provenance = "Source: current mobility plan • updated 8:40 AM",
            ),
            PatientGoal(
                id = "01982e0c-709a-7ef0-9000-000000000013",
                label = "Understand the next step before leaving",
                authorLabel = "Your goal",
                status = "Planned",
                detail = "Tell your care team what you want explained or what would help you feel ready.",
                provenance = "Source: patient-shared preference • updated 8:42 AM",
            ),
        ),
        pathwayEducation = listOf(
            PatientEducation(
                id = "01982e0c-709a-7ef0-9000-000000000014",
                title = "Preparing for the next setting",
                summary = "Review the support, medicines, follow-up, and warning signs that matter before you leave the hospital.",
                provenance = "Source: released patient education • updated 8:40 AM",
            ),
        ),
        pathwayEvents = PatientPathwayEventsView(
            headline = "What has happened so far",
            summary = "A simple timeline of key moments in your hospital stay. Timing is approximate.",
            events = listOf(
                PatientPathwayEventView(
                    id = "01982e0c-709a-7ef0-9000-000000000020",
                    title = "Admitted to the hospital",
                    whenLabel = "Two days ago",
                    status = "Completed",
                    detail = "Your care team reviewed your history and started your plan.",
                    category = "Care milestone",
                ),
                PatientPathwayEventView(
                    id = "01982e0c-709a-7ef0-9000-000000000021",
                    title = "Initial tests completed",
                    whenLabel = "Two days ago",
                    status = "Completed",
                    detail = "Your team released the completed test milestone for your review.",
                    category = "Test",
                ),
                PatientPathwayEventView(
                    id = "01982e0c-709a-7ef0-9000-000000000022",
                    title = "Preparing for a bedside procedure",
                    whenLabel = "Today",
                    status = "Current",
                    detail = "Your team will explain what to expect and how to prepare before it happens.",
                    category = "Procedure",
                ),
                PatientPathwayEventView(
                    id = "01982e0c-709a-7ef0-9000-000000000023",
                    title = "Planning transportation after you leave",
                    whenLabel = "When you are ready",
                    status = "Planned",
                    detail = "Your team will confirm whether you need a ride or other transportation support.",
                    category = "Transport",
                ),
            ),
            notices = listOf("This timeline is a summary and may not include every detail."),
            provenance = "Source: released pathway timeline • updated 8:40 AM",
        ),
        dischargeReadiness = PatientDischargeReadinessView(
            headline = "Getting ready to leave",
            summary = "Your team will confirm the details before you leave. Timing can change.",
            estimatedRange = "The next day or two",
            estimatedConfidence = "Estimated",
            criteria = listOf(
                PatientDischargeReadinessCriterion(
                    id = "01982e0c-709a-7ef0-9000-000000000015",
                    label = "Comfortable with your pain plan",
                    status = "Met",
                    detail = "",
                ),
                PatientDischargeReadinessCriterion(
                    id = "01982e0c-709a-7ef0-9000-000000000016",
                    label = "Moving safely with the support you need",
                    status = "Pending",
                    detail = "Your care team will review this with you each day.",
                ),
            ),
            unresolvedNeeds = listOf("A ride home arranged for the day you leave."),
            medications = listOf(
                PatientDischargeReadinessMedication(
                    id = "01982e0c-709a-7ef0-9000-000000000017",
                    name = "Your updated medicine list",
                    purpose = "Your team will review each medicine with you before you leave.",
                ),
            ),
            followUp = listOf(
                PatientDischargeReadinessFollowUp(
                    id = "01982e0c-709a-7ef0-9000-000000000018",
                    label = "Follow-up visit with your care team",
                    whenLabel = "Within a week or two of leaving",
                ),
            ),
            warningSigns = listOf("Call your care team if symptoms get worse after you go home."),
            contacts = listOf(
                PatientDischargeReadinessContact(
                    id = "01982e0c-709a-7ef0-9000-000000000019",
                    label = "Your care team",
                    routeLabel = "Speak with bedside staff",
                ),
            ),
            questions = emptyList(),
            notices = listOf("This is a summary; your team will confirm the details before you leave."),
            provenance = "Source: discharge-planning projection • updated 8:40 AM",
        ),
        roundsSummary = PatientRoundsSummaryView(
            headline = "Your care-team conversation",
            summary = "A plain-language summary your team released after reviewing your care. It is not a complete clinical record.",
            roundWindow = "Earlier today",
            topics = listOf(
                PatientRoundsTopicView(
                    id = "01982e0c-709a-7ef0-9000-000000000023",
                    title = "How you are feeling and responding to care",
                    summary = "Your team reviewed your progress and will keep checking how you are doing.",
                    status = "Current",
                ),
                PatientRoundsTopicView(
                    id = "01982e0c-709a-7ef0-9000-000000000024",
                    title = "Moving safely",
                    summary = "Your team plans to support safe movement as you regain strength.",
                    status = "Planned",
                ),
            ),
            nextSteps = listOf("Tell your bedside team what you would like explained or what matters most to you today."),
            questions = listOf("Use Messages for non-urgent questions, or speak with bedside staff sooner if you need help."),
            notices = listOf("This summary can change after your team reassesses you. It does not replace a conversation with your care team."),
            provenance = "Source: released care-conversation summary • updated 8:42 AM",
        ),
        careTeam = listOf(
            PatientCareTeamMember(
                name = "Dr. Morgan",
                role = "Hospital medicine clinician",
                availability = "Expected during morning rounds",
                responsibility = "Coordinates your medical plan and reviews major decisions with you.",
                provenance = "Source: current attending assignment • updated 7:55 AM",
            ),
            PatientCareTeamMember(
                name = "Taylor, RN",
                role = "Bedside nurse",
                availability = "Assigned until 7:00 PM",
                responsibility = "Coordinates care at the bedside and can help route questions to the right person.",
                provenance = "Source: current nursing assignment • updated 7:03 AM",
            ),
            PatientCareTeamMember(
                name = "Case management team",
                role = "Care transitions",
                availability = "Planned check-in today",
                responsibility = "Helps plan services, equipment, transportation, and follow-up for your next setting.",
                provenance = "Source: current care-team directory • updated 8:20 AM",
            ),
        ),
        contexts = mapOf(
            PatientDestination.TODAY to PatientDataContext(
                heading = "Your plan for today",
                asOfLabel = "Updated today at 8:42 AM",
                sourceLabel = "Source: inpatient care plan",
                uncertaintyNotice = "Some times are estimates and can change as your care needs change.",
                stale = false,
            ),
            PatientDestination.PATH to PatientDataContext(
                heading = "My Path",
                asOfLabel = "Updated today at 8:40 AM",
                sourceLabel = "Source: current inpatient care plan",
                uncertaintyNotice = "This pathway is a guide and may change after new symptoms, results, or care-team review.",
                stale = false,
                revisionNotice = "Your care team updated this information. Please use the details shown here.",
            ),
            PatientDestination.CARE_TEAM to PatientDataContext(
                heading = "Your care team",
                asOfLabel = "Updated today at 8:20 AM",
                sourceLabel = "Source: current care-team directory",
                uncertaintyNotice = "Assignments and availability can change during your stay.",
                stale = false,
            ),
            PatientDestination.MESSAGES to PatientDataContext(
                heading = "Messages",
                asOfLabel = "Updated today at 8:42 AM",
                sourceLabel = "Source: Hummingbird Patient communication service",
                uncertaintyNotice = "Messages are for non-urgent questions and are not live emergency chat.",
                stale = false,
            ),
        ),
        encounterUuid = "01982e0c-709a-7ef0-9000-000000000001",
        encounterScopes = listOf("messaging:read", "messaging:write"),
        preferences = PatientPreferences(
            textSize = "standard",
            reducedMotion = false,
            highContrast = false,
            notificationPreview = "hidden",
            preferredChannel = "push",
        ),
    )

    fun messagingOrNull(): PatientMessagingState.Ready = PatientMessagingState.Ready(
        topics = listOf(
            PatientMessageTopic(
                code = "rounds_question",
                label = "Question for care-team rounds",
                description = "Share a non-urgent question your care team may review before a care conversation. Sending it does not promise it will be discussed in a particular round.",
                expectedResponseWindow = "A team member usually responds during this shift.",
            ),
            PatientMessageTopic(
                code = "care_question",
                label = "Question for my care team",
                description = "Ask the responsible care-team pool a non-urgent question.",
                expectedResponseWindow = "A team member usually responds during this shift.",
            ),
            PatientMessageTopic(
                code = "care_preference",
                label = "What matters to you",
                description = "Share a non-urgent preference for this hospital stay. Your care team can review it, but it does not change your care plan or create a clinical order on its own.",
                expectedResponseWindow = "A team member usually responds during this shift.",
            ),
            PatientMessageTopic(
                code = "patient_goal",
                label = "A personal goal for my stay",
                description = "Share a non-urgent personal goal for this hospital stay. Your care team can review it, but it does not change your care plan or create a clinical order on its own.",
                expectedResponseWindow = "A team member usually responds during this shift.",
            ),
            PatientMessageTopic(
                code = "discharge_planning",
                label = "Discharge planning",
                description = "Ask about preparing to leave the hospital and what comes next.",
                expectedResponseWindow = "A team member usually responds during this shift.",
            ),
        ),
        threads = listOf(
            PatientMessageThread(
                threadUuid = "01982e0c-709a-7ef0-9000-000000000002",
                topic = PatientMessageThreadTopic(
                    code = "care_question",
                    label = "Question for my care team",
                    description = "A non-urgent question routed to the responsible team.",
                ),
                status = "open",
                ownershipState = "team_acknowledged",
                expectedResponseWindow = "A team member usually responds during this shift.",
                version = 2,
                lastMessageAt = "2026-07-19T09:05:00-04:00",
                createdAt = "2026-07-19T08:55:00-04:00",
                closedAt = null,
                closeReason = null,
                messages = listOf(
                    PatientThreadMessage(
                        messageUuid = "01982e0c-709a-7ef0-9000-000000000003",
                        senderDisplayRole = "You",
                        messageKind = "message",
                        body = "Could someone explain what the team plans to discuss during rounds?",
                        relatesToMessageUuid = null,
                        deliveryState = "sent",
                        sentAt = "2026-07-19T08:55:00-04:00",
                    ),
                    PatientThreadMessage(
                        messageUuid = "01982e0c-709a-7ef0-9000-000000000004",
                        senderDisplayRole = "Care team",
                        messageKind = "message",
                        body = "Your team plans to review your symptoms, test results, and goals for today.",
                        relatesToMessageUuid = null,
                        deliveryState = "delivered",
                        sentAt = "2026-07-19T09:05:00-04:00",
                    ),
                ),
            ),
            PatientMessageThread(
                threadUuid = "01982e0c-709a-7ef0-9000-000000000005",
                topic = PatientMessageThreadTopic(
                    code = "rounds_question",
                    label = "Question for care-team rounds",
                    description = "A non-urgent question for possible care-team review.",
                ),
                status = "open",
                ownershipState = "team_acknowledged",
                expectedResponseWindow = "A team member usually responds during this shift.",
                version = 3,
                lastMessageAt = "2026-07-19T09:18:00-04:00",
                createdAt = "2026-07-19T09:08:00-04:00",
                closedAt = null,
                closeReason = null,
                messages = listOf(
                    PatientThreadMessage(
                        messageUuid = "01982e0c-709a-7ef0-9000-000000000006",
                        senderDisplayRole = "You",
                        messageKind = "message",
                        body = "Could my care team consider my question before the next care conversation?",
                        relatesToMessageUuid = null,
                        deliveryState = "acknowledged",
                        sentAt = "2026-07-19T09:08:00-04:00",
                    ),
                    PatientThreadMessage(
                        messageUuid = "01982e0c-709a-7ef0-9000-000000000007",
                        senderDisplayRole = "Care team",
                        messageKind = "system_status",
                        body = "Your question was shared with your care team for possible review. It may not be discussed in a particular round.",
                        relatesToMessageUuid = null,
                        deliveryState = "sent",
                        sentAt = "2026-07-19T09:12:00-04:00",
                    ),
                    PatientThreadMessage(
                        messageUuid = "01982e0c-709a-7ef0-9000-000000000010",
                        senderDisplayRole = "Care team",
                        messageKind = "system_status",
                        body = "Your care team has completed their review of the question you shared. If you still need help, please send a message to your care team.",
                        relatesToMessageUuid = null,
                        deliveryState = "sent",
                        sentAt = "2026-07-19T09:18:00-04:00",
                    ),
                ),
            ),
            PatientMessageThread(
                threadUuid = "01982e0c-709a-7ef0-9000-000000000008",
                topic = PatientMessageThreadTopic(
                    code = "rounds_question",
                    label = "Question for care-team rounds",
                    description = "A non-urgent question for possible care-team review.",
                ),
                status = "open",
                ownershipState = "awaiting_team",
                expectedResponseWindow = "A team member usually responds during this shift.",
                version = 1,
                lastMessageAt = "2026-07-19T09:18:00-04:00",
                createdAt = "2026-07-19T09:18:00-04:00",
                closedAt = null,
                closeReason = null,
                messages = listOf(
                    PatientThreadMessage(
                        messageUuid = "01982e0c-709a-7ef0-9000-000000000009",
                        senderDisplayRole = "You",
                        messageKind = "message",
                        body = "Could I ask about the next care-team conversation?",
                        relatesToMessageUuid = null,
                        deliveryState = "sent",
                        sentAt = "2026-07-19T09:18:00-04:00",
                    ),
                ),
            ),
        ),
        immediateHelp = PatientImmediateHelp(
            version = "reference-guidance-v1",
            text = "Messages are not monitored for emergencies. For immediate help, use your bedside call button or tell a staff member in person.",
        ),
        canWrite = true,
    )
}
