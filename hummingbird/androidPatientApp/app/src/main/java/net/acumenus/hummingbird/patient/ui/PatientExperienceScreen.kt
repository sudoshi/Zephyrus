package net.acumenus.hummingbird.patient.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Groups
import androidx.compose.material.icons.outlined.DevicesOther
import androidx.compose.material.icons.outlined.ChatBubbleOutline
import androidx.compose.material.icons.outlined.Info
import androidx.compose.material.icons.outlined.Route
import androidx.compose.material.icons.outlined.Tune
import androidx.compose.material.icons.outlined.Today
import androidx.compose.material.icons.outlined.WarningAmber
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.OutlinedTextField
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import net.acumenus.hummingbird.patient.PatientCareTeamMember
import net.acumenus.hummingbird.patient.PatientDataContext
import net.acumenus.hummingbird.patient.PatientDestination
import net.acumenus.hummingbird.patient.PatientEducation
import net.acumenus.hummingbird.patient.PatientMessagingState
import net.acumenus.hummingbird.patient.PatientPathStep
import net.acumenus.hummingbird.patient.PatientSnapshot
import net.acumenus.hummingbird.patient.PatientTodayItem

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun PatientExperienceScreen(
    snapshot: PatientSnapshot,
    syntheticNotice: String?,
    selectedDestination: PatientDestination,
    messagingState: PatientMessagingState,
    onDestinationSelected: (PatientDestination) -> Unit,
    onMessagesRefresh: () -> Unit,
    onMessageThreadSelected: (String) -> Unit,
    onLeaveMessageThread: () -> Unit,
    onCreateMessageThread: (String, String) -> Unit,
    onRequestEducationClarification: (String, String) -> Unit,
    onSendMessage: (String) -> Unit,
    onAmendMessage: (String, net.acumenus.hummingbird.patient.data.PatientMessageAmendmentAction, String?) -> Unit,
    onCloseMessageThread: () -> Unit,
    onManagePreferences: () -> Unit,
    onManageDevices: () -> Unit,
    onSignOut: () -> Unit,
) {
    var selectedEducation by remember { mutableStateOf<PatientEducation?>(null) }
    val scene = when (selectedDestination) {
        PatientDestination.TODAY -> PatientScene.TODAY
        PatientDestination.PATH -> PatientScene.PATHWAY
        PatientDestination.CARE_TEAM -> PatientScene.CARE_TEAM
        PatientDestination.MESSAGES -> PatientScene.MESSAGES
    }
    val context = snapshot.contexts[selectedDestination] ?: PatientDataContext(
        heading = when (selectedDestination) {
            PatientDestination.TODAY -> "Your plan for today"
            PatientDestination.PATH -> "My Path"
            PatientDestination.CARE_TEAM -> "Your care team"
            PatientDestination.MESSAGES -> "Messages"
        },
        asOfLabel = if (selectedDestination == PatientDestination.MESSAGES) {
            "Message status is available after a secure refresh"
        } else {
            "No released update is available"
        },
        sourceLabel = if (selectedDestination == PatientDestination.MESSAGES) {
            "Source: Hummingbird Patient communication service"
        } else {
            "Source: no patient-facing projection released"
        },
        uncertaintyNotice = if (selectedDestination == PatientDestination.MESSAGES) {
            "Messages are for non-urgent questions and are not live emergency chat."
        } else {
            "Ask your care team for the most current information."
        },
        stale = true,
    )
    PatientScenicBackground(scene = scene) {
        Scaffold(
            containerColor = androidx.compose.ui.graphics.Color.Transparent,
            topBar = {
                TopAppBar(
                    title = {
                        Text(
                            text = "Hummingbird Patient",
                            style = MaterialTheme.typography.titleMedium,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                        )
                    },
                    actions = {
                        IconButton(onClick = onManagePreferences) {
                            Icon(
                                imageVector = Icons.Outlined.Tune,
                                contentDescription = "Preferences",
                            )
                        }
                        IconButton(onClick = onManageDevices) {
                            Icon(
                                imageVector = Icons.Outlined.DevicesOther,
                                contentDescription = "Manage devices",
                            )
                        }
                        TextButton(onClick = onSignOut) {
                            Text("Exit")
                        }
                    },
                    colors = TopAppBarDefaults.topAppBarColors(
                        containerColor = MaterialTheme.colorScheme.surface.copy(alpha = 0.94f),
                    ),
                )
            },
            bottomBar = {
                NavigationBar(
                    modifier = Modifier.navigationBarsPadding(),
                    containerColor = MaterialTheme.colorScheme.surface.copy(alpha = 0.96f),
                ) {
                    PatientDestination.entries.forEach { destination ->
                        val icon = when (destination) {
                            PatientDestination.TODAY -> Icons.Outlined.Today
                            PatientDestination.PATH -> Icons.Outlined.Route
                            PatientDestination.CARE_TEAM -> Icons.Outlined.Groups
                            PatientDestination.MESSAGES -> Icons.Outlined.ChatBubbleOutline
                        }
                        NavigationBarItem(
                            selected = selectedDestination == destination,
                            onClick = { onDestinationSelected(destination) },
                            icon = { Icon(icon, contentDescription = null) },
                            label = { Text(destination.label) },
                        )
                    }
                }
            },
        ) { contentPadding ->
            LazyColumn(
                modifier = Modifier
                    .fillMaxSize()
                    .padding(contentPadding)
                    .testTag("patient-content"),
                verticalArrangement = Arrangement.spacedBy(16.dp),
            ) {
                item {
                    DataContextCard(
                        context = context,
                        syntheticNotice = syntheticNotice,
                        patientDisplayName = snapshot.patientDisplayName,
                        modifier = Modifier.padding(horizontal = 16.dp),
                    )
                }
                item {
                    PatientPresentationPreferenceNotice(
                        modifier = Modifier.padding(horizontal = 16.dp),
                    )
                }
                item {
                    UrgentHelpCard(modifier = Modifier.padding(horizontal = 16.dp))
                }
                when (selectedDestination) {
                    PatientDestination.TODAY -> todayContent(snapshot)
                    PatientDestination.PATH -> pathwayContent(
                        snapshot = snapshot,
                        canRequestEducationClarification = (messagingState as? PatientMessagingState.Ready)?.canWrite == true,
                        onEducationSelected = { selectedEducation = it },
                    )
                    PatientDestination.CARE_TEAM -> careTeamContent(
                        snapshot = snapshot,
                    )
                    PatientDestination.MESSAGES -> messagesContent(
                        messagingState = messagingState,
                        onMessagesRefresh = onMessagesRefresh,
                        onMessageThreadSelected = onMessageThreadSelected,
                        onLeaveMessageThread = onLeaveMessageThread,
                        onCreateMessageThread = onCreateMessageThread,
                        onSendMessage = onSendMessage,
                        onAmendMessage = onAmendMessage,
                        onCloseMessageThread = onCloseMessageThread,
                    )
                }
                item { Surface(modifier = Modifier.padding(bottom = 8.dp)) {} }
            }
        }
    }

    selectedEducation?.let { education ->
        PatientEducationClarificationDialog(
            education = education,
            onDismiss = { selectedEducation = null },
            onSend = { message ->
                onRequestEducationClarification(education.id, message)
                selectedEducation = null
            },
        )
    }
}

