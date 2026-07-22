import SwiftUI

struct PatientCareTeamView: View {
    let snapshot: PatientExperienceSnapshot

    var body: some View {
        ScrollView {
            LazyVStack(alignment: .leading, spacing: 18) {
                PatientScreenHeader(
                    eyebrow: "People helping you",
                    title: "Care Team",
                    subtitle: snapshot.canReadMessaging
                        ? "Roles, responsibilities, and safe ways to ask your care team a nonurgent question."
                        : "Roles, responsibilities, and the safest available way to reach someone."
                )
                #if DEBUG
                if snapshot.isSynthetic { SyntheticReferenceBanner() }
                #endif
                PatientProjectionSummaryCard(
                    headline: snapshot.careTeamHeadline,
                    summary: snapshot.careTeamSummary
                )
                if let notice = snapshot.careTeamRevisionNotice {
                    PatientProjectionRevisionNoticeCard(notice: notice)
                }
                PatientUrgentHelpNotice()

                PatientCard {
                    VStack(alignment: .leading, spacing: 9) {
                        Label("How to connect", systemImage: "person.wave.2.fill")
                            .font(.headline)
                            .foregroundStyle(PatientPalette.blue)
                        Text(snapshot.canReadMessaging
                            ? "Use Messages for nonurgent care questions. For immediate help, use your bedside call button, speak with your nurse, or ask any team member to connect you with the right person."
                            : "Messaging is not available for this care connection. Use your bedside call button, speak with your nurse, or ask any team member to connect you with the right person.")
                            .font(.body)
                        Text("Viewing this screen never sends a message.")
                            .font(.subheadline.weight(.semibold))
                            .foregroundStyle(.secondary)
                    }
                }
                .accessibilityIdentifier("care-team-connection-guidance")

                Text("Your team")
                    .font(.title2.bold())
                    .foregroundStyle(PatientPalette.ink)

                if !snapshot.hasCareTeamProjection || snapshot.careTeam.isEmpty {
                    PatientPhotoStateCard(
                        scene: .empty,
                        icon: "person.3.sequence.fill",
                        title: "No released care-team details",
                        message: "Ask bedside staff who is helping with your care. This app will not expose staff assignments that were not released to you."
                    )
                    .accessibilityIdentifier("care-team-empty-state")
                } else {
                    ForEach(snapshot.careTeam) { member in
                        PatientCard {
                            HStack(alignment: .top, spacing: 14) {
                                Image(systemName: "person.crop.circle.fill")
                                    .font(.system(size: 40))
                                    .foregroundStyle(PatientPalette.blue)
                                    .accessibilityHidden(true)
                                VStack(alignment: .leading, spacing: 6) {
                                    Text(member.name)
                                        .font(.title3.bold())
                                    Text(member.role)
                                        .font(.subheadline.weight(.semibold))
                                        .foregroundStyle(PatientPalette.blue)
                                    Text(member.availability)
                                        .font(.body)
                                    PatientProvenanceText(value: member.provenance)
                                }
                            }
                        }
                        .accessibilityElement(children: .combine)
                    }
                }

                if !snapshot.careTeamNotices.isEmpty {
                    PatientBulletListCard(
                        title: "Important context",
                        icon: "info.circle.fill",
                        items: snapshot.careTeamNotices
                    )
                }

                PatientFreshnessView(snapshot: snapshot)
            }
            .padding(20)
        }
        .background {
            PatientPhotoBackground(scene: .careTeam)
                .ignoresSafeArea()
        }
        .navigationTitle("Care Team")
        .navigationBarTitleDisplayMode(.inline)
    }
}
