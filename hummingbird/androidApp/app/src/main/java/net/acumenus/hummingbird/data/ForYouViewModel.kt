package net.acumenus.hummingbird.data

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.launch

class ForYouViewModel(app: Application) : AndroidViewModel(app) {
    private val api = ApiClient()

    var items by mutableStateOf<List<ForYouItem>>(emptyList()); private set
    var loading by mutableStateOf(false); private set
    var error by mutableStateOf<String?>(null); private set
    var needsReauth by mutableStateOf(false); private set

    fun load(bearer: String) {
        loading = true
        viewModelScope.launch {
            try {
                items = api.forYou(bearer); error = null
            } catch (e: ApiException) {
                if (e.statusCode == 401) needsReauth = true
                error = e.message
            } catch (e: Exception) {
                error = e.message
            }
            loading = false
        }
    }
}
