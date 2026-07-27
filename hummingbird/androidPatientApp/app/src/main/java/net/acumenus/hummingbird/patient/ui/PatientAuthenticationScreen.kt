package net.acumenus.hummingbird.patient.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Lock
import androidx.compose.material.icons.outlined.VerifiedUser
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusDirection
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import net.acumenus.hummingbird.patient.PatientAuthMode
import net.acumenus.hummingbird.patient.PatientAuthStatus
import net.acumenus.hummingbird.patient.PatientEnrollmentForm
import net.acumenus.hummingbird.patient.PatientSessionState

@Composable
internal fun PatientAuthenticationScreen(
    state: PatientSessionState.SignedOut,
    networkEnabled: Boolean,
    onAuthModeSelected: (PatientAuthMode) -> Unit,
    onSignIn: (String, String) -> Unit,
    onEnroll: (PatientEnrollmentForm) -> Unit,
) {
    PatientScenicBackground(scene = PatientScene.WELCOME) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .statusBarsPadding()
                .navigationBarsPadding()
                .padding(horizontal = 24.dp, vertical = 28.dp),
            verticalArrangement = Arrangement.spacedBy(20.dp),
        ) {
            Icon(
                imageVector = Icons.Outlined.VerifiedUser,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.primary,
            )
            Text(
                text = "Hummingbird Patient",
                style = MaterialTheme.typography.headlineMedium,
                fontWeight = FontWeight.Bold,
                modifier = Modifier.semantics { heading() },
            )
            Text(
                text = "Understand today’s care, see the path ahead, and know who is caring for you.",
                style = MaterialTheme.typography.bodyLarge,
            )

            Card(
                colors = CardDefaults.cardColors(
                    containerColor = MaterialTheme.colorScheme.primaryContainer,
                ),
            ) {
                Row(
                    modifier = Modifier.padding(16.dp),
                    horizontalArrangement = Arrangement.spacedBy(12.dp),
                ) {
                    Icon(Icons.Outlined.Lock, contentDescription = null)
                    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(
                            text = "A separate patient account",
                            style = MaterialTheme.typography.titleMedium,
                            fontWeight = FontWeight.SemiBold,
                        )
                        Text(
                            text = "Your hospital staff account cannot be used here. Patient information stays behind a separate sign-in and protected storage.",
                            style = MaterialTheme.typography.bodyMedium,
                        )
                    }
                }
            }

            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                FilterChip(
                    selected = state.authMode == PatientAuthMode.ENROLL,
                    onClick = { onAuthModeSelected(PatientAuthMode.ENROLL) },
                    label = { Text("Use invitation") },
                )
                FilterChip(
                    selected = state.authMode == PatientAuthMode.SIGN_IN,
                    onClick = { onAuthModeSelected(PatientAuthMode.SIGN_IN) },
                    label = { Text("Sign in") },
                )
            }

            when (state.authMode) {
                PatientAuthMode.ENROLL -> EnrollmentForm(onEnroll)
                PatientAuthMode.SIGN_IN -> SignInForm(onSignIn)
            }

            when (val status = state.status) {
                PatientAuthStatus.Idle -> Unit
                is PatientAuthStatus.ValidationError -> StatusMessage(
                    message = status.message,
                    isError = true,
                )
                is PatientAuthStatus.Unavailable -> StatusMessage(
                    message = status.message,
                    isError = false,
                )
                is PatientAuthStatus.Failure -> StatusMessage(
                    message = status.message,
                    isError = true,
                )
            }

            if (!networkEnabled) {
                Text(
                    text = "Online patient access is off by default in this pilot build.",
                    style = MaterialTheme.typography.labelLarge,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }

            Text(
                text = "Need help getting access? Ask your bedside nurse or another member of your care team. Do not use this app for emergencies.",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@Composable
private fun EnrollmentForm(onEnroll: (PatientEnrollmentForm) -> Unit) {
    // Enrollment secrets and identifiers stay in volatile composition memory;
    // they must never be serialized into Activity saved state.
    var challengeUuid by remember { mutableStateOf("") }
    var challengeToken by remember { mutableStateOf("") }
    var verificationCode by remember { mutableStateOf("") }
    var displayName by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var passwordConfirmation by remember { mutableStateOf("") }
    val focusManager = LocalFocusManager.current
    fun submit() {
        focusManager.clearFocus()
        onEnroll(
            PatientEnrollmentForm(
                challengeUuid = challengeUuid,
                challengeToken = challengeToken,
                verificationCode = verificationCode,
                displayName = displayName,
                email = email,
                password = password,
                passwordConfirmation = passwordConfirmation,
            ),
        )
        challengeToken = ""
        verificationCode = ""
        password = ""
        passwordConfirmation = ""
    }
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text(
            text = "Use your hospital invitation",
            style = MaterialTheme.typography.titleLarge,
            modifier = Modifier.semantics { heading() },
        )
        Text(
            text = "Use the invitation details provided by your care team, then create your separate patient account.",
            style = MaterialTheme.typography.bodyMedium,
        )
        OutlinedTextField(
            value = challengeUuid,
            onValueChange = { challengeUuid = it },
            modifier = Modifier.fillMaxWidth(),
            label = { Text("Invitation ID") },
            singleLine = true,
            keyboardOptions = KeyboardOptions(
                keyboardType = KeyboardType.Ascii,
                imeAction = ImeAction.Next,
            ),
            keyboardActions = KeyboardActions(onNext = { focusManager.moveFocus(FocusDirection.Down) }),
        )
        OutlinedTextField(
            value = challengeToken,
            onValueChange = { challengeToken = it },
            modifier = Modifier.fillMaxWidth(),
            label = { Text("Invitation secret") },
            supportingText = { Text("Invitation details expire and can only be used as directed.") },
            singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(
                keyboardType = KeyboardType.Password,
                imeAction = ImeAction.Next,
            ),
            keyboardActions = KeyboardActions(onNext = { focusManager.moveFocus(FocusDirection.Down) }),
        )
        OutlinedTextField(
            value = verificationCode,
            onValueChange = { verificationCode = it },
            modifier = Modifier.fillMaxWidth(),
            label = { Text("Verification code") },
            singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(
                keyboardType = KeyboardType.NumberPassword,
                imeAction = ImeAction.Next,
            ),
            keyboardActions = KeyboardActions(onNext = { focusManager.moveFocus(FocusDirection.Down) }),
        )
        OutlinedTextField(
            value = displayName,
            onValueChange = { displayName = it },
            modifier = Modifier.fillMaxWidth(),
            label = { Text("Name") },
            singleLine = true,
            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Next),
            keyboardActions = KeyboardActions(onNext = { focusManager.moveFocus(FocusDirection.Down) }),
        )
        OutlinedTextField(
            value = email,
            onValueChange = { email = it },
            modifier = Modifier.fillMaxWidth(),
            label = { Text("Email") },
            singleLine = true,
            keyboardOptions = KeyboardOptions(
                keyboardType = KeyboardType.Email,
                imeAction = ImeAction.Next,
            ),
            keyboardActions = KeyboardActions(onNext = { focusManager.moveFocus(FocusDirection.Down) }),
        )
        OutlinedTextField(
            value = password,
            onValueChange = { password = it },
            modifier = Modifier.fillMaxWidth(),
            label = { Text("Create password") },
            supportingText = { Text("Use at least 12 characters with mixed character types.") },
            singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(
                keyboardType = KeyboardType.Password,
                imeAction = ImeAction.Next,
            ),
            keyboardActions = KeyboardActions(onNext = { focusManager.moveFocus(FocusDirection.Down) }),
        )
        OutlinedTextField(
            value = passwordConfirmation,
            onValueChange = { passwordConfirmation = it },
            modifier = Modifier.fillMaxWidth(),
            label = { Text("Confirm password") },
            singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(
                keyboardType = KeyboardType.Password,
                imeAction = ImeAction.Done,
            ),
            keyboardActions = KeyboardActions(onDone = { submit() }),
        )
        Button(
            onClick = ::submit,
            modifier = Modifier.fillMaxWidth(),
        ) {
            Text("Continue securely")
        }
    }
}

