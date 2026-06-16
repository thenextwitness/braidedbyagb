package com.braidedbyagb.admin.ui.screens.dashboard

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.material3.pulltorefresh.PullToRefreshContainer
import androidx.compose.material3.pulltorefresh.rememberPullToRefreshState
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.input.nestedscroll.nestedScroll
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.braidedbyagb.admin.data.db.AppDatabase
import com.braidedbyagb.admin.data.db.CacheEntry
import com.braidedbyagb.admin.data.model.BookingSummary
import com.braidedbyagb.admin.data.model.DashboardStats
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.sync.SyncWorker
import com.braidedbyagb.admin.ui.theme.Purple800
import com.braidedbyagb.admin.utils.NetworkUtils
import com.braidedbyagb.admin.utils.OfflineBanner
import com.google.gson.Gson
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DashboardScreen(onBookingClick: (Int) -> Unit) {
    val context   = LocalContext.current
    val scope     = rememberCoroutineScope()
    val gson      = remember { Gson() }
    val dao       = remember { AppDatabase.getInstance(context).cacheDao() }
    val pullState = rememberPullToRefreshState()
    val isOnline  = remember { NetworkUtils.isOnline(context) }

    var stats   by remember { mutableStateOf<DashboardStats?>(null) }
    var loading by remember { mutableStateOf(true) }
    var error   by remember { mutableStateOf<String?>(null) }

    // ── Helpers ──────────────────────────────────────────
    suspend fun loadFromCache() {
        val entry = withContext(Dispatchers.IO) { dao.get(SyncWorker.KEY_DASH) }
        if (entry != null) {
            runCatching { gson.fromJson(entry.json, DashboardStats::class.java) }
                .onSuccess { stats = it }
        }
    }

    suspend fun fetchFromApi() {
        try {
            val res = ApiClient.api.getDashboard()
            if (res.isSuccessful && res.body() != null) {
                stats = res.body()
                withContext(Dispatchers.IO) {
                    dao.put(CacheEntry(SyncWorker.KEY_DASH, gson.toJson(stats)))
                }
                error = null
            } else if (stats == null) {
                error = "Failed to load dashboard"
            }
        } catch (e: Exception) {
            if (stats == null) error = "Connection error"
        }
    }

    // ── Initial load — cache first, then live ────────────
    LaunchedEffect(Unit) {
        loadFromCache()
        loading = stats == null  // show spinner only if cache is empty
        fetchFromApi()
        loading = false
    }

    // ── Pull-to-refresh handler ───────────────────────────
    LaunchedEffect(pullState.isRefreshing) {
        if (pullState.isRefreshing) {
            fetchFromApi()
            pullState.endRefresh()
        }
    }

    // ── UI ────────────────────────────────────────────────
    Column(Modifier.fillMaxSize()) {
        if (!isOnline) OfflineBanner()

        if (loading) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                CircularProgressIndicator()
            }
            return@Column
        }

        Box(
            modifier = Modifier
                .fillMaxSize()
                .nestedScroll(pullState.nestedScrollConnection)
        ) {
        LazyColumn(
            modifier            = Modifier
                .fillMaxSize()
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            item {
                Text("Dashboard", fontWeight = FontWeight.ExtraBold, fontSize = 22.sp, color = Purple800)
                Spacer(Modifier.height(4.dp))
            }

            if (error != null && stats == null) {
                item {
                    Text(error!!, color = MaterialTheme.colorScheme.error, fontSize = 14.sp)
                }
            }

            item {
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    StatCard("Pending",  stats?.pendingCount.toString(),            Modifier.weight(1f))
                    StatCard("Upcoming", stats?.upcomingCount.toString(),           Modifier.weight(1f))
                    StatCard("Week £",   "%.2f".format(stats?.weekRevenue ?: 0.0), Modifier.weight(1f))
                }
            }

            item {
                Text(
                    "Today's Appointments",
                    fontWeight = FontWeight.Bold,
                    fontSize   = 16.sp,
                    color      = Purple800
                )
            }

            if (stats?.todayBookings.isNullOrEmpty()) {
                item {
                    Text(
                        "No appointments today.",
                        color    = MaterialTheme.colorScheme.onSurfaceVariant,
                        fontSize = 14.sp
                    )
                }
            } else {
                items(stats!!.todayBookings) { bk ->
                    BookingListCard(bk, onClick = { onBookingClick(bk.id) })
                }
            }
        }

        // Pull-to-refresh indicator at top
        PullToRefreshContainer(
            state    = pullState,
            modifier = Modifier.align(Alignment.TopCenter)
        )
        } // closes inner Box
    } // closes outer Column
}

@Composable
private fun StatCard(label: String, value: String, modifier: Modifier = Modifier) {
    Card(
        modifier = modifier,
        colors   = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer)
    ) {
        Column(Modifier.padding(12.dp)) {
            Text(value, fontWeight = FontWeight.ExtraBold, fontSize = 20.sp, color = Purple800)
            Text(label, fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
fun BookingListCard(bk: BookingSummary, onClick: () -> Unit) {
    Card(onClick = onClick, modifier = Modifier.fillMaxWidth()) {
        Row(Modifier.padding(14.dp), horizontalArrangement = Arrangement.SpaceBetween) {
            Column(Modifier.weight(1f)) {
                Text(bk.clientName, fontWeight = FontWeight.Bold)
                Text(
                    bk.serviceName + (bk.variantName?.let { " — $it" } ?: ""),
                    fontSize = 13.sp,
                    color    = MaterialTheme.colorScheme.onSurfaceVariant
                )
                Text(
                    "${bk.date}  ${bk.time.take(5)}",
                    fontSize = 12.sp,
                    color    = MaterialTheme.colorScheme.onSurfaceVariant
                )
                if (!bk.guestName.isNullOrBlank() || !bk.cartGroupRef.isNullOrBlank()) {
                    Text(
                        buildString {
                            if (!bk.guestName.isNullOrBlank()) append("For: ${bk.guestName}")
                            if (!bk.cartGroupRef.isNullOrBlank()) {
                                if (isNotEmpty()) append("  ")
                                append("👪 Group")
                            }
                        },
                        fontSize   = 12.sp,
                        fontWeight = FontWeight.Medium,
                        color      = Purple800
                    )
                }
            }
            StatusChip(bk.status)
        }
    }
}

@Composable
fun StatusChip(status: String) {
    val (bg, fg) = when (status) {
        "confirmed"  -> Pair(androidx.compose.ui.graphics.Color(0xFFD1FAE5), androidx.compose.ui.graphics.Color(0xFF065F46))
        "pending"    -> Pair(androidx.compose.ui.graphics.Color(0xFFFEF9C3), androidx.compose.ui.graphics.Color(0xFF854D0E))
        "completed"  -> Pair(androidx.compose.ui.graphics.Color(0xFFE0E7FF), Purple800)
        "cancelled"  -> Pair(androidx.compose.ui.graphics.Color(0xFFFEE2E2), androidx.compose.ui.graphics.Color(0xFF991B1B))
        else         -> Pair(MaterialTheme.colorScheme.surfaceVariant, MaterialTheme.colorScheme.onSurfaceVariant)
    }
    Surface(color = bg, shape = MaterialTheme.shapes.small) {
        Text(
            status.replaceFirstChar { it.uppercase() },
            color      = fg,
            fontSize   = 11.sp,
            fontWeight = FontWeight.Bold,
            modifier   = Modifier.padding(horizontal = 8.dp, vertical = 4.dp)
        )
    }
}
