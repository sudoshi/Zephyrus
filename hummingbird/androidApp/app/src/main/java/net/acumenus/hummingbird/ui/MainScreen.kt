package net.acumenus.hummingbird.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Apartment
import androidx.compose.material.icons.filled.Inbox
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.ui.theme.Z

/** Signed-in shell: House Status + the For You queue, behind a bottom nav. */
@Composable
fun MainScreen(auth: AuthViewModel) {
    var tab by remember { mutableStateOf(0) }
    Scaffold(
        containerColor = Z.bg,
        bottomBar = {
            NavigationBar(containerColor = Z.surface) {
                val colors = NavigationBarItemDefaults.colors(
                    selectedIconColor = Z.primary,
                    selectedTextColor = Z.primary,
                    indicatorColor = Z.primary.copy(alpha = 0.15f),
                    unselectedIconColor = Z.inkMuted,
                    unselectedTextColor = Z.inkMuted,
                )
                NavigationBarItem(
                    selected = tab == 0, onClick = { tab = 0 },
                    icon = { Icon(Icons.Filled.Apartment, contentDescription = null) },
                    label = { Text("House") }, colors = colors,
                )
                NavigationBarItem(
                    selected = tab == 1, onClick = { tab = 1 },
                    icon = { Icon(Icons.Filled.Inbox, contentDescription = null) },
                    label = { Text("For You") }, colors = colors,
                )
            }
        },
    ) { inner ->
        Box(Modifier.fillMaxSize().padding(inner)) {
            when (tab) {
                0 -> HomeScreen(auth)
                else -> ForYouScreen(auth)
            }
        }
    }
}