@Composable
private fun SignInForm(onSignIn: (String, String) -> Unit) {
    // Credentials are intentionally not part of Activity saved state.
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    val focusManager = LocalFocusManager.current
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text(
            text = "Sign in to your patient account",
            style = MaterialTheme.typography.titleLarge,
            modifier = Modifier.semantics { heading() },
        )
        OutlinedTextField(
            value = email,
            onValueChange = { email = it },
            modifier = Modifier.fillMaxWidth(),
            label = { Text("Email") },
            singleLine = true,
            keyboardOptions = KeyboardOptions(
                keyboardType = KeyboardType.Email,
                imeAction = ImeAction.Next,
            ),
            keyboardActions = KeyboardActions(onNext = {
                focusManager.moveFocus(FocusDirection.Down)
            }),
        )
        OutlinedTextField(
            value = password,
            onValueChange = { password = it },
            modifier = Modifier.fillMaxWidth(),
            label = { Text("Password") },
            singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(
                keyboardType = KeyboardType.Password,
                imeAction = ImeAction.Done,
            ),
            keyboardActions = KeyboardActions(onDone = {
                focusManager.clearFocus()
                onSignIn(email, password)
                password = ""
            }),
        )
        Button(
            onClick = {
                focusManager.clearFocus()
                onSignIn(email, password)
                password = ""
            },
            modifier = Modifier.fillMaxWidth(),
        ) {
            Text("Sign in")
        }
    }
}

@Composable
private fun StatusMessage(message: String, isError: Boolean) {
    Spacer(modifier = Modifier.height(2.dp))
    Card(
        colors = CardDefaults.cardColors(
            containerColor = if (isError) {
                MaterialTheme.colorScheme.errorContainer
            } else {
                MaterialTheme.colorScheme.secondaryContainer
            },
        ),
        modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite },
    ) {
        Text(
            text = message,
            modifier = Modifier.padding(16.dp),
            color = if (isError) {
                MaterialTheme.colorScheme.onErrorContainer
            } else {
                MaterialTheme.colorScheme.onSecondaryContainer
            },
        )
    }
}
