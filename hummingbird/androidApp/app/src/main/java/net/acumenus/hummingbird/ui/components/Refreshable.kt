package net.acumenus.hummingbird.ui.components

import androidx.compose.foundation.layout.BoxScope
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier

/**
 * Pull-to-refresh for the main list surfaces — shared wrapper so every screen gets the
 * same gesture (the periodic poll stays; this is the worker's explicit "now" refresh).
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HbRefreshable(
    refreshing: Boolean,
    onRefresh: () -> Unit,
    modifier: Modifier = Modifier,
    content: @Composable BoxScope.() -> Unit,
) {
    PullToRefreshBox(
        isRefreshing = refreshing,
        onRefresh = onRefresh,
        modifier = modifier,
        content = content,
    )
}
