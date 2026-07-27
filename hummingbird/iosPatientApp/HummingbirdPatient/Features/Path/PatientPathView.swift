import SwiftUI

struct PatientPathView: View {
    let snapshot: PatientExperienceSnapshot
    @ObservedObject var viewModel: PatientAppViewModel
    @State private var selectedEducation: PatientReleasedEducation?

    var body: some View {
        ScrollView {
            LazyVStack(alignment: .leading, spacing: 18) {
                PatientScreenHeader(
                    eyebrow: "Your care pathway",
                    title: "My Path",
                    subtitle: "A plain-language view of what is complete, what is happening, and what remains uncertain."
                )
                #if DEBUG
                if snapshot.isSynthetic { SyntheticReferenceBanner() }
                #endif
                PatientFreshnessView(snapshot: snapshot)
                if let notice = snapshot.pathwayRevisionNotice {
                    PatientProjectionRevisionNoticeCard(notice: notice)
                }
                PatientProjectionSummaryCard(
                    headline: snapshot.pathwayHeadline,
                    summary: snapshot.pathwaySummary
                )

                if !snapshot.hasPathwayProjection || snapshot.pathwayStages.isEmpty {
                    PatientPhotoStateCard(
                        scene: .empty,
                        icon: "point.topleft.down.to.point.bottomright.curvepath",
                        title: "No released pathway stages",
                        message: "Your care team has not released a patient-facing pathway yet. This app will not infer one from operational data."
                    )
                    .accessibilityIdentifier("pathway-empty-state")
                } else {
                    ForEach(snapshot.pathwayStages.indices, id: \.self) { index in
                        PatientPathwayStageRow(
                            stage: snapshot.pathwayStages[index],
                            isLast: index == snapshot.pathwayStages.indices.last
                        )
                    }
                }

                if !snapshot.pathwayMilestones.isEmpty {
                    PatientBulletListCard(
                        title: "Milestones your team released",
                        icon: "flag.checkered",
                        items: snapshot.pathwayMilestones.map { milestone in
                            [
                                milestone.title,
                                PatientStateVocabulary.label(for: milestone.status, domain: .milestone),
                                milestone.detail,
                                milestone.timing,
                            ]
                            .compactMap { value in
                                guard let value, !value.isEmpty else { return nil }
                                return value
                            }
                            .joined(separator: " · ")
                        }
                    )
                }

                if !snapshot.pathwayGoals.isEmpty {
                    PatientBulletListCard(
                        title: "Goals for your care",
                        icon: "target",
                        items: snapshot.pathwayGoals.map { goal in
                            [
                                goal.authorType.patientGoalAuthorLabel,
                                goal.label,
                                PatientStateVocabulary.label(for: goal.status, domain: .goal),
                                goal.explanation,
                                goal.targetRange,
                            ]
                            .compactMap { value in
                                guard let value, !value.isEmpty else { return nil }
                                return value
                            }
                            .joined(separator: " · ")
                        }
                    )
                }

                PatientCard {
                    VStack(alignment: .leading, spacing: 8) {
                        Label("Share what matters to you", systemImage: "heart.text.square.fill")
                            .font(.headline)
                            .foregroundStyle(PatientPalette.teal)
                        Text("Your experiences, needs, and personal priorities can be important to your care. If Messages is available, choose \"What matters to you\" for a preference or \"A personal goal for my stay\" for a personal goal.")
                            .font(.body)
                        Text("Sending a message does not automatically change your care plan or create a clinical order. Your team will review it with you.")
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                    }
                }
                .accessibilityIdentifier("patient-preference-guidance")

                if !snapshot.pathwayEducation.isEmpty {
                    Text("Learning and preparation")
                        .font(.title2.bold())
                        .foregroundStyle(PatientPalette.ink)
                    ForEach(snapshot.pathwayEducation) { education in
                        PatientCard {
                            VStack(alignment: .leading, spacing: 10) {
                                Label(education.title, systemImage: "book.closed.fill")
                                    .font(.headline)
                                    .foregroundStyle(PatientPalette.teal)
                                Text(education.summary)
                                    .font(.body)
                                if snapshot.canWriteMessaging {
                                    Button("Ask for an explanation", systemImage: "text.bubble.fill") {
                                        selectedEducation = education
                                    }
                                    .buttonStyle(.borderedProminent)
                                    .tint(PatientPalette.teal)
                                    .accessibilityIdentifier("request-education-clarification-\(education.itemUUID)")
                                } else {
                                    Text("Ask your bedside nurse or another care-team member to talk this through with you.")
                                        .font(.footnote)
                                        .foregroundStyle(.secondary)
                                }
                            }
                        }
                    }
                    PatientCard {
                        VStack(alignment: .leading, spacing: 8) {
                            Label("Want to talk it through?", systemImage: "person.2.fill")
                                .font(.headline)
                                .foregroundStyle(PatientPalette.teal)
                            Text("Ask your bedside nurse or another care-team member to discuss these topics with you. A request for an explanation does not record consent, completion, or that you understand the information.")
                                .font(.body)
                        }
                    }
                    .accessibilityIdentifier("education-clarification-safety-guidance")
                }

                if let timeline = snapshot.pathwayEvents {
                    PatientProjectionSummaryCard(
                        headline: timeline.headline,
                        summary: timeline.summary
                    )
                    if let events = timeline.events, !events.isEmpty {
                        PatientBulletListCard(
                            title: "Key moments your team released",
                            icon: "clock.arrow.circlepath",
                            items: events.map { event in
                                [
                                    event.category?.patientLabel,
                                    event.when,
                                    event.title,
                                    PatientStateVocabulary.label(for: event.status, domain: .pathwayEvent),
                                    event.detail,
                                ]
                                .compactMap { value in
                                    guard let value, !value.isEmpty else { return nil }
                                    return value
                                }
                                .joined(separator: " · ")
                            }
                        )
                    }
                    PatientBulletListCard(
                        title: "Timeline context",
                        icon: "info.circle.fill",
                        items: timeline.notices ?? []
                    )
                    if let provenance = snapshot.pathwayEventsProvenance {
                        PatientProvenanceText(value: provenance)
                    }
                }

                if let discharge = snapshot.dischargeReadiness {
                    PatientProjectionSummaryCard(
                        headline: discharge.headline,
                        summary: discharge.summary
                    )

                    if let estimatedRange = discharge.estimatedRange, !estimatedRange.isEmpty {
                        PatientCard {
                            VStack(alignment: .leading, spacing: 8) {
                                Label("Timing can change", systemImage: "calendar.badge.clock")
                                    .font(.headline)
                                    .foregroundStyle(PatientPalette.teal)
                                Text(estimatedRange)
                                    .font(.body.weight(.semibold))
                                if let confidence = discharge.estimatedConfidence {
                                    Text("Timing confidence: \(PatientStateVocabulary.label(for: confidence, domain: .timingConfidence)).")
                                        .font(.footnote)
                                        .foregroundStyle(.secondary)
                                }
                            }
                        }
                    }

                    if let criteria = discharge.criteria, !criteria.isEmpty {
                        PatientBulletListCard(
                            title: "What needs to happen",
                            icon: "checklist",
                            items: criteria.map { criterion in
                                [
                                    PatientStateVocabulary.label(for: criterion.status, domain: .dischargeCriterion),
                                    criterion.label,
                                    criterion.detail,
                                ]
                                .compactMap { value in
                                    guard let value, !value.isEmpty else { return nil }
                                    return value
                                }
                                .joined(separator: " · ")
                            }
                        )
                    }

                    PatientBulletListCard(
                        title: "Still being arranged",
                        icon: "clock.arrow.circlepath",
                        items: discharge.unresolvedNeeds ?? []
                    )
                    PatientBulletListCard(
                        title: "Medicines to review",
                        icon: "pills.fill",
                        items: (discharge.medications ?? []).map { medication in
                            [medication.name, medication.purpose]
                                .compactMap { $0 }
                                .joined(separator: ": ")
                        }
                    )
                    PatientBulletListCard(
                        title: "Follow-up after leaving",
                        icon: "calendar",
                        items: (discharge.followUp ?? []).map { "\($0.label) · \($0.when)" }
                    )
                    PatientBulletListCard(
                        title: "When to get help",
                        icon: "exclamationmark.triangle.fill",
                        items: discharge.warningSigns ?? []
                    )
                    PatientBulletListCard(
                        title: "How to reach your team",
                        icon: "person.2.fill",
                        items: (discharge.contacts ?? []).map { contact in
                            "\(contact.label) · \(contact.route.patientDischargeContactLabel)"
                        }
                    )
                    PatientBulletListCard(
                        title: "Important discharge context",
                        icon: "info.circle.fill",
                        items: (discharge.questions ?? []) + (discharge.notices ?? [])
                    )
                    if let provenance = snapshot.dischargeReadinessProvenance {
                        PatientProvenanceText(value: provenance)
                    }
                    PatientCard {
                        VStack(alignment: .leading, spacing: 8) {
                            Label("Your team confirms the details", systemImage: "person.crop.circle.badge.checkmark")
                                .font(.headline)
                                .foregroundStyle(PatientPalette.teal)
                            Text("This is a released summary to help you prepare. Your care team will confirm medicines, follow-up, warning signs, and the safe time to leave.")
                                .font(.body)
                        }
                    }
                }

                if let rounds = snapshot.roundsSummary {
                    PatientProjectionSummaryCard(
                        headline: rounds.headline,
                        summary: rounds.summary
                    )
                    if let roundWindow = rounds.roundWindow, !roundWindow.isEmpty {
                        PatientCard {
                            Label(roundWindow, systemImage: "person.2.wave.2.fill")
                                .font(.headline)
                                .foregroundStyle(PatientPalette.teal)
                        }
                    }
                    if let topics = rounds.topics, !topics.isEmpty {
                        PatientBulletListCard(
                            title: "Topics your team released",
                            icon: "text.bubble.fill",
                            items: topics.map { topic in
                                "\(PatientStateVocabulary.label(for: topic.status, domain: .roundsTopic)) · \(topic.title): \(topic.summary)"
                            }
                        )
                    }
                    PatientBulletListCard(
                        title: "Next steps and questions",
                        icon: "checklist",
                        items: (rounds.nextSteps ?? []) + (rounds.questions ?? [])
                    )
                    PatientBulletListCard(
                        title: "Conversation context",
                        icon: "info.circle.fill",
                        items: rounds.notices ?? []
                    )
                    if let provenance = snapshot.roundsSummaryProvenance {
                        PatientProvenanceText(value: provenance)
                    }
                    PatientCard {
                        VStack(alignment: .leading, spacing: 8) {
                            Label("A released summary, not the full conversation", systemImage: "bubble.left.and.bubble.right.fill")
                                .font(.headline)
                                .foregroundStyle(PatientPalette.teal)
                            Text("Ask your care team to explain anything that is unclear. For non-urgent questions, use Messages when it is available; for urgent help, use your call button or speak with bedside staff.")
                                .font(.body)
                        }
                    }
                }

                if !snapshot.pathwayNotices.isEmpty {
                    PatientBulletListCard(
                        title: "Important context",
                        icon: "info.circle.fill",
                        items: snapshot.pathwayNotices
                    )
                }

                PatientCard {
                    VStack(alignment: .leading, spacing: 8) {
                        Label("What uncertainty means", systemImage: "questionmark.circle.fill")
                            .font(.headline)
                            .foregroundStyle(PatientPalette.amber)
                        Text("Expected steps are possibilities, not promises. ‘Not scheduled’ means no confirmed time was released. Your team may change the path as your needs change.")
                            .font(.body)
                    }
                }
            }
            .padding(20)
        }
        .background {
            PatientPhotoBackground(scene: .pathway)
                .ignoresSafeArea()
        }
        .navigationTitle("My Path")
        .navigationBarTitleDisplayMode(.inline)
        .sheet(item: $selectedEducation) { education in
            PatientEducationClarificationComposer(
                education: education,
                viewModel: viewModel
            ) {
                selectedEducation = nil
            }
        }
    }

}

