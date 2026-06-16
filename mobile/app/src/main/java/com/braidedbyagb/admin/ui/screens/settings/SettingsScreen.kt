package com.braidedbyagb.admin.ui.screens.settings

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowForward
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
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.sync.SyncWorker
import com.braidedbyagb.admin.ui.theme.Primary
import com.braidedbyagb.admin.ui.theme.Purple800
import com.braidedbyagb.admin.ui.theme.TextMuted
import com.braidedbyagb.admin.utils.NetworkUtils
import com.braidedbyagb.admin.utils.OfflineBanner
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

private val Warning = Color(0xFFD97706)

private data class SettingGroup(val title: String, val keys: List<String>)

private val GROUPS = listOf(
    SettingGroup(
        "General",
        listOf("site_name", "site_email", "site_phone", "site_address", "business_address")
    ),
    SettingGroup(
        "Banking",
        listOf("bank_account_name", "bank_sort_code", "bank_account_number", "bank_transfer_hold_hours")
    ),
    SettingGroup(
        "Booking Policy",
        listOf("deposit_percent", "booking_buffer_hours", "auto_cancel_bank_transfer_hours",
               "cancellation_hours", "late_arrival_mins")
    ),
    SettingGroup(
        "Loyalty Programme",
        listOf("loyalty_enabled", "loyalty_earn_rate", "loyalty_redeem_rate", "loyalty_min_redeem")
    ),
    SettingGroup(
        "Admin Notifications",
        listOf("admin_notify_morning", "admin_notify_evening", "admin_notify_30min")
    )
)

private val TOGGLE_KEYS = setOf(
    "admin_notify_morning", "admin_notify_evening", "admin_notify_30min",
    "review_incentive_enabled", "loyalty_enabled"
)

