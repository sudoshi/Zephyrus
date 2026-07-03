package net.acumenus.hummingbird.ui.improvement

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.launch
import net.acumenus.hummingbird.data.ApiClient
import net.acumenus.hummingbird.data.ApiException
import net.acumenus.hummingbird.data.Opportunity
import net.acumenus.hummingbird.data.PdsaCycle

class ImprovementViewModel(app: Application) : AndroidViewModel(app) {
    private val api = ApiClient()

    var cycles by mutableStateOf<List<PdsaCycle>>(emptyList()); private set
    var opportunities by mutableStateOf<List<Opportunity>>(emptyList()); private set
    var loading by mutableStateOf(false); private set
    var loaded by mutableStateOf(false); private set
    var error by mutableStateOf<String?>(null); private set
    var needsReauth by mutableStateOf(false); private set

    fun load(bearer: String) {
        loading = true
        viewModelScope.launch {
            try {
                cycles = api.improvementPdsa(bearer)
                opportunities = api.improvementOpportunities(bearer)
                loaded = true
                error = null
            } catch (e: ApiException) {
                if (e.statusCode == 401) needsReauth = true
                error = e.message
            } catch (e: Exception) {
                error = e.message
            }
            loading = false
        }
    }

    fun activeCycles(): List<PdsaCycle> = cycles.filter { it.status == "active" }
}
