package net.acumenus.hummingbird.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Dashboard
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.lifecycle.viewmodel.compose.viewModel
import net.acumenus.hummingbird.data.AltitudeViewModel
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.ui.theme.Z

private enum class AltitudeTopTab { Home, Workspace, Activity }

private sealed interface AltitudeDetail {
    data class Drill(val itemUuid: String) : AltitudeDetail
    data class Patient(val patientContextRef: String) : AltitudeDetail
    data class Eddy(val scopeRef: String) : AltitudeDetail
}

@Composable
fun MainScreen(auth: AuthViewModel) {
    val vm: AltitudeViewModel = viewModel()
    var topTab by remember { mutableStateOf(AltitudeTopTab.Home) }
    var detail by remember { mutableStateOf<AltitudeDetail?>(null) }
    val bearer = auth.accessToken ?: ""

    LaunchedEffect(vm.needsReauth) { if (vm.needsReauth) auth.logout() }
    BackHandler(enabled = detail != null) { detail = null }

    Scaffold(
        containerColor = Z.bg,
        bottomBar = {
            if (detail == null) {
                NavigationBar(containerColor = Z.surface) {
                    val colors = NavigationBarItemDefaults.colors(
                        selectedIconColor = Z.primary,
                        selectedTextColor = Z.primary,
                        indicatorColor = Z.primary.copy(alpha = 0.15f),
                        unselectedIconColor = Z.inkMuted,
                        unselectedTextColor = Z.inkMuted,
                    )
                    NavigationBarItem(
                        selected = topTab == AltitudeTopTab.Home,
                        onClick = { topTab = AltitudeTopTab.Home },
                        icon = { Icon(Icons.Filled.Home, contentDescription = null) },
                        label = { Text("A0") },
                        colors = colors,
                    )
                    NavigationBarItem(
                        selected = topTab == AltitudeTopTab.Workspace,
                        onClick = { topTab = AltitudeTopTab.Workspace },
                        icon = { Icon(Icons.Filled.Dashboard, contentDescription = null) },
                        label = { Text("A1") },
                        colors = colors,
                    )
                    NavigationBarItem(
                        selected = topTab == AltitudeTopTab.Activity,
                        onClick = { topTab = AltitudeTopTab.Activity },
                        icon = { Icon(Icons.Filled.Notifications, contentDescription = null) },
                        label = { Text("Activity") },
                        colors = colors,
                    )
                }
            }
        },
    ) { inner ->
        Box(Modifier.fillMaxSize().padding(inner)) {
            val currentDetail = detail
            if (currentDetail == null) {
                when (topTab) {
                    AltitudeTopTab.Home -> AltitudeHomeScreen(
                        auth = auth,
                        vm = vm,
                        bearer = bearer,
                        onOpenDrill = { detail = AltitudeDetail.Drill(it) },
                        onOpenPatient = { detail = AltitudeDetail.Patient(it) },
                        onOpenEddy = { detail = AltitudeDetail.Eddy(it) },
                    )
                    AltitudeTopTab.Workspace -> AltitudeWorkspaceScreen(
                        auth = auth,
                        vm = vm,
                        bearer = bearer,
                        onOpenDrill = { detail = AltitudeDetail.Drill(it) },
                        onOpenPatient = { detail = AltitudeDetail.Patient(it) },
                    )
                    AltitudeTopTab.Activity -> ActivityFeedScreen(
                        auth = auth,
                        vm = vm,
                        bearer = bearer,
                        onOpenDrill = { detail = AltitudeDetail.Drill(it) },
                        onOpenPatient = { detail = AltitudeDetail.Patient(it) },
                    )
                }
            } else {
                when (currentDetail) {
                    is AltitudeDetail.Drill -> DrillDetailScreen(
                        vm = vm,
                        bearer = bearer,
                        itemUuid = currentDetail.itemUuid,
                        onBack = { detail = null },
                        onOpenPatient = { detail = AltitudeDetail.Patient(it) },
                        onOpenEddy = { detail = AltitudeDetail.Eddy(it) },
                    )
                    is AltitudeDetail.Patient -> PatientContextScreen(
                        vm = vm,
                        bearer = bearer,
                        patientContextRef = currentDetail.patientContextRef,
                        onBack = { detail = null },
                        onOpenEddy = { detail = AltitudeDetail.Eddy(it) },
                    )
                    is AltitudeDetail.Eddy -> EddyContextScreen(
                        vm = vm,
                        bearer = bearer,
                        scopeRef = currentDetail.scopeRef,
                        onBack = { detail = null },
                    )
                }
            }
        }
    }
}