private struct PatientEducationClarificationComposer: View {
    let education: PatientReleasedEducation
    @ObservedObject var viewModel: PatientAppViewModel
    let dismiss: () -> Void
    @State private var message = ""

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    Text(education.title)
                        .font(.title2.bold())
                    Text(education.summary)
                        .font(.body)
                    PatientCard {
                        VStack(alignment: .leading, spacing: 8) {
                            Label("Ask what would help", systemImage: "text.bubble.fill")
                                .font(.headline)
                                .foregroundStyle(PatientPalette.teal)
                            Text("Write a non-urgent question or say what you would like explained. Sending this does not record that you understand, completed an education task, gave consent, or received a clinical assessment.")
                                .font(.body)
                        }
                    }
                    TextEditor(text: $message)
                        .frame(minHeight: 140)
                        .padding(8)
                        .background(PatientPalette.surface)
                        .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                        .accessibilityIdentifier("education-clarification-message")
                    if let feedback = viewModel.messagingMessage {
                        PatientCard {
                            Text(feedback)
                                .font(.body)
                                .foregroundStyle(PatientPalette.teal)
                        }
                    }
                    Button {
                        Task {
                            let sent = await viewModel.requestEducationClarification(
                                educationItemUUID: education.itemUUID,
                                message: message
                            )
                            if sent { dismiss() }
                        }
                    } label: {
                        HStack {
                            if viewModel.isMessagingBusy { ProgressView() }
                            Text("Send request for an explanation")
                        }
                        .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.borderedProminent)
                    .tint(PatientPalette.teal)
                    .disabled(viewModel.isMessagingBusy || message.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                    .accessibilityIdentifier("send-education-clarification")
                }
                .padding(20)
            }
            .navigationTitle("Talk it through")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel", action: dismiss)
                }
            }
        }
    }
}