@Composable
private fun PatientPresentationPreferenceNotice(modifier: Modifier = Modifier) {
    val presentation = LocalPatientPresentationAccessibility.current
    val choices = buildList {
        when (presentation.textSizePreference) {
            "large" -> add("Large text")
            "extra_large" -> add("Extra large text")
        }
        if (presentation.highContrast) add("high contrast")
        if (presentation.reducedMotion) add("reduced motion")
    }
    if (choices.isEmpty()) return

    GuidanceCard(
        title = "Your reading preferences",
        body = "Hummingbird Patient is using ${choices.joinToString()}. Your device accessibility settings can make text larger. These display choices do not change your care plan, clinical orders, or urgent-help instructions.",
        modifier = modifier.testTag("patient-presentation-preference-notice"),
    )
}

private fun androidx.compose.foundation.lazy.LazyListScope.todayContent(snapshot: PatientSnapshot) {
    item {
        SectionHeading(
            title = "Today",
            subtitle = snapshot.todaySummary
                ?: "What is completed, planned, or still uncertain in your care today.",
        )
    }
    items(snapshot.todayItems, key = { it.title }) { item ->
        TodayItemCard(item = item, modifier = Modifier.padding(horizontal = 16.dp))
    }
    if (snapshot.todayItems.isEmpty()) {
        item {
            GuidanceCard(
                title = "Today’s plan is not available yet",
                body = "Ask your care team for the current plan. Hummingbird Patient only shows information that has been released for you.",
                modifier = Modifier.padding(horizontal = 16.dp),
            )
        }
    }
    patientListCard(
        title = "Released next steps",
        entries = snapshot.todayNextSteps,
    )
    patientListCard(
        title = "What your team wants you to know",
        entries = snapshot.todayNotices,
    )
    item {
        GuidanceCard(
            title = "Before something happens",
            body = "Ask what it is for, what to expect, and what choices you have. Your care team can explain changes that are not yet shown here.",
            modifier = Modifier.padding(horizontal = 16.dp),
        )
    }
}

