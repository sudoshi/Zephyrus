import SwiftUI

struct PatientTodayView: View {
    let snapshot: PatientExperienceSnapshot

    var body: some View {
        ScrollView {
            LazyVStack(alignment: .leading, spacing: 18) {
                PatientScreenHeader(
                    eyebrow: "Your day",
                    title: "Hello, \(firstName)",
                    subtitle: snapshot.encounterLabel
                )

                #if DEBUG
                if snapshot.isSynthetic { SyntheticReferenceBanner() }
                #endif
                PatientPresentationPreferenceNotice()
                PatientFreshnessView(snapshot: snapshot)
                if let notice = snapshot.todayRevisionNotice {
                    PatientProjectionRevisionNoticeCard(notice: notice)
                }
                PatientProjectionSummaryCard(
                    headline: snapshot.todayHeadline,
                    summary: snapshot.todaySummary
                )
                PatientUrgentHelpNotice()

                Text("Today’s plan")
                    .font(.title2.bold())
                    .foregroundStyle(PatientPalette.ink)
                    .padding(.top, 4)

                if let currentStage = snapshot.pathwayStages.first(where: { $0.state == .current }) {
                    PatientCard {
                        VStack(alignment: .leading, spacing: 8) {
                            Label("Where you are in your care", systemImage: "point.topleft.down.to.point.bottomright.curvepath")
                                .font(.headline)
                                .foregroundStyle(PatientPalette.teal)
                            Text(currentStage.title)
                                .font(.title3.bold())
                            Text(currentStage.detail)
                                .font(.body)
                            PatientProvenanceText(value: currentStage.provenance)
                        }
                    }
                    .accessibilityIdentifier("today-current-care-stage")
                }

                if snapshot.hasCareTeamProjection, !snapshot.careTeam.isEmpty {
                    PatientCard {
                        VStack(alignment: .leading, spacing: 8) {
                            Label("Your care team today", systemImage: "person.3.fill")
                                .font(.headline)
                                .foregroundStyle(PatientPalette.blue)
                            Text(snapshot.careTeamSummary)
                                .font(.body)
                            ForEach(snapshot.careTeam) { member in
                                Text("\(member.name) · \(member.role)")
                                    .font(.subheadline.weight(.semibold))
                            }
                            Text("Open Care Team for ways to connect with your team.")
                                .font(.footnote)
                                .foregroundStyle(.secondary)
                            PatientProvenanceText(value: snapshot.careTeam[0].provenance)
                        }
                    }
                    .accessibilityIdentifier("today-care-team-summary")
                }

                if !snapshot.pathwayGoals.isEmpty {
                    PatientCard {
                        VStack(alignment: .leading, spacing: 8) {
                            Label("Goals for your care", systemImage: "target")
                                .font(.headline)
                                .foregroundStyle(PatientPalette.teal)
                            ForEach(snapshot.pathwayGoals) { goal in
                                VStack(alignment: .leading, spacing: 3) {
                                    Text(goal.label)
                                        .font(.subheadline.weight(.semibold))
                                    Text(
                                        [
                                            PatientStateVocabulary.label(for: goal.status, domain: .goal),
                                            goal.explanation,
                                            goal.targetRange,
                                        ]
                                        .compactMap { $0 }
                                        .filter { !$0.isEmpty }
                                        .joined(separator: " · ")
                                    )
                                    .font(.footnote)
                                    .foregroundStyle(.secondary)
                                }
                            }
                            PatientProvenanceText(value: currentStageProvenance)
                        }
                    }
                    .accessibilityIdentifier("today-care-goals")
                }

                if let rounds = snapshot.roundsSummary {
                    PatientCard {
                        VStack(alignment: .leading, spacing: 10) {
                            Label("After your care-team conversation", systemImage: "person.2.wave.2.fill")
                                .font(.headline)
                                .foregroundStyle(PatientPalette.teal)
                            Text(rounds.headline)
                                .font(.title3.bold())
                            Text(rounds.summary)
                                .font(.body)
                            if let roundWindow = rounds.roundWindow, !roundWindow.isEmpty {
                                Label(roundWindow, systemImage: "clock")
                                    .font(.subheadline.weight(.semibold))
                                    .foregroundStyle(PatientPalette.blue)
                            }
                            if let topics = rounds.topics, !topics.isEmpty {
                                Divider()
                                Text("Topics your team released")
                                    .font(.subheadline.weight(.semibold))
                                ForEach(topics) { topic in
                                    VStack(alignment: .leading, spacing: 3) {
                                        Text(topic.title)
                                            .font(.subheadline.weight(.semibold))
                                        Text(
                                            "\(PatientStateVocabulary.label(for: topic.status, domain: .roundsTopic)) · \(topic.summary)"
                                        )
                                        .font(.footnote)
                                        .foregroundStyle(.secondary)
                                    }
                                }
                            }
                            PatientProvenanceText(
                                value: snapshot.roundsSummaryProvenance ?? "Released care-team conversation"
                            )
                        }
                    }
                    .accessibilityIdentifier("today-rounds-summary")

                    let conversationNextSteps = (rounds.nextSteps ?? []) + (rounds.questions ?? [])
                    if !conversationNextSteps.isEmpty {
                        PatientBulletListCard(
                            title: "Next steps from your conversation",
                            icon: "checklist",
                            items: conversationNextSteps
                        )
                    }
                }

                if !snapshot.hasTodayProjection || snapshot.todayItems.isEmpty {
                    PatientPhotoStateCard(
                        scene: .empty,
                        icon: "calendar.badge.clock",
                        title: "No released plan items",
                        message: "This app will not guess from staff-only information. Ask your care team what is planned today."
                    )
                    .accessibilityIdentifier("today-empty-state")
                } else {
                    ForEach(snapshot.todayItems) { item in
                        PatientCard {
                            VStack(alignment: .leading, spacing: 10) {
                                PatientCertaintyBadge(certainty: item.certainty)
                                if let statusLabel = item.statusLabel {
                                    Text(statusLabel)
                                        .font(.subheadline.weight(.semibold))
                                        .foregroundStyle(PatientPalette.ink)
                                }
                                Text(item.title)
                                    .font(.title3.bold())
                                Text(item.timeLabel)
                                    .font(.subheadline.weight(.semibold))
                                    .foregroundStyle(PatientPalette.blue)
                                Text(item.detail)
                                    .font(.body)
                                PatientProvenanceText(value: item.provenance)
                            }
                        }
                        .accessibilityElement(children: .combine)
                    }
                }

                if !snapshot.todayNextSteps.isEmpty {
                    PatientBulletListCard(
                        title: "Next steps and questions",
                        icon: "checklist",
                        items: snapshot.todayNextSteps
                    )
                }

                if !snapshot.todayNotices.isEmpty {
                    PatientBulletListCard(
                        title: "Important context",
                        icon: "info.circle.fill",
                        items: snapshot.todayNotices
                    )
                }

                Text("Care plans can change. Your care team—not this app—makes clinical decisions. Ask them if something here does not match what you were told.")
                    .font(.footnote)
                    .foregroundStyle(.secondary)
                    .padding(.vertical, 8)
            }
            .padding(20)
        }
        .background {
            PatientPhotoBackground(scene: .today)
                .ignoresSafeArea()
        }
        .navigationTitle("Today")
        .navigationBarTitleDisplayMode(.inline)
    }

    private var firstName: String {
        snapshot.patientName.split(separator: " ").first.map(String.init) ?? snapshot.patientName
    }

    private var currentStageProvenance: String {
        snapshot.pathwayStages.first?.provenance ?? "Released patient pathway"
    }
}