private fun keyLabel(key: String): String = when (key) {
    "site_name"                        -> "Business Name"
    "site_email"                       -> "Contact Email"
    "site_phone"                       -> "Contact Phone"
    "site_address"                     -> "Studio Location (public)"
    "business_address"                 -> "Full Business Address (paid emails)"
    "bank_account_name"                -> "Account Name"
    "bank_sort_code"                   -> "Sort Code"
    "bank_account_number"              -> "Account Number"
    "bank_transfer_hold_hours"         -> "Hold Hours (bank transfer)"
    "deposit_percent"                  -> "Deposit % Required"
    "booking_buffer_hours"             -> "Booking Buffer (hours)"
    "auto_cancel_bank_transfer_hours"  -> "Auto-cancel After (hours)"
    "cancellation_hours"               -> "Cancellation Policy (hours)"
    "late_arrival_mins"                -> "Late Arrival Grace (mins)"
    "admin_notify_morning"             -> "Morning Daily Brief (7:30 AM)"
    "admin_notify_evening"             -> "Evening Preview (8:00 PM)"
    "admin_notify_30min"               -> "30-min Pre-appointment Alert"
    "loyalty_enabled"                  -> "Enable Loyalty Scheme"
    "loyalty_earn_rate"                -> "Points Earned per £1 Spent"
    "loyalty_redeem_rate"              -> "Points Needed per £1 Off"
    "loyalty_min_redeem"               -> "Minimum Points to Redeem"
    else -> key.replace("_", " ").replaceFirstChar { it.uppercase() }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SettingsScreen(
    onSignOut:         () -> Unit = {},
    onManageServices:  () -> Unit = {},
    onManageReviews:   () -> Unit = {},
    onManageRequests:  () -> Unit = {},
    onManageDiscounts:  () -> Unit = {},
    onManageChat:       () -> Unit = {},
    onOpenAccounting:   () -> Unit = {}
) {
    val context   = LocalContext.current
    val scope     = rememberCoroutineScope()
    val gson      = remember { Gson() }
    val dao       = remember { AppDatabase.getInstance(context).cacheDao() }
    val isOnline  = remember { NetworkUtils.isOnline(context) }

    var settings   by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var editingKey by remember { mutableStateOf<String?>(null) }
    var editValue  by remember { mutableStateOf("") }
    var loading    by remember { mutableStateOf(true) }
    var saving     by remember { mutableStateOf(false) }
    var snack      by remember { mutableStateOf<String?>(null) }
    val snackState = remember { SnackbarHostState() }

    var showSignOutConfirm     by remember { mutableStateOf(false) }
    var showMaintenanceConfirm by remember { mutableStateOf(false) }
    var runningMaintenance     by remember { mutableStateOf(false) }

    fun runMaintenance() {
        scope.launch {
            runningMaintenance = true
            try {
                val res = ApiClient.api.runMaintenance(mapOf("action" to "run_all"))
                if (res.isSuccessful && res.body()?.success == true) {
                    val body = res.body()!!
                    snack = buildString {
                        append("Done! ")
                        if (body.completed > 0)       append("${body.completed} booking(s) marked completed. ")
                        if (body.addonsConverted > 0) append("${body.addonsConverted} add-on(s) made global.")
                        if (body.completed == 0 && body.addonsConverted == 0) append("Everything already up to date.")
                    }
                } else {
                    snack = "Maintenance failed"
                }
            } catch (_: Exception) { snack = "Connection error" }
            runningMaintenance = false
        }
    }

    LaunchedEffect(snack) {
        snack?.let { snackState.showSnackbar(it); snack = null }
    }

    LaunchedEffect(Unit) {
        // Cache-first
        val entry = withContext(Dispatchers.IO) { dao.get(SyncWorker.KEY_SETTINGS) }
        if (entry != null) {
            val type = object : TypeToken<Map<String, String>>() {}.type
            runCatching { gson.fromJson<Map<String, String>>(entry.json, type) }
                .onSuccess { settings = it }
        }
        loading = settings.isEmpty()
        // Fetch live
        try {
            val res = ApiClient.api.getSettings()
            if (res.isSuccessful && res.body() != null) {
                settings = res.body()!!
                withContext(Dispatchers.IO) { dao.put(CacheEntry(SyncWorker.KEY_SETTINGS, gson.toJson(settings))) }
            }
        } catch (_: Exception) {}
        loading = false
    }

    fun saveKey(key: String, value: String) {
        scope.launch {
            saving = true
            try {
                val res = ApiClient.api.updateSetting(mapOf("key" to key, "value" to value))
                if (res.isSuccessful) {
                    settings   = settings.toMutableMap().also { it[key] = value }
                    editingKey = null
                    snack      = "Saved"
                    // Update cache
                    withContext(Dispatchers.IO) { dao.put(CacheEntry(SyncWorker.KEY_SETTINGS, gson.toJson(settings))) }
                } else {
                    snack = "Failed to save"
                }
            } catch (_: Exception) { snack = "Connection error" }
            saving = false
        }
    }

    // Maintenance confirmation
    if (showMaintenanceConfirm) {
        AlertDialog(
            onDismissRequest = { showMaintenanceConfirm = false },
            title = { Text("Apply to All Past Bookings?") },
            text  = { Text("This will:\n\n• Mark all past confirmed/pending bookings as Completed\n• Make all existing add-ons apply globally to every service\n\nThis cannot be undone.") },
            confirmButton = {
                Button(
                    onClick = { showMaintenanceConfirm = false; runMaintenance() },
                    colors  = ButtonDefaults.buttonColors(containerColor = Warning)
                ) { Text("Apply Now") }
            },
            dismissButton = { TextButton(onClick = { showMaintenanceConfirm = false }) { Text("Cancel") } }
        )
    }

    // Sign-out confirmation
    if (showSignOutConfirm) {
        AlertDialog(
            onDismissRequest = { showSignOutConfirm = false },
            title = { Text("Sign Out") },
            text  = { Text("Are you sure you want to sign out?") },
            confirmButton = {
                TextButton(onClick = {
                    ApiClient.clearToken()
                    showSignOutConfirm = false
                    onSignOut()
                }) { Text("Sign Out", color = Color(0xFFDC2626)) }
            },
            dismissButton = { TextButton(onClick = { showSignOutConfirm = false }) { Text("Cancel") } }
        )
    }

    Scaffold(snackbarHost = { SnackbarHost(snackState) }) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            if (!isOnline) OfflineBanner()

            if (loading) {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(color = Primary)
                }
            } else {

            LazyColumn(
                modifier       = Modifier.fillMaxSize(),
                contentPadding = PaddingValues(16.dp),
                verticalArrangement = Arrangement.spacedBy(16.dp)
            ) {
                item {
                    Text("Settings", fontWeight = FontWeight.ExtraBold, fontSize = 22.sp, color = Purple800)
                }

                // ── Setting groups ────────────────────────────────────────
                GROUPS.forEach { group ->
                    val visibleKeys = group.keys.filter { settings.containsKey(it) }
                    if (visibleKeys.isEmpty()) return@forEach

                    item {
                        Text(group.title, fontWeight = FontWeight.Bold, fontSize = 14.sp,
                             color = Primary, modifier = Modifier.padding(top = 4.dp))
                    }

                    item {
                        Card(Modifier.fillMaxWidth()) {
                            Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                visibleKeys.forEachIndexed { index, key ->
                                    val value = settings[key] ?: ""
                                    val label = keyLabel(key)

                                    if (index > 0) HorizontalDivider(Modifier.padding(vertical = 6.dp))

                                    if (key in TOGGLE_KEYS) {
                                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                                            Column(Modifier.weight(1f)) {
                                                Text(label, fontWeight = FontWeight.Medium, fontSize = 13.sp)
                                            }
                                            Switch(
                                                checked         = value == "1",
                                                onCheckedChange = { if (isOnline) saveKey(key, if (it) "1" else "0") },
                                                enabled         = !saving && isOnline,
                                                colors          = SwitchDefaults.colors(checkedThumbColor = Primary, checkedTrackColor = Primary.copy(alpha = 0.4f))
                                            )
                                        }
                                    } else {
                                        Column(Modifier.fillMaxWidth()) {
                                            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                                                Column(Modifier.weight(1f)) {
                                                    Text(label, fontWeight = FontWeight.Medium, fontSize = 13.sp)
                                                    if (editingKey != key) {
                                                        Text(
                                                            value.takeIf { it.isNotBlank() } ?: "—",
                                                            fontSize = 13.sp,
                                                            color    = if (value.isBlank()) TextMuted else MaterialTheme.colorScheme.onSurfaceVariant
                                                        )
                                                    }
                                                }
                                                if (editingKey != key && isOnline) {
                                                    TextButton(onClick = { editingKey = key; editValue = value }) {
                                                        Text("Edit", fontSize = 12.sp, color = Primary)
                                                    }
                                                }
                                            }
                                            if (editingKey == key) {
                                                OutlinedTextField(
                                                    value         = editValue,
                                                    onValueChange = { editValue = it },
                                                    singleLine    = true,
                                                    modifier      = Modifier.fillMaxWidth().padding(top = 4.dp)
                                                )
                                                Row(
                                                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                                                    modifier = Modifier.padding(top = 6.dp)
                                                ) {
                                                    Button(
                                                        onClick  = { saveKey(key, editValue) },
                                                        enabled  = !saving && isOnline,
                                                        colors   = ButtonDefaults.buttonColors(containerColor = Primary)
                                                    ) {
                                                        if (saving) CircularProgressIndicator(Modifier.size(14.dp), color = Color.White, strokeWidth = 2.dp)
                                                        else Text("Save")
                                                    }
                                                    OutlinedButton(onClick = { editingKey = null }) { Text("Cancel") }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                // ── Management shortcuts ──────────────────────────────────
                item {
                    Text("Management", fontWeight = FontWeight.Bold, fontSize = 14.sp,
                         color = Primary, modifier = Modifier.padding(top = 4.dp))
                }
                item {
                    Card(onClick = onOpenAccounting, modifier = Modifier.fillMaxWidth()) {
                        Row(
                            Modifier.padding(14.dp).fillMaxWidth(),
                            verticalAlignment     = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.SpaceBetween
                        ) {
                            Column {
                                Text("Finance", fontWeight = FontWeight.Medium, fontSize = 13.sp)
                                Text("Log expenses, pay yourself, view profit summary", fontSize = 12.sp, color = TextMuted)
                            }
                            Icon(Icons.AutoMirrored.Filled.ArrowForward, null, tint = TextMuted, modifier = Modifier.size(16.dp))
                        }
                    }
                }
                item {
                    Card(onClick = onManageServices, modifier = Modifier.fillMaxWidth()) {
                        Row(
                            Modifier.padding(14.dp).fillMaxWidth(),
                            verticalAlignment     = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.SpaceBetween
                        ) {
                            Column {
                                Text("Manage Services", fontWeight = FontWeight.Medium, fontSize = 13.sp)
                                Text("View, create and edit services, variants & add-ons", fontSize = 12.sp, color = TextMuted)
                            }
                            Icon(Icons.AutoMirrored.Filled.ArrowForward, null, tint = TextMuted, modifier = Modifier.size(16.dp))
                        }
                    }
                }

                item {
                    Card(onClick = onManageReviews, modifier = Modifier.fillMaxWidth()) {
                        Row(
                            Modifier.padding(14.dp).fillMaxWidth(),
                            verticalAlignment     = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.SpaceBetween
                        ) {
                            Column {
                                Text("Manage Reviews", fontWeight = FontWeight.Medium, fontSize = 13.sp)
                                Text("Approve or reject customer reviews", fontSize = 12.sp, color = TextMuted)
                            }
                            Icon(Icons.AutoMirrored.Filled.ArrowForward, null, tint = TextMuted, modifier = Modifier.size(16.dp))
                        }
                    }
                }
                item {
                    Card(onClick = onManageRequests, modifier = Modifier.fillMaxWidth()) {
                        Row(
                            Modifier.padding(14.dp).fillMaxWidth(),
                            verticalAlignment     = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.SpaceBetween
                        ) {
                            Column {
                                Text("Custom Requests", fontWeight = FontWeight.Medium, fontSize = 13.sp)
                                Text("View and reply to style consultation requests", fontSize = 12.sp, color = TextMuted)
                            }
                            Icon(Icons.AutoMirrored.Filled.ArrowForward, null, tint = TextMuted, modifier = Modifier.size(16.dp))
                        }
                    }
                }
                item {
                    Card(onClick = onManageDiscounts, modifier = Modifier.fillMaxWidth()) {
                        Row(
                            Modifier.padding(14.dp).fillMaxWidth(),
                            verticalAlignment     = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.SpaceBetween
                        ) {
                            Column {
                                Text("Discount Codes", fontWeight = FontWeight.Medium, fontSize = 13.sp)
                                Text("Create and manage promotional codes", fontSize = 12.sp, color = TextMuted)
                            }
                            Icon(Icons.AutoMirrored.Filled.ArrowForward, null, tint = TextMuted, modifier = Modifier.size(16.dp))
                        }
                    }
                }
                item {
                    Card(onClick = onManageChat, modifier = Modifier.fillMaxWidth()) {
                        Row(
                            Modifier.padding(14.dp).fillMaxWidth(),
                            verticalAlignment     = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.SpaceBetween
                        ) {
                            Column {
                                Text("Live Chat", fontWeight = FontWeight.Medium, fontSize = 13.sp)
                                Text("View and respond to website visitor conversations", fontSize = 12.sp, color = TextMuted)
                            }
                            Icon(Icons.AutoMirrored.Filled.ArrowForward, null, tint = TextMuted, modifier = Modifier.size(16.dp))
                        }
                    }
                }

                // ── Data Maintenance ─────────────────────────────────────
                item {
                    Text("Data", fontWeight = FontWeight.Bold, fontSize = 14.sp,
                         color = Primary, modifier = Modifier.padding(top = 4.dp))
                }
                item {
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(14.dp)) {
                            Text("Apply to All Past Bookings", fontWeight = FontWeight.Medium, fontSize = 13.sp)
                            Text(
                                "Mark past bookings as completed and make all add-ons global. Safe to run multiple times.",
                                fontSize = 12.sp, color = TextMuted
                            )
                            Spacer(Modifier.height(10.dp))
                            Button(
                                onClick  = { showMaintenanceConfirm = true },
                                enabled  = !runningMaintenance && isOnline,
                                colors   = ButtonDefaults.buttonColors(containerColor = Warning),
                                modifier = Modifier.fillMaxWidth()
                            ) {
                                if (runningMaintenance) {
                                    CircularProgressIndicator(Modifier.size(16.dp), color = Color.White, strokeWidth = 2.dp)
                                    Spacer(Modifier.width(8.dp))
                                    Text("Running…")
                                } else {
                                    Text(if (isOnline) "Run Now" else "Offline")
                                }
                            }
                        }
                    }
                }

                // ── Account / Sign Out ────────────────────────────────────
                item {
                    Text("Account", fontWeight = FontWeight.Bold, fontSize = 14.sp,
                         color = Primary, modifier = Modifier.padding(top = 4.dp))
                }
                item {
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(14.dp)) {
                            Text("Admin Account", fontWeight = FontWeight.Medium, fontSize = 13.sp)
                            Text(ApiClient.getAdminEmail() ?: "admin@braidedbyagb.co.uk", fontSize = 13.sp, color = TextMuted)
                            Spacer(Modifier.height(12.dp))
                            Button(
                                onClick  = { showSignOutConfirm = true },
                                colors   = ButtonDefaults.buttonColors(containerColor = Color(0xFFDC2626)),
                                modifier = Modifier.fillMaxWidth()
                            ) { Text("Sign Out") }
                        }
                    }
                }
            }
            } // end else (not loading)
        }
    }
}