private fun androidx.compose.foundation.lazy.LazyListScope.pathwayContent(
    snapshot: PatientSnapshot,
    canRequestEducationClarification: Boolean,
    onEducationSelected: (PatientEducation) -> Unit,
) {
    item {
        SectionHeading(
            title = "My Path",
            subtitle = snapshot.pathwaySummary
                ?: "A plain-language guide to the current pathway for this stay.",
        )
    }
    snapshot.pathwayCurrentStage?.let { currentStage ->
        item {
            GuidanceCard(
                title = "Where you are now",
                body = currentStage,
                modifier = Modifier.padding(horizontal = 16.dp),
            )
        }
    }
    item {
        GuidanceCard(
            title = "A guide, not a guarantee",
            body = "This pathway can change as your symptoms, test results, and support needs change. It does not promise a discharge date or a specific outcome.",
            modifier = Modifier.padding(horizontal = 16.dp),
        )
    }
    items(snapshot.pathway, key = { it.title }) { step ->
        PathStepCard(step = step, modifier = Modifier.padding(horizontal = 16.dp))
    }
    if (snapshot.pathway.isEmpty()) {
        item {
            GuidanceCard(
                title = "No pathway has been released yet",
                body = "Your care team can explain the current pathway and what may change next.",
                modifier = Modifier.padding(horizontal = 16.dp),
            )
        }
    }
    if (snapshot.pathwayMilestones.isNotEmpty()) {
        item {
            SectionHeading(
                title = "Milestones your team released",
                subtitle = "Completed, current, and planned steps that have been approved for this patient view.",
            )
        }
        items(snapshot.pathwayMilestones, key = { it.id }) { milestone ->
            PatientInformationCard(
                title = milestone.title,
                badge = milestone.status,
                primary = milestone.timing.ifBlank { milestone.status },
                explanation = milestone.detail.ifBlank {
                    "Your team has released this milestone as part of your care pathway."
                },
                provenance = milestone.provenance,
                modifier = Modifier.padding(horizontal = 16.dp),
            )
        }
    }
    if (snapshot.pathwayGoals.isNotEmpty()) {
        item {
            SectionHeading(
                title = "Goals for your care",
                subtitle = "The source of each goal is shown so your own priorities stay distinct from the care team's plan.",
            )
        }
        items(snapshot.pathwayGoals, key = { it.id }) { goal ->
            PatientInformationCard(
                title = goal.label,
                badge = goal.authorLabel,
                primary = goal.status,
                explanation = goal.detail,
                provenance = goal.provenance,
                modifier = Modifier.padding(horizontal = 16.dp),
            )
        }
    }
    item {
        GuidanceCard(
            title = "Share what matters to you",
            body = "Your experiences, needs, and personal priorities can be important to your care. If Messages is available, choose \"What matters to you\" for a preference or \"A personal goal for my stay\" for a personal goal. Sending a message does not automatically change your care plan or create a clinical order. Your team will review it with you.",
            modifier = Modifier.padding(horizontal = 16.dp),
        )
    }
    if (snapshot.pathwayEducation.isNotEmpty()) {
        item {
            SectionHeading(
                title = "Learning and preparation",
                subtitle = "Topics your care team has released to help you understand and prepare for your next steps.",
            )
        }
        items(snapshot.pathwayEducation, key = { it.id }) { education ->
            PatientEducationCard(
                education = education,
                canRequestClarification = canRequestEducationClarification,
                onRequestClarification = { onEducationSelected(education) },
                modifier = Modifier.padding(horizontal = 16.dp),
            )
        }
        item {
            GuidanceCard(
                title = "Want to talk it through?",
                body = "Ask your bedside nurse or another care-team member to discuss these topics with you. A request for an explanation does not record consent, completion, or that you understand the information.",
                modifier = Modifier
                    .padding(horizontal = 16.dp)
                    .testTag("education-clarification-safety-guidance"),
            )
        }
    }
    snapshot.pathwayEvents?.let { timeline ->
        item {
            SectionHeading(
                title = timeline.headline,
                subtitle = timeline.summary,
            )
        }
        if (timeline.events.isNotEmpty()) {
            item {
                SectionHeading(
                    title = "Key moments your team released",
                    subtitle = "This is a patient-facing summary of key moments, not a complete clinical record.",
                )
            }
            items(timeline.events, key = { it.id }) { event ->
                PatientInformationCard(
                    title = event.title,
                    badge = listOfNotNull(event.category, event.status).joinToString(" • "),
                    primary = event.whenLabel,
                    explanation = event.detail.ifBlank {
                        "Your team released this key moment for your review."
                    },
                    provenance = timeline.provenance,
                    modifier = Modifier.padding(horizontal = 16.dp),
                )
            }
        }
        patientListCard(
            title = "Timeline context",
            entries = timeline.notices,
        )
    }
    snapshot.dischargeReadiness?.let { readiness ->
        item {
            SectionHeading(
                title = readiness.headline,
                subtitle = readiness.summary,
            )
        }
        readiness.estimatedRange?.let { estimatedRange ->
            item {
                GuidanceCard(
                    title = "Timing can change",
                    body = listOfNotNull(
                        estimatedRange,
                        readiness.estimatedConfidence?.let { "Timing confidence: $it." },
                    ).joinToString(" "),
                    modifier = Modifier.padding(horizontal = 16.dp),
                )
            }
        }
        if (readiness.criteria.isNotEmpty()) {
            item {
                SectionHeading(
                    title = "What needs to happen",
                    subtitle = "These are released preparation items, not a promise of a discharge date.",
                )
            }
            items(readiness.criteria, key = { it.id }) { criterion ->
                PatientInformationCard(
                    title = criterion.label,
                    badge = criterion.status,
                    primary = criterion.status,
                    explanation = criterion.detail.ifBlank {
                        "Your care team has released this preparation item for your review."
                    },
                    provenance = readiness.provenance,
                    modifier = Modifier.padding(horizontal = 16.dp),
                )
            }
        }
        patientListCard(
            title = "Still being arranged",
            entries = readiness.unresolvedNeeds,
        )
        patientListCard(
            title = "Medicines to review",
            entries = readiness.medications.map { medication ->
                listOf(medication.name, medication.purpose)
                    .filter { it.isNotBlank() }
                    .joinToString(": ")
            },
        )
        patientListCard(
            title = "Follow-up after leaving",
            entries = readiness.followUp.map { "${it.label} · ${it.whenLabel}" },
        )
        patientListCard(
            title = "When to get help",
            entries = readiness.warningSigns,
        )
        patientListCard(
            title = "How to reach your team",
            entries = readiness.contacts.map { "${it.label} · ${it.routeLabel}" },
        )
        patientListCard(
            title = "Important discharge context",
            entries = readiness.questions + readiness.notices,
        )
        item {
            GuidanceCard(
                title = "Your team confirms the details",
                body = "This is a released summary to help you prepare. Your care team will confirm medicines, follow-up, warning signs, and the safe time to leave.",
                modifier = Modifier.padding(horizontal = 16.dp),
            )
        }
    }
    snapshot.roundsSummary?.let { rounds ->
        item {
            SectionHeading(
                title = rounds.headline,
                subtitle = rounds.summary,
            )
        }
        rounds.roundWindow?.let { roundWindow ->
            item {
                GuidanceCard(
                    title = "When this was discussed",
                    body = roundWindow,
                    modifier = Modifier.padding(horizontal = 16.dp),
                )
            }
        }
        if (rounds.topics.isNotEmpty()) {
            item {
                SectionHeading(
                    title = "Topics your team released",
                    subtitle = "This is a patient-facing summary, not a complete clinical record.",
                )
            }
            items(rounds.topics, key = { it.id }) { topic ->
                PatientInformationCard(
                    title = topic.title,
                    badge = topic.status,
                    primary = topic.status,
                    explanation = topic.summary,
                    provenance = rounds.provenance,
                    modifier = Modifier.padding(horizontal = 16.dp),
                )
            }
        }
        patientListCard(
            title = "Next steps and questions",
            entries = rounds.nextSteps + rounds.questions,
        )
        patientListCard(
            title = "Conversation context",
            entries = rounds.notices,
        )
        item {
            GuidanceCard(
                title = "A released summary, not the full conversation",
                body = "Ask your care team to explain anything that is unclear. For non-urgent questions, use Messages when it is available; for urgent help, use your call button or speak with bedside staff.",
                modifier = Modifier.padding(horizontal = 16.dp),
            )
        }
    }
    patientListCard(
        title = "Pathway notices",
        entries = snapshot.pathwayNotices,
    )
    item {
        GuidanceCard(
            title = "What could change the plan?",
            body = "New symptoms, test results, response to treatment, safe mobility, medicines, and the support available after your stay can all change the next step.",
            modifier = Modifier.padding(horizontal = 16.dp),
        )
    }
}