private extension String {
    var patientGoalAuthorLabel: String {
        switch self {
        case "patient": "Your goal"
        case "representative": "Goal shared by your representative"
        case "care_team": "Care-team goal"
        default: "Released pathway goal"
        }
    }

    var patientDischargeContactLabel: String {
        switch self {
        case "speak_with_bedside_staff": "Speak with bedside staff"
        case "call_button_for_urgent_help": "Use your bedside call button for urgent help"
        default: "Ask your care team how to reach them"
        }
    }
}

private struct PatientPathwayStageRow: View {
    let stage: PatientPathwayStage
    let isLast: Bool

    var body: some View {
        HStack(alignment: .top, spacing: 14) {
            VStack(spacing: 0) {
                Image(systemName: icon)
                    .font(.title2)
                    .foregroundStyle(stateColor)
                    .frame(width: 34, height: 34)
                    .accessibilityHidden(true)
                if !isLast {
                    Rectangle()
                        .fill(Color.secondary.opacity(0.25))
                        .frame(width: 2)
                        .frame(minHeight: 105)
                }
            }

            PatientCard {
                VStack(alignment: .leading, spacing: 9) {
                    Text(stage.state.rawValue)
                        .font(.caption.weight(.bold))
                        .foregroundStyle(stateColor)
                    Text(stage.title)
                        .font(.title3.bold())
                    Text(stage.detail)
                        .font(.body)
                    PatientProvenanceText(value: stage.provenance)
                }
            }
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel(accessibilitySummary)
    }

    private var accessibilitySummary: String {
        "\(stage.state.rawValue). \(stage.title). \(stage.detail). Source: \(stage.provenance)"
    }

    private var icon: String {
        let state = stage.state
        switch state {
        case .complete: return "checkmark.circle.fill"
        case .current: return "circle.inset.filled"
        case .expected: return "circle.dotted"
        case .delayed: return "clock.badge.exclamationmark"
        case .notScheduled, .notAvailable: return "questionmark.circle"
        }
    }

    private var stateColor: Color {
        let state = stage.state
        switch state {
        case .complete: return PatientPalette.teal
        case .current: return PatientPalette.blue
        case .expected: return PatientPalette.amber
        case .delayed: return PatientPalette.rose
        case .notScheduled, .notAvailable: return Color.secondary
        }
    }
}
