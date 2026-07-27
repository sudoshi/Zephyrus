package net.acumenus.hummingbird.patient

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import net.acumenus.hummingbird.patient.data.PatientApiConfiguration
import net.acumenus.hummingbird.patient.data.PatientApiException
import net.acumenus.hummingbird.patient.data.PatientEnrollmentRequest
import net.acumenus.hummingbird.patient.data.PatientMessageAmendmentAction
import net.acumenus.hummingbird.patient.data.PatientPreferencesUpdate
import net.acumenus.hummingbird.patient.data.PatientSessionCoordinator
import net.acumenus.hummingbird.patient.data.PatientSessionOutcome

internal class PatientAppViewModel(
    private val apiEnabled: Boolean,
    launchState: PatientLaunchState = PatientLaunchState(),
    private val coordinator: PatientSessionCoordinator? = null,
    scope: CoroutineScope? = null,
    private val workDispatcher: CoroutineDispatcher = Dispatchers.IO,
) {
    private val operationScope: CoroutineScope by lazy {
        scope ?: CoroutineScope(SupervisorJob() + Dispatchers.Main.immediate)
    }
    private var sessionManagementJob: Job? = null
    private var sessionRevocationInFlight = false

    val networkEnabled: Boolean
        get() = apiEnabled

    private val syntheticSnapshot = if (launchState.syntheticReferenceRequested) {
        SyntheticReferencePatientScenario.snapshotOrNull()
    } else {
        null
    }

    var state: PatientUiState by mutableStateOf(
        if (syntheticSnapshot != null) {
            PatientUiState(
                session = PatientSessionState.Ready(
                    snapshot = syntheticSnapshot,
                    synthetic = true,
                ),
                destination = launchState.initialDestination,
                messaging = SyntheticReferencePatientScenario.messagingOrNull()
                    ?: PatientMessagingState.Hidden,
            )
        } else {
            PatientUiState(
                session = SyntheticReferencePatientScenario.previewSessionOrNull(
                    launchState.preview,
                ) ?: PatientSessionState.SignedOut(),
            )
        },
    )
        private set

    constructor(
        launchState: PatientLaunchState,
        coordinator: PatientSessionCoordinator?,
    ) : this(
        apiEnabled = PatientApiConfiguration.fromBuild().enabled,
        launchState = launchState,
        coordinator = coordinator,
    )

    fun restoreSession() {
        if (state.session is PatientSessionState.Ready || !apiEnabled) return
        val signedOut = state.session as? PatientSessionState.SignedOut ?: return
        val availableCoordinator = coordinator ?: run {
            unavailable(
                signedOut,
                "Protected patient credential storage is unavailable. No information was requested.",
            )
            return
        }
        execute("Checking your secure patient session") {
            availableCoordinator.restore()
                ?: return@execute PatientSessionOutcome.Empty(
                    displayName = "Patient",
                    message = SIGNED_OUT_SENTINEL,
                )
        }
    }

    fun selectAuthMode(mode: PatientAuthMode) {
        val signedOut = state.session as? PatientSessionState.SignedOut ?: return
        state = state.copy(session = signedOut.copy(authMode = mode, status = PatientAuthStatus.Idle))
    }

    fun submitSignIn(email: String, password: String) {
        val signedOut = state.session as? PatientSessionState.SignedOut ?: return
        when {
            email.isBlank() || password.isBlank() -> {
                state = state.copy(session = signedOut.copy(
                    status = PatientAuthStatus.ValidationError("Enter both your email and password."),
                ))
            }
            !apiEnabled -> unavailable(
                signedOut,
                "Patient sign-in is not enabled in this build. Ask your care team for current information.",
            )
            coordinator == null -> unavailable(
                signedOut,
                "Protected patient credential storage is unavailable. No information was sent.",
            )
            else -> {
                val passwordChars = password.toCharArray()
                execute("Signing in securely") {
                    try {
                        coordinator.signIn(email.trim(), passwordChars)
                    } finally {
                        passwordChars.fill('\u0000')
                    }
                }
            }
        }
    }

    fun submitEnrollment(form: PatientEnrollmentForm) {
        val signedOut = state.session as? PatientSessionState.SignedOut ?: return
        val validation = when {
            listOf(
                form.challengeUuid,
                form.challengeToken,
                form.verificationCode,
                form.displayName,
                form.email,
                form.password,
                form.passwordConfirmation,
            ).any(String::isBlank) -> "Complete every invitation and account field."
            form.password != form.passwordConfirmation -> "The passwords do not match."
            form.password.length < 12 -> "Use a password with at least 12 characters."
            else -> null
        }
        when {
            validation != null -> {
                state = state.copy(session = signedOut.copy(
                    status = PatientAuthStatus.ValidationError(validation),
                ))
            }
            !apiEnabled -> unavailable(
                signedOut,
                "Patient enrollment is not enabled in this build. Ask your care team for help.",
            )
            coordinator == null -> unavailable(
                signedOut,
                "Protected patient credential storage is unavailable. No information was sent.",
            )
            else -> {
                val challengeToken = form.challengeToken.toCharArray()
                val verificationCode = form.verificationCode.toCharArray()
                val password = form.password.toCharArray()
                execute("Verifying your invitation securely") {
                    try {
                        coordinator.enroll(
                            PatientEnrollmentRequest(
                                challengeUuid = form.challengeUuid.trim(),
                                challengeToken = challengeToken,
                                verificationCode = verificationCode,
                                displayName = form.displayName.trim(),
                                email = form.email.trim(),
                                password = password,
                            ),
                        )
                    } finally {
                        challengeToken.fill('\u0000')
                        verificationCode.fill('\u0000')
                        password.fill('\u0000')
                    }
                }
            }
        }
    }

    fun selectDestination(destination: PatientDestination) {
        if (state.session is PatientSessionState.Ready) {
            state = state.copy(destination = destination)
        }
    }

    /** Loads device sessions only after the patient explicitly opens Manage devices. */
    fun openDeviceSessions() {
        val ready = state.session as? PatientSessionState.Ready ?: return
        if (sessionRevocationInFlight) {
            state = state.copy(
                deviceSessions = PatientDeviceSessionsState.Loading(
                    "Finishing your confirmed device sign-out securely",
                ),
            )
            return
        }
        sessionManagementJob?.cancel()
        when {
            ready.synthetic || !apiEnabled || coordinator == null -> {
                state = state.copy(
                    deviceSessions = PatientDeviceSessionsState.Unavailable(
                        message = "Manage devices is not available right now. You can keep using Hummingbird Patient.",
                        canRetry = false,
                    ),
                )
            }
            else -> {
                state = state.copy(
                    deviceSessions = PatientDeviceSessionsState.Loading(
                        "Checking your signed-in devices securely",
                    ),
                )
                sessionManagementJob = operationScope.launch {
                    try {
                        val sessions = withContext(workDispatcher) {
                            coordinator.patientSessions()
                        }
                        state = state.copy(
                            deviceSessions = PatientDeviceSessionsState.Ready(sessions),
                        )
                    } catch (cancelled: CancellationException) {
                        throw cancelled
                    } catch (error: PatientApiException) {
                        if (error.statusCode == 401) {
                            transitionToSignedOut()
                        } else {
                            showDeviceSessionsUnavailable(featureDisabled = error.statusCode == 404)
                        }
                    } catch (_: Exception) {
                        showDeviceSessionsUnavailable(featureDisabled = false)
                    }
                }
            }
        }
    }

    fun dismissDeviceSessions() {
        if (!sessionRevocationInFlight) {
            sessionManagementJob?.cancel()
            sessionManagementJob = null
        }
        state = state.copy(deviceSessions = PatientDeviceSessionsState.Hidden)
    }

    /** Opens account choices already present in the released patient profile. */
    fun openPreferences() {
        val ready = state.session as? PatientSessionState.Ready ?: return
        state = when {
            ready.synthetic -> state.copy(
                preferences = PatientPreferencesState.Ready(ready.snapshot.preferences),
            )
            !apiEnabled || coordinator == null -> state.copy(
                preferences = PatientPreferencesState.Unavailable(
                    "Preferences are not available right now. Your care view is unchanged.",
                ),
            )
            else -> state.copy(
                preferences = PatientPreferencesState.Ready(ready.snapshot.preferences),
            )
        }
    }

    fun dismissPreferences() {
        state = state.copy(preferences = PatientPreferencesState.Hidden)
    }

    /**
     * Persists patient account preferences only. The request cannot update a care plan, clinical
     * order, urgent-help instruction, or responsible care-team workflow; failures are not queued.
     */
    fun savePreferences(update: PatientPreferencesUpdate) {
        val ready = state.session as? PatientSessionState.Ready ?: return
        val current = state.preferences as? PatientPreferencesState.Ready ?: return
        if (current.saving) return

        if (ready.synthetic) {
            val updated = update.applyTo(current.preferences)
            state = state.copy(
                session = ready.copy(snapshot = ready.snapshot.copy(preferences = updated)),
                preferences = PatientPreferencesState.Ready(
                    preferences = updated,
                    message = "Reference settings updated on this device. No patient account was changed.",
                ),
            )
            return
        }

        val availableCoordinator = coordinator ?: run {
            state = state.copy(
                preferences = PatientPreferencesState.Unavailable(
                    "Preferences are not available right now. Your care view is unchanged.",
                ),
            )
            return
        }
        state = state.copy(preferences = current.copy(saving = true, message = null))
        operationScope.launch {
            try {
                val updated = withContext(workDispatcher) {
                    availableCoordinator.updatePreferences(update)
                }
                val currentSession = state.session as? PatientSessionState.Ready ?: return@launch
                state = state.copy(
                    session = currentSession.copy(
                        snapshot = currentSession.snapshot.copy(preferences = updated),
                    ),
                    preferences = if (state.preferences is PatientPreferencesState.Hidden) {
                        PatientPreferencesState.Hidden
                    } else {
                        PatientPreferencesState.Ready(
                            preferences = updated,
                            message = "Your preferences were saved. They do not change your care plan or urgent-help guidance.",
                        )
                    },
                )
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (error: PatientApiException) {
                if (error.statusCode == 401) {
                    transitionToSignedOut()
                } else {
                    showPreferencesSaveFailure()
                }
            } catch (_: Exception) {
                showPreferencesSaveFailure()
            }
        }
    }

    fun onAppBackgrounded() {
        dismissDeviceSessions()
        dismissPreferences()
    }

    fun selectDeviceSessionForRevocation(sessionUuid: String) {
        val current = state.deviceSessions as? PatientDeviceSessionsState.Ready ?: return
        if (current.operation is PatientDeviceSessionOperation.Working) return
        val selected = current.sessions.firstOrNull { it.sessionUuid == sessionUuid } ?: return
        state = state.copy(
            deviceSessions = current.copy(
                selectedForRevocation = selected,
                operation = PatientDeviceSessionOperation.Idle,
            ),
        )
    }

    fun cancelDeviceSessionRevocation() {
        val current = state.deviceSessions as? PatientDeviceSessionsState.Ready ?: return
        if (current.operation is PatientDeviceSessionOperation.Working) return
        state = state.copy(
            deviceSessions = current.copy(selectedForRevocation = null),
        )
    }

    fun confirmDeviceSessionRevocation() {
        val current = state.deviceSessions as? PatientDeviceSessionsState.Ready ?: return
        val selected = current.selectedForRevocation ?: return
        if (current.operation is PatientDeviceSessionOperation.Working) return
        val availableCoordinator = coordinator ?: return

        state = state.copy(
            deviceSessions = current.copy(
                selectedForRevocation = null,
                operation = PatientDeviceSessionOperation.Working(selected.sessionUuid),
            ),
        )
        sessionManagementJob?.cancel()
        sessionRevocationInFlight = true
        sessionManagementJob = operationScope.launch {
            var revocationConfirmed = false
            try {
                val outcome = withContext(workDispatcher) {
                    availableCoordinator.revokePatientSession(selected.sessionUuid)
                }
                revocationConfirmed = true
                if (outcome.currentSessionRevoked) {
                    transitionToSignedOut()
                    return@launch
                }
                if (state.deviceSessions is PatientDeviceSessionsState.Hidden) {
                    return@launch
                }

                val refreshed = withContext(workDispatcher) {
                    availableCoordinator.patientSessions()
                }
                state = state.copy(
                    deviceSessions = PatientDeviceSessionsState.Ready(
                        sessions = refreshed,
                        operation = PatientDeviceSessionOperation.Notice(
                            "That device is signed out. This device remains signed in.",
                        ),
                    ),
                )
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (error: PatientApiException) {
                if (error.statusCode == 401) {
                    transitionToSignedOut()
                } else {
                    updateDeviceSessionFailure(
                        if (revocationConfirmed) {
                            "That device was signed out, but the device list could not be refreshed. Refresh the list to review current sessions."
                        } else if (error.statusCode == 404) {
                            "Device management is not available right now. No additional request was sent."
                        } else {
                            "Hummingbird could not confirm that device was signed out. Review the list before trying again."
                        },
                    )
                }
            } catch (_: Exception) {
                updateDeviceSessionFailure(
                    if (revocationConfirmed) {
                        "That device was signed out, but the device list could not be refreshed. Refresh the list to review current sessions."
                    } else {
                        "Hummingbird could not confirm that device was signed out. Review the list before trying again."
                    },
                )
            } finally {
                sessionRevocationInFlight = false
                sessionManagementJob = null
            }
        }
    }

    fun refreshMessages() {
        val ready = state.session as? PatientSessionState.Ready ?: return
        loadMessaging(ready.snapshot)
    }

    fun selectMessageThread(threadUuid: String) {
        val messaging = state.messaging as? PatientMessagingState.Ready ?: return
        if (messaging.operation is PatientMessagingOperation.Working) return
        if ((state.session as? PatientSessionState.Ready)?.synthetic == true) {
            state = state.copy(
                messaging = messaging.copy(
                    selectedThread = messaging.threads.firstOrNull { it.threadUuid == threadUuid },
                    operation = PatientMessagingOperation.Idle,
                ),
            )
            return
        }
        val availableCoordinator = coordinator ?: return
        state = state.copy(
            messaging = messaging.copy(
                operation = PatientMessagingOperation.Working("Opening this conversation securely"),
            ),
        )
        operationScope.launch {
            try {
                val thread = withContext(workDispatcher) {
                    availableCoordinator.messageThread(threadUuid)
                }
                updateMessaging { current ->
                    current.copy(
                        selectedThread = thread,
                        operation = PatientMessagingOperation.Idle,
                    )
                }
            } catch (_: Exception) {
                updateMessaging { current ->
                    current.copy(
                        operation = PatientMessagingOperation.Failure(
                            "This conversation could not be opened securely. Try again.",
                        ),
                    )
                }
            }
        }
    }

    fun leaveMessageThread() {
        updateMessaging { current ->
            current.copy(selectedThread = null, operation = PatientMessagingOperation.Idle)
        }
    }

    fun createMessageThread(topicCode: String, message: String) {
        val messaging = state.messaging as? PatientMessagingState.Ready ?: return
        val validation = validateMessage(message)
        if (
            !messaging.canWrite ||
            messaging.topics.none { it.code == topicCode } ||
            validation != null
        ) {
            state = state.copy(
                messaging = messaging.copy(
                    operation = PatientMessagingOperation.Failure(
                        validation ?: "That secure message topic is not available for this stay.",
                    ),
                ),
            )
            return
        }
        val ready = state.session as? PatientSessionState.Ready ?: return
        val encounterUuid = ready.snapshot.encounterUuid ?: return
        val availableCoordinator = coordinator ?: return
        val trimmed = message.trim()
        state = state.copy(
            messaging = messaging.copy(
                operation = PatientMessagingOperation.Working("Sending your message securely"),
            ),
        )
        operationScope.launch {
            try {
                val created = withContext(workDispatcher) {
                    availableCoordinator.createMessageThread(
                        encounterUuid = encounterUuid,
                        topicCode = topicCode,
                        message = trimmed,
                        urgentGuidanceVersion = messaging.immediateHelp.version,
                    )
                }
                val refreshed = runCatching {
                    withContext(workDispatcher) {
                        availableCoordinator.messagingOverview(encounterUuid)
                    }
                }.getOrNull()
                if (refreshed != null) {
                    state = state.copy(
                        messaging = PatientMessagingState.Ready(
                            topics = refreshed.topics,
                            threads = refreshed.threads,
                            immediateHelp = refreshed.immediateHelp,
                            canWrite = true,
                            operation = PatientMessagingOperation.Notice(
                                "Your message was sent to the responsible care-team pool.",
                            ),
                        ),
                    )
                } else {
                    updateMessaging { current ->
                        current.copy(
                            threads = listOf(created) + current.threads.filterNot {
                                it.threadUuid == created.threadUuid
                            },
                            operation = PatientMessagingOperation.Notice(
                                "Your message was sent. Refresh to check the latest care-team response.",
                            ),
                        )
                    }
                }
            } catch (error: PatientApiException) {
                handleMessagingMutationFailure(error, threadUuid = null)
            } catch (_: Exception) {
                genericMessagingFailure()
            }
        }
    }

    fun requestEducationClarification(educationItemUuid: String, message: String) {
        val ready = state.session as? PatientSessionState.Ready ?: return
        val messaging = state.messaging as? PatientMessagingState.Ready ?: return
        val validation = validateMessage(message)
        if (
            ready.synthetic ||
            !messaging.canWrite ||
            ready.snapshot.pathwayEducation.none { it.id == educationItemUuid } ||
            validation != null
        ) {
            state = state.copy(
                messaging = messaging.copy(
                    operation = PatientMessagingOperation.Failure(
                        validation
                            ?: "A secure request to explain this information is not available for this stay. Ask your bedside nurse for help.",
                    ),
                ),
            )
            return
        }
        val encounterUuid = ready.snapshot.encounterUuid ?: return
        val availableCoordinator = coordinator ?: return
        val trimmed = message.trim()
        state = state.copy(
            messaging = messaging.copy(
                operation = PatientMessagingOperation.Working("Sending your request securely"),
            ),
        )
        operationScope.launch {
            try {
                val created = withContext(workDispatcher) {
                    availableCoordinator.requestEducationClarification(
                        encounterUuid = encounterUuid,
                        educationItemUuid = educationItemUuid,
                        message = trimmed,
                        urgentGuidanceVersion = messaging.immediateHelp.version,
                    )
                }
                updateMessaging { current ->
                    current.copy(
                        threads = listOf(created) + current.threads.filterNot {
                            it.threadUuid == created.threadUuid
                        },
                        operation = PatientMessagingOperation.Notice(
                            "Your request for an explanation was sent to your care team. It does not record that you understand, complete, or agree to this information.",
                        ),
                    )
                }
            } catch (error: PatientApiException) {
                handleMessagingMutationFailure(error, threadUuid = null)
            } catch (_: Exception) {
                genericMessagingFailure()
            }
        }
    }

    fun sendMessage(message: String) {
        val messaging = state.messaging as? PatientMessagingState.Ready ?: return
        val thread = messaging.selectedThread ?: return
        val validation = validateMessage(message)
        if (!messaging.canWrite || thread.status != "open" || validation != null) {
            state = state.copy(
                messaging = messaging.copy(
                    operation = PatientMessagingOperation.Failure(
                        validation ?: "This conversation is closed or unavailable for replies.",
                    ),
                ),
            )
            return
        }
        val availableCoordinator = coordinator ?: return
        val trimmed = message.trim()
        state = state.copy(
            messaging = messaging.copy(
                operation = PatientMessagingOperation.Working("Sending your reply securely"),
            ),
        )
        operationScope.launch {
            try {
                val result = withContext(workDispatcher) {
                    availableCoordinator.sendMessage(
                        threadUuid = thread.threadUuid,
                        threadVersion = thread.version,
                        message = trimmed,
                        urgentGuidanceVersion = messaging.immediateHelp.version,
                    )
                }
                updateMessaging { current ->
                    val updatedThread = result.thread.copy(
                        messages = thread.messages + result.message,
                    )
                    current.copy(
                        threads = current.threads.replaceSummary(updatedThread),
                        selectedThread = updatedThread,
                        operation = PatientMessagingOperation.Notice(
                            "Your reply was sent to the responsible care-team pool.",
                        ),
                    )
                }
            } catch (error: PatientApiException) {
                handleMessagingMutationFailure(error, thread.threadUuid)
            } catch (_: Exception) {
                genericMessagingFailure()
            }
        }
    }

    fun amendMessage(
        messageUuid: String,
        action: PatientMessageAmendmentAction,
        message: String? = null,
    ) {
        val messaging = state.messaging as? PatientMessagingState.Ready ?: return
        val thread = messaging.selectedThread ?: return
        val source = thread.messages.firstOrNull { it.messageUuid == messageUuid }
        val sourceAlreadyAmended = thread.messages.any {
            it.relatesToMessageUuid == messageUuid &&
                it.messageKind in setOf("correction", "retraction")
        }
        if (
            !messaging.canWrite ||
            thread.status != "open" ||
            messaging.operation is PatientMessagingOperation.Working ||
            source == null ||
            source.senderDisplayRole != "You" ||
            source.messageKind != "message" ||
            sourceAlreadyAmended
        ) {
            state = state.copy(
                messaging = messaging.copy(
                    operation = PatientMessagingOperation.Failure(
                        "This message can no longer be corrected or withdrawn. Refresh the conversation to review it.",
                    ),
                ),
            )
            return
        }
        val trimmed = message?.trim()
        if (action == PatientMessageAmendmentAction.Correction && validateMessage(trimmed.orEmpty()) != null) {
            state = state.copy(
                messaging = messaging.copy(
                    operation = PatientMessagingOperation.Failure(
                        "Enter a correction between 1 and 2,000 characters.",
                    ),
                ),
            )
            return
        }
        if (action == PatientMessageAmendmentAction.Retraction && message != null) {
            state = state.copy(
                messaging = messaging.copy(
                    operation = PatientMessagingOperation.Failure(
                        "A withdrawal does not include a replacement message.",
                    ),
                ),
            )
            return
        }
        val availableCoordinator = coordinator ?: return
        state = state.copy(
            messaging = messaging.copy(
                operation = PatientMessagingOperation.Working(
                    if (action == PatientMessageAmendmentAction.Correction) {
                        "Sending your correction securely"
                    } else {
                        "Sending your withdrawal securely"
                    },
                ),
            ),
        )
        operationScope.launch {
            try {
                val result = withContext(workDispatcher) {
                    availableCoordinator.amendMessage(
                        threadUuid = thread.threadUuid,
                        messageUuid = messageUuid,
                        threadVersion = thread.version,
                        action = action,
                        message = if (action == PatientMessageAmendmentAction.Correction) trimmed else null,
                        urgentGuidanceVersion = messaging.immediateHelp.version,
                    )
                }
                updateMessaging { current ->
                    val updatedThread = result.thread.copy(
                        messages = thread.messages + result.message,
                    )
                    current.copy(
                        threads = current.threads.replaceSummary(updatedThread),
                        selectedThread = updatedThread,
                        operation = PatientMessagingOperation.Notice(
                            if (action == PatientMessageAmendmentAction.Correction) {
                                "Your correction was sent to your care team. The earlier message remains in the conversation history."
                            } else {
                                "Your withdrawal was sent to your care team. The earlier message remains in the conversation history."
                            },
                        ),
                    )
                }
            } catch (error: PatientApiException) {
                handleMessagingMutationFailure(error, thread.threadUuid)
            } catch (_: Exception) {
                genericMessagingFailure()
            }
        }
    }

    fun closeMessageThread() {
        val messaging = state.messaging as? PatientMessagingState.Ready ?: return
        val thread = messaging.selectedThread ?: return
        if (!messaging.canWrite || thread.status != "open") return
        val availableCoordinator = coordinator ?: return
        state = state.copy(
            messaging = messaging.copy(
                operation = PatientMessagingOperation.Working("Closing this conversation securely"),
            ),
        )
        operationScope.launch {
            try {
                val closed = withContext(workDispatcher) {
                    availableCoordinator.closeMessageThread(thread.threadUuid, thread.version)
                }.copy(messages = thread.messages)
                updateMessaging { current ->
                    current.copy(
                        threads = current.threads.replaceSummary(closed),
                        selectedThread = closed,
                        operation = PatientMessagingOperation.Notice("This conversation is closed."),
                    )
                }
            } catch (error: PatientApiException) {
                handleMessagingMutationFailure(error, thread.threadUuid)
            } catch (_: Exception) {
                genericMessagingFailure()
            }
        }
    }

    fun signOut() {
        dismissDeviceSessions()
        val session = state.session
        if (session is PatientSessionState.Ready && session.synthetic) {
            state = PatientUiState(session = PatientSessionState.SignedOut())
            return
        }
        val availableCoordinator = coordinator
        if (availableCoordinator == null) {
            state = PatientUiState(session = PatientSessionState.SignedOut())
            return
        }
        state = state.copy(session = PatientSessionState.Loading("Signing out securely"))
        operationScope.launch {
            val result = withContext(workDispatcher) { availableCoordinator.signOut() }
            state = PatientUiState(
                session = PatientSessionState.SignedOut(
                    status = if (result.serverRevoked) {
                        PatientAuthStatus.Idle
                    } else {
                        PatientAuthStatus.Unavailable(
                            "Signed out on this device. The server could not confirm revocation; try again when connected.",
                        )
                    },
                ),
            )
        }
    }

    fun close() {
        sessionManagementJob?.cancel()
        operationScope.cancel()
    }

    private fun showDeviceSessionsUnavailable(featureDisabled: Boolean) {
        state = state.copy(
            deviceSessions = PatientDeviceSessionsState.Unavailable(
                message = if (featureDisabled) {
                    "Manage devices is not available right now. You can keep using Hummingbird Patient."
                } else {
                    "Your devices could not be checked right now. Your care information is still available."
                },
                canRetry = !featureDisabled,
            ),
        )
    }

    private fun updateDeviceSessionFailure(message: String) {
        when (val current = state.deviceSessions) {
            is PatientDeviceSessionsState.Ready -> {
                state = state.copy(
                    deviceSessions = current.copy(
                        selectedForRevocation = null,
                        operation = PatientDeviceSessionOperation.Failure(message),
                    ),
                )
            }
            PatientDeviceSessionsState.Hidden -> Unit
            else -> {
                state = state.copy(
                    deviceSessions = PatientDeviceSessionsState.Unavailable(
                        message = message,
                        canRetry = true,
                    ),
                )
            }
        }
    }

    private fun transitionToSignedOut() {
        sessionManagementJob = null
        state = PatientUiState(session = PatientSessionState.SignedOut())
    }

    private fun showPreferencesSaveFailure() {
        val current = state.preferences as? PatientPreferencesState.Ready ?: return
        state = state.copy(
            preferences = current.copy(
                saving = false,
                message = "We could not save your preferences. No change was queued. Check your connection and try again.",
            ),
        )
    }

    private fun execute(message: String, operation: () -> PatientSessionOutcome) {
        state = state.copy(session = PatientSessionState.Loading(message))
        operationScope.launch {
            try {
                val outcome = withContext(workDispatcher) { operation() }
                state = when (outcome) {
                    is PatientSessionOutcome.Ready -> PatientUiState(
                        session = PatientSessionState.Ready(outcome.snapshot, synthetic = false),
                    )
                    is PatientSessionOutcome.Empty -> if (outcome.message == SIGNED_OUT_SENTINEL) {
                        PatientUiState(session = PatientSessionState.SignedOut())
                    } else {
                        PatientUiState(
                            session = PatientSessionState.Empty(outcome.displayName, outcome.message),
                        )
                    }
                }
                if (outcome is PatientSessionOutcome.Ready) {
                    loadMessaging(outcome.snapshot)
                }
            } catch (error: Exception) {
                state = PatientUiState(
                    session = PatientSessionState.SignedOut(
                        status = PatientAuthStatus.Failure(error.patientMessage()),
                    ),
                )
            }
        }
    }

    private fun unavailable(
        signedOut: PatientSessionState.SignedOut,
        message: String,
    ) {
        state = state.copy(session = signedOut.copy(status = PatientAuthStatus.Unavailable(message)))
    }

    private fun loadMessaging(snapshot: PatientSnapshot) {
        val encounterUuid = snapshot.encounterUuid
        if (encounterUuid == null || "messaging:read" !in snapshot.encounterScopes) {
            state = state.copy(
                messaging = PatientMessagingState.Unavailable(
                    "Secure care-team messaging is not available for this stay.",
                ),
            )
            return
        }
        val availableCoordinator = coordinator ?: return
        state = state.copy(
            messaging = PatientMessagingState.Loading("Checking care-team messages securely"),
        )
        operationScope.launch {
            try {
                val overview = withContext(workDispatcher) {
                    availableCoordinator.messagingOverview(encounterUuid)
                }
                state = state.copy(
                    messaging = PatientMessagingState.Ready(
                        topics = overview.topics,
                        threads = overview.threads,
                        immediateHelp = overview.immediateHelp,
                        canWrite = "messaging:write" in snapshot.encounterScopes,
                    ),
                )
            } catch (_: Exception) {
                state = state.copy(
                    messaging = PatientMessagingState.Unavailable(
                        "Secure care-team messaging is not available right now. Use your call button or speak with staff.",
                    ),
                )
            }
        }
    }

    private suspend fun handleMessagingMutationFailure(
        error: PatientApiException,
        threadUuid: String?,
    ) {
        when (error.errorCode) {
            "stale_thread_version" -> {
                val refreshed = threadUuid?.let { uuid ->
                    runCatching {
                        withContext(workDispatcher) { coordinator?.messageThread(uuid) }
                    }.getOrNull()
                }
                updateMessaging { current ->
                    if (refreshed != null) {
                        current.copy(
                            threads = current.threads.replaceSummary(refreshed),
                            selectedThread = refreshed,
                            operation = PatientMessagingOperation.Failure(
                                "This conversation changed while you were viewing it. Review the latest messages, then try again.",
                            ),
                        )
                    } else {
                        current.copy(
                            selectedThread = null,
                            operation = PatientMessagingOperation.Failure(
                                "This conversation changed and could not be refreshed. Open it again before replying.",
                            ),
                        )
                    }
                }
            }
            "urgent_guidance_changed" -> {
                val snapshot = (state.session as? PatientSessionState.Ready)?.snapshot
                if (snapshot != null) {
                    loadMessaging(snapshot)
                } else {
                    genericMessagingFailure()
                }
            }
            else -> genericMessagingFailure()
        }
    }

    private fun genericMessagingFailure() {
        updateMessaging { current ->
            current.copy(
                operation = PatientMessagingOperation.Failure(
                    "Hummingbird could not confirm this request. Refresh before trying again; use your call button for immediate help.",
                ),
            )
        }
    }

    private fun updateMessaging(
        transform: (PatientMessagingState.Ready) -> PatientMessagingState.Ready,
    ) {
        val current = state.messaging as? PatientMessagingState.Ready ?: return
        state = state.copy(messaging = transform(current))
    }

    private fun validateMessage(message: String): String? = when {
        message.isBlank() -> "Enter a message before sending."
        message.trim().length > 2_000 -> "Keep your message to 2,000 characters or fewer."
        message.any { it.code in 0..8 || it.code in 11..12 || it.code in 14..31 || it.code == 127 } ->
            "Remove unsupported control characters before sending."
        else -> null
    }

    private fun List<net.acumenus.hummingbird.patient.data.PatientMessageThread>.replaceSummary(
        updated: net.acumenus.hummingbird.patient.data.PatientMessageThread,
    ): List<net.acumenus.hummingbird.patient.data.PatientMessageThread> =
        map { existing -> if (existing.threadUuid == updated.threadUuid) updated else existing }

    private fun Exception.patientMessage(): String = when (this) {
        is PatientApiException -> when (statusCode) {
            401 -> "Your patient session or sign-in could not be verified. Check your information and try again."
            403, 404 -> "That patient information is not available. Ask your care team if you expected to see it."
            422 -> message ?: "The invitation or account information could not be verified."
            429 -> "Too many attempts. Wait a moment before trying again."
            else -> "Hummingbird Patient could not load your information securely. Try again."
        }
        else -> "Hummingbird Patient could not connect securely. Check your connection and try again."
    }

    private companion object {
        const val SIGNED_OUT_SENTINEL = "signed-out"
    }
}