private fun androidx.compose.foundation.lazy.LazyListScope.careTeamContent(
    snapshot: PatientSnapshot,
) {
    item {
        SectionHeading(
            title = "Care Team",
            subtitle = snapshot.careTeamSummary
                ?: "Who is currently involved and what each person or team helps with.",
        )
    }
    item {
        GuidanceCard(
            title = "How to reach your team",
            body = "Use secure messages for non-urgent questions when available. Use your bedside call button or speak with staff for immediate help; messages are not emergency chat.",
            modifier = Modifier.padding(horizontal = 16.dp),
        )
    }
    items(snapshot.careTeam, key = { "${it.name}:${it.role}" }) { member ->
        CareTeamCard(member = member, modifier = Modifier.padding(horizontal = 16.dp))
    }
    if (snapshot.careTeam.isEmpty()) {
        item {
            GuidanceCard(
                title = "Care-team details are not available yet",
                body = "Use your call button or ask bedside staff who is caring for you today.",
                modifier = Modifier.padding(horizontal = 16.dp),
            )
        }
    }
    patientListCard(
        title = "Available ways to ask for your team",
        entries = snapshot.careTeamCommunicationOptions,
    )
    patientListCard(
        title = "Care-team notices",
        entries = snapshot.careTeamNotices,
    )
    item {
        GuidanceCard(
            title = "Bring everyone into the conversation",
            body = "Tell your bedside nurse if you want a family member, interpreter, case manager, pharmacist, or another care-team member included in a discussion.",
            modifier = Modifier.padding(horizontal = 16.dp),
        )
    }
}

