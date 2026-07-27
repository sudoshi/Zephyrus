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
}
