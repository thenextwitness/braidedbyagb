package com.braidedbyagb.admin.ui.screens.orders

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.braidedbyagb.admin.data.db.AppDatabase
import com.braidedbyagb.admin.data.db.CacheEntry
import com.braidedbyagb.admin.data.model.Order
import com.braidedbyagb.admin.data.model.OrdersResponse
import com.braidedbyagb.admin.data.model.StatusRequest
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.sync.SyncWorker
import com.braidedbyagb.admin.ui.screens.dashboard.StatusChip
import com.braidedbyagb.admin.ui.theme.Purple800
import com.braidedbyagb.admin.utils.NetworkUtils
import com.braidedbyagb.admin.utils.OfflineBanner
import com.google.gson.Gson
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

private val ORDER_TABS = listOf("all","pending","processing","dispatched","delivered","cancelled")

@Composable
fun OrdersScreen() {
    val context   = LocalContext.current
    val scope     = rememberCoroutineScope()
    val gson      = remember { Gson() }
    val dao       = remember { AppDatabase.getInstance(context).cacheDao() }
    val isOnline  = remember { NetworkUtils.isOnline(context) }

    var selectedTab by remember { mutableIntStateOf(0) }
    var orders      by remember { mutableStateOf<List<Order>>(emptyList()) }
    var loading     by remember { mutableStateOf(true) }
    var error       by remember { mutableStateOf<String?>(null) }

    fun load() { scope.launch {
        error = null
        val status = ORDER_TABS[selectedTab].takeIf { it != "all" }
        // Cache-first for "all" tab
        if (selectedTab == 0) {
            val entry = withContext(Dispatchers.IO) { dao.get(SyncWorker.KEY_ORDERS) }
            if (entry != null) {
                runCatching { gson.fromJson(entry.json, OrdersResponse::class.java) }
                    .onSuccess { orders = it.orders }
            }
        }
        loading = orders.isEmpty()
        // Fetch live
        try {
            val res = ApiClient.api.getOrders(status = status)
            if (res.isSuccessful) {
                orders = res.body()?.orders ?: emptyList()
                if (selectedTab == 0) {
                    withContext(Dispatchers.IO) { dao.put(CacheEntry(SyncWorker.KEY_ORDERS, gson.toJson(res.body()))) }
                }
            } else if (orders.isEmpty()) {
                error = "Failed to load"
            }
        } catch (e: Exception) {
            if (orders.isEmpty()) error = "Connection error"
        }
        loading = false
    }}

    LaunchedEffect(selectedTab) { load() }

    Column(Modifier.fillMaxSize()) {
        if (!isOnline) OfflineBanner()

        Text("Orders", fontWeight = FontWeight.ExtraBold, fontSize = 22.sp, color = Purple800, modifier = Modifier.padding(16.dp))

        ScrollableTabRow(selectedTabIndex = selectedTab, edgePadding = 16.dp) {
            ORDER_TABS.forEachIndexed { i, s ->
                Tab(selected = selectedTab == i, onClick = { selectedTab = i }) {
                    Text(s.replaceFirstChar { it.uppercase() }, Modifier.padding(horizontal = 12.dp, vertical = 10.dp), fontSize = 13.sp)
                }
            }
        }

        when {
            loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            error != null && orders.isEmpty() -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { Text(error!!, color = MaterialTheme.colorScheme.error) }
            orders.isEmpty() -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { Text("No orders found.") }
            else -> LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                items(orders, key = { it.id }) { o ->
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                                Text(o.ref, fontWeight = FontWeight.Bold)
                                StatusChip(o.status)
                            }
                            Text(o.clientName, fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            Text("£%.2f · ${o.deliveryType?.replace("_"," ") ?: "Standard"}".format(o.total), fontSize = 13.sp)
                            if (o.status == "processing") {
                                Button(
                                    onClick = {
                                        scope.launch {
                                            ApiClient.api.updateOrderStatus(o.id, StatusRequest("dispatched"))
                                            load()
                                        }
                                    },
                                    enabled = isOnline
                                ) { Text("Mark Dispatched", fontSize = 12.sp) }
                            }
                        }
                    }
                }
            }
        }
    }
}