private fun androidx.compose.foundation.lazy.LazyListScope.messagesContent(
    messagingState: PatientMessagingState,
    onMessagesRefresh: () -> Unit,
    onMessageThreadSelected: (String) -> Unit,
    onLeaveMessageThread: () -> Unit,
    onCreateMessageThread: (String, String) -> Unit,
    onSendMessage: (String) -> Unit,
    onAmendMessage: (String, net.acumenus.hummingbird.patient.data.PatientMessageAmendmentAction, String?) -> Unit,
    onCloseMessageThread: () -> Unit,
) {
    item {
        PatientMessagingPanel(
            state = messagingState,
            onRefresh = onMessagesRefresh,
            onThreadSelected = onMessageThreadSelected,
            onLeaveThread = onLeaveMessageThread,
            onCreateThread = onCreateMessageThread,
            onSendMessage = onSendMessage,
            onAmendMessage = onAmendMessage,
            onCloseThread = onCloseMessageThread,
            modifier = Modifier.padding(horizontal = 16.dp),
        )
    }
}

@Composable
private fun DataContextCard(
    context: PatientDataContext,
    syntheticNotice: String?,
    patientDisplayName: String,
    modifier: Modifier = Modifier,
) {
    Card(
        modifier = modifier
            .fillMaxWidth()
            .semantics { liveRegion = LiveRegionMode.Polite },
        colors = CardDefaults.cardColors(
            containerColor = MaterialTheme.colorScheme.secondaryContainer,
        ),
    ) {
        Column(
            modifier = Modifier.padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Icon(Icons.Outlined.Info, contentDescription = null)
                Text(
                    text = context.heading,
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.SemiBold,
                )
            }
            Text(
                text = "For $patientDisplayName",
                style = MaterialTheme.typography.labelLarge,
                color = MaterialTheme.colorScheme.onSecondaryContainer,
            )
            if (syntheticNotice != null) {
                Text(
                    text = syntheticNotice,
                    style = MaterialTheme.typography.labelLarge,
                    color = MaterialTheme.colorScheme.onSecondaryContainer,
                )
            }
            Text(context.asOfLabel, style = MaterialTheme.typography.bodyMedium)
            Text(context.sourceLabel, style = MaterialTheme.typography.bodySmall)
            context.revisionNotice?.let { notice ->
                Text(
                    text = "Information updated",
                    style = MaterialTheme.typography.labelLarge,
                    color = MaterialTheme.colorScheme.primary,
                )
                Text(
                    text = notice,
                    style = MaterialTheme.typography.bodyMedium,
                )
                Text(
                    text = "Ask your care team if you have questions about what is shown here.",
                    style = MaterialTheme.typography.bodySmall,
                )
            }
            if (context.stale) {
                Text(
                    text = "This information may be out of date.",
                    style = MaterialTheme.typography.labelLarge,
                    color = MaterialTheme.colorScheme.error,
                )
            }
            HorizontalDivider(modifier = Modifier.padding(vertical = 4.dp))
            Text(
                text = context.uncertaintyNotice,
                style = MaterialTheme.typography.bodyMedium,
            )
        }
    }
}

