package com.braidedbyagb.admin.ui.screens.services

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.braidedbyagb.admin.data.db.AppDatabase
import com.braidedbyagb.admin.data.db.CacheEntry
import com.braidedbyagb.admin.data.model.ServiceFull
import com.braidedbyagb.admin.data.model.ServicesFullResponse
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.sync.SyncWorker
import com.braidedbyagb.admin.ui.theme.Primary
import com.braidedbyagb.admin.ui.theme.Purple800
import com.braidedbyagb.admin.ui.theme.TextMuted
import com.braidedbyagb.admin.utils.NetworkUtils
import com.braidedbyagb.admin.utils.OfflineBanner
import com.braidedbyagb.admin.utils.formatDuration
import com.google.gson.Gson
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ServicesScreen(
    onCreateService: () -> Unit,
    onEditService:   (Int) -> Unit
) {
    val context   = LocalContext.current
    val scope     = rememberCoroutineScope()
    val gson      = remember { Gson() }
    val dao       = remember { AppDatabase.getInstance(context).cacheDao() }
    val isOnline  = remember { NetworkUtils.isOnline(context) }

    var services   by remember { mutableStateOf<List<ServiceFull>>(emptyList()) }
    var loading    by remember { mutableStateOf(true) }
    var error      by remember { mutableStateOf<String?>(null) }
    var togglingId by remember { mutableStateOf<Int?>(null) }
    val snackState = remember { SnackbarHostState() }

    // ── Load (cache-first) ────────────────────────────────
    suspend fun load() {
        // Cache-first
        val entry = withContext(Dispatchers.IO) { dao.get(SyncWorker.KEY_SERVICES) }
        if (entry != null) {
            runCatching { gson.fromJson(entry.json, ServicesFullResponse::class.java) }
                .onSuccess { services = it.services }
        }
        // Fetch live
        try {
            val res = ApiClient.api.getServicesAdmin()
            if (res.isSuccessful && res.body() != null) {
                services = res.body()!!.services
                withContext(Dispatchers.IO) { dao.put(CacheEntry(SyncWorker.KEY_SERVICES, gson.toJson(res.body()))) }
                error = null
            } else if (services.isEmpty()) {
                error = "Failed to load services"
            }
        } catch (_: Exception) {
            if (services.isEmpty()) error = "Connection error"
        }
    }

    LaunchedEffect(Unit) {
        load()
        loading = false
    }

    // ── Toggle active ─────────────────────────────────────
    fun toggleActive(service: ServiceFull) {
        if (!isOnline) {
            scope.launch { snackState.showSnackbar("You're offline. Connect to perform this action.") }
            return
        }
        scope.launch {
            togglingId = service.id
            try {
                val res = ApiClient.api.toggleService(service.id)
                if (res.isSuccessful) {
                    val newActive = res.body()?.isActive ?: (1 - service.isActive)
                    services = services.map {
                        if (it.id == service.id) it.copy(isActive = newActive) else it
                    }
                } else {
                    snackState.showSnackbar("Failed to update")
                }
            } catch (_: Exception) {
                snackState.showSnackbar("Connection error")
            }
            togglingId = null
        }
    }

    // ── UI ────────────────────────────────────────────────
    Scaffold(
        snackbarHost = { SnackbarHost(snackState) },
        floatingActionButton = {
            FloatingActionButton(
                onClick        = { if (isOnline) onCreateService() else scope.launch { snackState.showSnackbar("You're offline. Connect to create services.") } },
                containerColor = if (isOnline) Primary else Color(0xFF9CA3AF),
                contentColor   = Color.White
            ) { Icon(Icons.Default.Add, "New Service") }
        }
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            if (!isOnline) OfflineBanner()

            when {
                loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(color = Primary)
                }
                error != null && services.isEmpty() ->
                    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                        Text(error!!, color = MaterialTheme.colorScheme.error)
                    }
                else -> LazyColumn(
                    modifier       = Modifier.fillMaxSize(),
                    contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 16.dp, bottom = 88.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp)
                ) {
                    item {
                        Text("Services", fontWeight = FontWeight.ExtraBold, fontSize = 22.sp, color = Purple800)
                        Spacer(Modifier.height(4.dp))
                    }

                    if (services.isEmpty()) {
                        item {
                            Text("No services yet. Tap + to create one.", color = TextMuted, fontSize = 14.sp)
                        }
                    }

                    val sorted = services.sortedWith(compareByDescending<ServiceFull> { it.isActive }.thenBy { it.displayOrder })

                    items(sorted, key = { it.id }) { svc ->
                        ServiceCard(
                            service    = svc,
                            toggling   = togglingId == svc.id,
                            isOnline   = isOnline,
                            onEdit     = { if (isOnline) onEditService(svc.id) else scope.launch { snackState.showSnackbar("You're offline. Connect to edit services.") } },
                            onToggle   = { toggleActive(svc) }
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun ServiceCard(
    service:  ServiceFull,
    toggling: Boolean,
    isOnline: Boolean,
    onEdit:   () -> Unit,
    onToggle: () -> Unit
) {
    val isActive = service.isActive == 1
    Card(
        modifier = Modifier.fillMaxWidth(),
        colors   = CardDefaults.cardColors(
            containerColor = if (isActive) MaterialTheme.colorScheme.surface
                            else MaterialTheme.colorScheme.surfaceVariant
        )
    ) {
        Column(Modifier.padding(14.dp)) {
            Row(
                Modifier.fillMaxWidth(),
                verticalAlignment       = Alignment.CenterVertically,
                horizontalArrangement   = Arrangement.SpaceBetween
            ) {
                Column(Modifier.weight(1f)) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text(service.name, fontWeight = FontWeight.Bold, fontSize = 15.sp, color = Purple800)
                        if (!isActive) {
                            Surface(color = MaterialTheme.colorScheme.errorContainer, shape = MaterialTheme.shapes.extraSmall) {
                                Text("Inactive", fontSize = 10.sp, color = MaterialTheme.colorScheme.onErrorContainer,
                                     modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp))
                            }
                        }
                    }
                    if (!service.category.isNullOrBlank()) {
                        Text(service.category, fontSize = 12.sp, color = TextMuted)
                    }
                    Spacer(Modifier.height(2.dp))
                    Text(
                        "£%.2f • %s".format(service.priceFrom, formatDuration(service.durationMins)),
                        fontSize = 13.sp,
                        color    = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }

                Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    IconButton(onClick = onEdit, enabled = isOnline) {
                        Icon(Icons.Default.Edit, "Edit", tint = if (isOnline) Primary else TextMuted, modifier = Modifier.size(20.dp))
                    }
                    if (toggling) {
                        CircularProgressIndicator(Modifier.size(24.dp).padding(4.dp), strokeWidth = 2.dp, color = Primary)
                    } else {
                        Switch(
                            checked         = isActive,
                            onCheckedChange = { onToggle() },
                            enabled         = isOnline,
                            colors          = SwitchDefaults.colors(
                                checkedThumbColor  = Primary,
                                checkedTrackColor  = Primary.copy(alpha = 0.4f)
                            )
                        )
                    }
                }
            }

            // Variants
            if (service.variants.isNotEmpty()) {
                Spacer(Modifier.height(6.dp))
                HorizontalDivider()
                Spacer(Modifier.height(6.dp))
                Text("Variants", fontSize = 11.sp, color = TextMuted, fontWeight = FontWeight.Medium)
                service.variants.forEach { v ->
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Text(v.variantName, fontSize = 12.sp)
                        Text("£%.2f".format(v.price), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }

            // Add-ons
            if (service.addons.isNotEmpty()) {
                Spacer(Modifier.height(6.dp))
                if (service.variants.isEmpty()) {
                    HorizontalDivider()
                    Spacer(Modifier.height(6.dp))
                }
                Text("Add-ons", fontSize = 11.sp, color = TextMuted, fontWeight = FontWeight.Medium)
                service.addons.forEach { a ->
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Text(a.name, fontSize = 12.sp, color = if (a.isActive == 1) MaterialTheme.colorScheme.onSurface else TextMuted)
                        Text("£%.2f".format(a.price), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
        }
    }
}
