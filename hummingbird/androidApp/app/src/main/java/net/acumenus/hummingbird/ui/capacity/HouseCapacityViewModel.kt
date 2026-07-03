package net.acumenus.hummingbird.ui.capacity

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.launch
import net.acumenus.hummingbird.data.ApiClient
import net.acumenus.hummingbird.data.ApiException
import net.acumenus.hummingbird.data.HouseRollup
import net.acumenus.hummingbird.data.Placement
import net.acumenus.hummingbird.data.PlacementRecommendations

class HouseCapacityViewModel(app: Application) : AndroidViewModel(app) {
    private val api = ApiClient()

    var house by mutableStateOf<HouseRollup?>(null); private set
    var placements by mutableStateOf<List<Placement>>(emptyList()); private set
    var recommendations by mutableStateOf<PlacementRecommendations?>(null); private set
    var loading by mutableStateOf(false); private set
    var loadingRecommendations by mutableStateOf(false); private set
    var decisionWorking by mutableStateOf(false); private set
    var error by mutableStateOf<String?>(null); private set
    var needsReauth by mutableStateOf(false); private set

    fun load(bearer: String) {
        loading = true
        viewModelScope.launch {
            try {
                house = api.rtdcHouse(bearer)
                placements = api.placements(bearer)
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

    fun loadRecommendations(bearer: String, placementId: Int) {
        loadingRecommendations = true
        recommendations = null
        viewModelScope.launch {
            try {
                recommendations = api.placementRecommendations(bearer, placementId)
                error = null
            } catch (e: ApiException) {
                if (e.statusCode == 401) needsReauth = true
                error = e.message
            } catch (e: Exception) {
                error = e.message
            }
            loadingRecommendations = false
        }
    }

    fun decide(
        bearer: String,
        placementId: Int,
        action: String,
        chosenBedId: Int?,
        onDone: () -> Unit,
    ) {
        decisionWorking = true
        viewModelScope.launch {
            try {
                api.placeBed(bearer, placementId, action, chosenBedId)
                house = api.rtdcHouse(bearer)
                placements = api.placements(bearer)
                recommendations = null
                error = null
                onDone()
            } catch (e: ApiException) {
                if (e.statusCode == 401) needsReauth = true
                error = e.message
            } catch (e: Exception) {
                error = e.message
            }
            decisionWorking = false
        }
    }
}