@Composable
private fun UrgentHelpCard(modifier: Modifier = Modifier) {
    Card(
        modifier = modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(
            containerColor = MaterialTheme.colorScheme.errorContainer,
        ),
    ) {
        Row(
            modifier = Modifier.padding(16.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Icon(
                Icons.Outlined.WarningAmber,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.onErrorContainer,
            )
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(
                    text = "Need urgent help now?",
                    style = MaterialTheme.typography.titleMedium,
                    color = MaterialTheme.colorScheme.onErrorContainer,
                    fontWeight = FontWeight.Bold,
                )
                Text(
                    text = "Use your bedside call button or tell a staff member in person. This pilot does not send urgent alerts or messages.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onErrorContainer,
                )
            }
        }
    }
}

@Composable
private fun SectionHeading(title: String, subtitle: String) {
    Column(
        modifier = Modifier.padding(horizontal = 16.dp),
        verticalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Text(
            text = title,
            style = MaterialTheme.typography.headlineSmall,
            fontWeight = FontWeight.Bold,
            modifier = Modifier.semantics { heading() },
        )
        Text(
            text = subtitle,
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun TodayItemCard(item: PatientTodayItem, modifier: Modifier = Modifier) {
    PatientInformationCard(
        title = item.title,
        badge = item.status,
        primary = item.timing,
        explanation = item.explanation,
        provenance = item.provenance,
        modifier = modifier,
    )
}

@Composable
private fun PathStepCard(step: PatientPathStep, modifier: Modifier = Modifier) {
    PatientInformationCard(
        title = step.title,
        badge = step.state,
        primary = step.state,
        explanation = step.explanation,
        provenance = step.provenance,
        modifier = modifier,
    )
}

@Composable
private fun CareTeamCard(member: PatientCareTeamMember, modifier: Modifier = Modifier) {
    PatientInformationCard(
        title = member.name,
        badge = member.role,
        primary = listOfNotNull(member.availability, member.contactGuidance).joinToString("\n"),
        explanation = member.responsibility,
        provenance = member.provenance,
        modifier = modifier,
    )
}

private fun androidx.compose.foundation.lazy.LazyListScope.patientListCard(
    title: String,
    entries: List<String>,
) {
    if (entries.isEmpty()) return
    item {
        GuidanceCard(
            title = title,
            body = entries.joinToString("\n") { entry -> "• $entry" },
            modifier = Modifier.padding(horizontal = 16.dp),
        )
    }
}

@Composable
private fun PatientEducationCard(
    education: PatientEducation,
    canRequestClarification: Boolean,
    onRequestClarification: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Card(modifier = modifier.fillMaxWidth()) {
        Column(
            modifier = Modifier.padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Text(
                text = education.title,
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.SemiBold,
            )
            Text(
                text = "Released for your review",
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.primary,
            )
            Text(text = education.summary, style = MaterialTheme.typography.bodyMedium)
            if (canRequestClarification) {
                TextButton(
                    onClick = onRequestClarification,
                    modifier = Modifier.testTag("request-education-clarification-${education.id}"),
                ) {
                    Text("Ask for an explanation")
                }
            } else {
                Text(
                    text = "Ask your bedside nurse or another care-team member to talk this through with you.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            HorizontalDivider()
            Text(
                text = education.provenance,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@Composable
private fun PatientEducationClarificationDialog(
    education: PatientEducation,
    onDismiss: () -> Unit,
    onSend: (String) -> Unit,
) {
    var message by remember(education.id) { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Ask about ${education.title}") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text(education.summary, style = MaterialTheme.typography.bodyMedium)
                Text(
                    "Write a non-urgent question or say what you would like explained. Sending this does not record that you understand, completed an education task, gave consent, or received a clinical assessment.",
                    style = MaterialTheme.typography.bodyMedium,
                )
                OutlinedTextField(
                    value = message,
                    onValueChange = { message = it },
                    label = { Text("What would you like explained?") },
                    minLines = 4,
                    modifier = Modifier
                        .fillMaxWidth()
                        .testTag("education-clarification-message"),
                )
            }
        },
        confirmButton = {
            TextButton(
                onClick = { onSend(message.trim()) },
                enabled = message.trim().isNotEmpty(),
                modifier = Modifier.testTag("send-education-clarification"),
            ) {
                Text("Send request")
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text("Cancel") }
        },
    )
}

@Composable
private fun PatientInformationCard(
    title: String,
    badge: String,
    primary: String,
    explanation: String,
    provenance: String,
    modifier: Modifier = Modifier,
) {
    Card(modifier = modifier.fillMaxWidth()) {
        Column(
            modifier = Modifier.padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Text(
                text = title,
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.SemiBold,
            )
            Surface(
                color = MaterialTheme.colorScheme.primaryContainer,
                shape = MaterialTheme.shapes.small,
            ) {
                Text(
                    text = badge,
                    modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp),
                    style = MaterialTheme.typography.labelMedium,
                    color = MaterialTheme.colorScheme.onPrimaryContainer,
                )
            }
            Text(
                text = primary,
                style = MaterialTheme.typography.bodyLarge,
                fontWeight = FontWeight.Medium,
            )
            Text(text = explanation, style = MaterialTheme.typography.bodyMedium)
            HorizontalDivider()
            Text(
                text = provenance,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@Composable
private fun GuidanceCard(
    title: String,
    body: String,
    modifier: Modifier = Modifier,
) {
    Card(
        modifier = modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(
            containerColor = MaterialTheme.colorScheme.surfaceVariant,
        ),
    ) {
        Column(
            modifier = Modifier.padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            Text(
                text = title,
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.SemiBold,
            )
            Text(text = body, style = MaterialTheme.typography.bodyMedium)
        }
    }
}
