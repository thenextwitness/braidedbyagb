package com.braidedbyagb.admin.ui.screens.bookings

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.CalendarMonth
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
import com.braidedbyagb.admin.data.model.BookingsResponse
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.sync.SyncWorker
import com.braidedbyagb.admin.ui.screens.dashboard.BookingListCard
import com.braidedbyagb.admin.ui.theme.Primary
import com.braidedbyagb.admin.ui.theme.Purple800
import com.google.gson.Gson
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

val STATUS_TABS = listOf("all", "pending", "confirmed", "completed", "cancelled")

// Maps tab name → cache key for preloaded data
private fun cacheKeyForTab(tab: String): String? = when (tab) {
    "pending"   -> SyncWorker.KEY_PENDING
    "confirmed" -> SyncWorker.KEY_ALL
    else        -> null
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun BookingsScreen(
    onBookingClick:  (Int) -> Unit,
    onCreateBooking: () -> Unit = {},
    onOpenCalendar:  () -> Unit = {}
) {
    val context   = LocalContext.current
    val scope     = rememberCoroutineScope()
    val gson      = remember { Gson() }
    val dao       = remember { AppDatabase.getInstance(context).cacheDao() }
    val pullState = rememberPullToRefreshState()

    var selectedTab by remember { mutableIntStateOf(0) }
    var bookings    by remember { mutableStateOf<List<BookingSummary>>(emptyList()) }
    var loading     by remember { mutableStateOf(true) }
    var error       by remember { mutableStateOf<String?>(null) }
    var query       by remember { mutableStateOf("") }

    // ── Fetch from API ────────────────────────────────────
    suspend fun fetchFromApi(tab: String, searchQuery: String) {
        val statusFilter = tab.takeIf { it != "all" }
        val res = ApiClient.api.getBookings(
            status = statusFilter,
            q      = searchQuery.takeIf { it.isNotBlank() }
        )
        if (res.isSuccessful && res.body() != null) {
            val body = res.body()!!
            bookings = body.bookings
            error    = null
            // Cache unfiltered tab results (no active search)
            if (searchQuery.isBlank()) {
                cacheKeyForTab(tab)?.let { key ->
                    withContext(Dispatchers.IO) { dao.put(CacheEntry(key, gson.toJson(body))) }
                }
            }
        } else if (bookings.isEmpty()) {
            error = "Failed to load bookings"
        }
    }

    // ── Load: cache first, then live ─────────────────────
    LaunchedEffect(selectedTab, query) {
        val tab = STATUS_TABS[selectedTab]
        error   = null

        // Serve cached data while network loads (only when no search active)
        if (query.isBlank()) {
            cacheKeyForTab(tab)?.let { key ->
                val entry = withContext(Dispatchers.IO) { dao.get(key) }
                if (entry != null) {
                    runCatching { gson.fromJson(entry.json, BookingsResponse::class.java) }
                        .onSuccess { bookings = it.bookings }
                }
            }
        }

        loading = bookings.isEmpty()
        try {
            fetchFromApi(tab, query)
        } catch (_: Exception) {
            if (bookings.isEmpty()) error = "Connection error"
        }
        loading = false
    }

    // ── Pull-to-refresh handler ───────────────────────────
    LaunchedEffect(pullState.isRefreshing) {
        if (pullState.isRefreshing) {
            try { fetchFromApi(STATUS_TABS[selectedTab], query) }
            catch (_: Exception) { if (bookings.isEmpty()) error = "Connection error" }
            pullState.endRefresh()
        }
    }

    // ── UI ────────────────────────────────────────────────
    Box(Modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize()) {

            // Header
            Row(
                Modifier
                    .fillMaxWidth()
                    .padding(start = 16.dp, end = 4.dp, top = 12.dp, bottom = 12.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Text(
                    "Bookings",
                    fontWeight = FontWeight.ExtraBold,
                    fontSize   = 22.sp,
                    color      = Purple800,
                    modifier   = Modifier.weight(1f)
                )
                IconButton(onClick = onOpenCalendar) {
                    Icon(
                        imageVector        = Icons.Default.CalendarMonth,
                        contentDescription = "Calendar",
                        tint               = Purple800
                    )
                }
            }

            // Search
            OutlinedTextField(
                value         = query,
                onValueChange = { query = it },
                placeholder   = { Text("Search name, email, ref…", fontSize = 13.sp) },
                singleLine    = true,
                modifier      = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = 16.dp)
            )

            Spacer(Modifier.height(8.dp))

            // Status tabs
            ScrollableTabRow(selectedTabIndex = selectedTab, edgePadding = 16.dp) {
                STATUS_TABS.forEachIndexed { i, s ->
                    Tab(selected = selectedTab == i, onClick = { selectedTab = i }) {
                        Text(
                            s.replaceFirstChar { it.uppercase() },
                            Modifier.padding(horizontal = 12.dp, vertical = 10.dp),
                            fontSize = 13.sp
                        )
                    }
                }
            }

            // List body
            when {
                loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(color = Primary)
                }

                error != null && bookings.isEmpty() ->
                    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                        Text(error!!, color = MaterialTheme.colorScheme.error)
                    }

                else -> Box(
                    modifier = Modifier
                        .fillMaxSize()
                        .nestedScroll(pullState.nestedScrollConnection)
                ) {
                    if (bookings.isEmpty()) {
                        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                            Text("No bookings found.")
                        }
                    } else {
                        LazyColumn(
                            modifier            = Modifier.fillMaxSize(),
                            contentPadding      = PaddingValues(
                                start  = 16.dp,
                                end    = 16.dp,
                                top    = 16.dp,
                                bottom = 88.dp
                            ),
                            verticalArrangement = Arrangement.spacedBy(8.dp)
                        ) {
                            items(bookings, key = { it.id }) { bk ->
                                BookingListCard(bk, onClick = { onBookingClick(bk.id) })
                            }
                        }
                    }

                    PullToRefreshContainer(
                        state    = pullState,
                        modifier = Modifier.align(Alignment.TopCenter)
                    )
                }
            }
        }

        // FAB — New Booking
        FloatingActionButton(
            onClick        = onCreateBooking,
            modifier       = Modifier
                .align(Alignment.BottomEnd)
                .padding(16.dp),
            containerColor = Primary,
            contentColor   = androidx.compose.ui.graphics.Color.White
        ) {
            Icon(Icons.Default.Add, contentDescription = "New Booking")
        }
    }
}
