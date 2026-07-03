package net.acumenus.hummingbird.ui.evs

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.launch
import net.acumenus.hummingbird.data.ApiClient
import net.acumenus.hummingbird.data.ApiException
import net.acumenus.hummingbird.data.EvsQueue

class EvsViewModel(app: Application) : AndroidViewModel(app) {
    private val api = ApiClient()

    var queue by mutableStateOf<EvsQueue?>(null); private set
    var loading by mutableStateOf(false); private set
    var error by mutableStateOf<String?>(null); private set
    var needsReauth by mutableStateOf(false); private set
    var workingTurnId by mutableStateOf<Int?>(null); private set

    fun load(bearer: String) {
        loading = true
        viewModelScope.launch {
            try {
                queue = api.evsQueue(bearer)
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

    fun advance(bearer: String, id: Int, status: String) {
        workingTurnId = id
        viewModelScope.launch {
            try {
                api.evsStatus(bearer, id, status)
                queue = api.evsQueue(bearer)
                error = null
            } catch (e: ApiException) {
                if (e.statusCode == 401) needsReauth = true
                error = e.message
            } catch (e: Exception) {
                error = e.message
            }
            workingTurnId = null
        }
    }
}
