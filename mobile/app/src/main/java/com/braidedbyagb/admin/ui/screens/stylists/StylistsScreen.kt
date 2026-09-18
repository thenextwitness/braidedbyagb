package com.braidedbyagb.admin.ui.screens.stylists

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
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
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.braidedbyagb.admin.data.model.Stylist
import com.braidedbyagb.admin.data.model.StylistRequest
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.ui.theme.Primary
import com.braidedbyagb.admin.ui.theme.Purple800
import com.braidedbyagb.admin.ui.theme.TextMuted
import com.braidedbyagb.admin.utils.NetworkUtils
import com.braidedbyagb.admin.utils.OfflineBanner
import kotlinx.coroutines.launch

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun StylistsScreen(onBack: () -> Unit) {
    val context   = LocalContext.current
    val scope     = rememberCoroutineScope()
    val isOnline  = remember { NetworkUtils.isOnline(context) }

    var stylists  by remember { mutableStateOf<List<Stylist>>(emptyList()) }
    var loading   by remember { mutableStateOf(true) }
    var error     by remember { mutableStateOf<String?>(null) }
    var editing   by remember { mutableStateOf<Stylist?>(null) }   // stylist being edited
    var showForm  by remember { mutableStateOf(false) }            // add or edit dialog open
    var togglingId by remember { mutableStateOf<Int?>(null) }
    val snackState = remember { SnackbarHostState() }

    suspend fun load() {
        try {
            val res = ApiClient.api.getStylists()
            if (res.isSuccessful && res.body() != null) {
                stylists = res.body()!!.stylists
                error = null
            } else if (stylists.isEmpty()) error = "Failed to load stylists"
        } catch (_: Exception) {
            if (stylists.isEmpty()) error = "Connection error"
        }
    }

    LaunchedEffect(Unit) { load(); loading = false }

    fun toggle(s: Stylist) {
        if (!isOnline) { scope.launch { snackState.showSnackbar("You're offline.") }; return }
        if (s.isOwner == 1) { scope.launch { snackState.showSnackbar("The owner can't be deactivated.") }; return }
        scope.launch {
            togglingId = s.id
            try {
                val res = ApiClient.api.toggleStylist(s.id)
                if (res.isSuccessful) {
                    val newActive = res.body()?.isActive ?: (1 - s.isActive)
                    stylists = stylists.map { if (it.id == s.id) it.copy(isActive = newActive) else it }
                } else snackState.showSnackbar("Failed to update")
            } catch (_: Exception) { snackState.showSnackbar("Connection error") }
            togglingId = null
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Stylists", fontWeight = FontWeight.Bold) },
                navigationIcon = {
                    IconButton(onClick = onBack) { Text("‹", fontSize = 26.sp, color = Primary) }
                }
            )
        },
        snackbarHost = { SnackbarHost(snackState) },
        floatingActionButton = {
            FloatingActionButton(
                onClick = { if (isOnline) { editing = null; showForm = true } else scope.launch { snackState.showSnackbar("You're offline.") } },
                containerColor = if (isOnline) Primary else Color(0xFF9CA3AF),
                contentColor = Color.White
            ) { Icon(Icons.Default.Add, "Add stylist") }
        }
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            if (!isOnline) OfflineBanner()
            when {
                loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Primary) }
                error != null && stylists.isEmpty() ->
                    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { Text(error!!, color = MaterialTheme.colorScheme.error) }
                else -> LazyColumn(
                    modifier = Modifier.fillMaxSize(),
                    contentPadding = PaddingValues(16.dp, 16.dp, 16.dp, 88.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp)
                ) {
                    if (stylists.isEmpty()) item { Text("No stylists yet. Tap + to add one.", color = TextMuted, fontSize = 14.sp) }
                    items(stylists, key = { it.id }) { s ->
                        StylistCard(
                            stylist = s,
                            toggling = togglingId == s.id,
                            isOnline = isOnline,
                            onEdit = { if (isOnline) { editing = s; showForm = true } else scope.launch { snackState.showSnackbar("You're offline.") } },
                            onToggle = { toggle(s) }
                        )
                    }
                }
            }
        }
    }

    if (showForm) {
        StylistFormDialog(
            existing = editing,
            onDismiss = { showForm = false },
            onSaved = { showForm = false; scope.launch { load() } },
            onError = { m -> scope.launch { snackState.showSnackbar(m) } }
        )
    }
}

@Composable
private fun StylistCard(
    stylist: Stylist,
    toggling: Boolean,
    isOnline: Boolean,
    onEdit: () -> Unit,
    onToggle: () -> Unit
) {
    val active = stylist.isActive == 1
    Card(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(
            containerColor = if (active) MaterialTheme.colorScheme.surface else MaterialTheme.colorScheme.surfaceVariant
        )
    ) {
        Row(Modifier.fillMaxWidth().padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(stylist.name, fontWeight = FontWeight.Bold, fontSize = 15.sp, color = Purple800)
                    if (stylist.isOwner == 1) StylistBadge(text = "Owner", bg = Color(0xFFF0C030), fg = Color(0xFF2A0020))
                    if (!active) StylistBadge(text = "Inactive", bg = Color(0xFF9CA3AF), fg = Color.White)
                }
                val typeLabel = when (stylist.stylistType) { "barber" -> "Barber"; "both" -> "Braider & Barber"; else -> "Braider" }
                Text(typeLabel, fontSize = 12.sp, color = TextMuted)
                Spacer(Modifier.height(2.dp))
                val rate = if (stylist.defaultHourlyRate > 0) " • £%.2f/hr".format(stylist.defaultHourlyRate) else ""
                Text("%.0f%% commission%s".format(stylist.defaultCommissionPct, rate),
                    fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                IconButton(onClick = onEdit, enabled = isOnline) {
                    Icon(Icons.Default.Edit, "Edit", tint = if (isOnline) Primary else TextMuted, modifier = Modifier.size(20.dp))
                }
                if (stylist.isOwner != 1) {
                    if (toggling) CircularProgressIndicator(Modifier.size(24.dp).padding(4.dp), strokeWidth = 2.dp, color = Primary)
                    else Switch(
                        checked = active, onCheckedChange = { onToggle() }, enabled = isOnline,
                        colors = SwitchDefaults.colors(checkedThumbColor = Primary, checkedTrackColor = Primary.copy(alpha = 0.4f))
                    )
                }
            }
        }
    }
}

@Composable
private fun StylistBadge(text: String, bg: Color, fg: Color) {
    Surface(color = bg, shape = MaterialTheme.shapes.extraSmall) {
        Text(text, fontSize = 10.sp, color = fg, modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp))
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun StylistFormDialog(
    existing: Stylist?,
    onDismiss: () -> Unit,
    onSaved: () -> Unit,
    onError: (String) -> Unit
) {
    val scope = rememberCoroutineScope()
    val isOwner = existing?.isOwner == 1
    var name  by remember { mutableStateOf(existing?.name ?: "") }
    var email by remember { mutableStateOf(existing?.email ?: "") }
    var phone by remember { mutableStateOf(existing?.phone ?: "") }
    var type  by remember { mutableStateOf(existing?.stylistType ?: "braider") }
    var pct   by remember { mutableStateOf(existing?.defaultCommissionPct?.let { "%.2f".format(it) } ?: "50") }
    var rate  by remember { mutableStateOf(existing?.defaultHourlyRate?.let { "%.2f".format(it) } ?: "0") }
    var portal by remember { mutableStateOf((existing?.portalEnabled ?: 1) == 1) }
    var active by remember { mutableStateOf((existing?.isActive ?: 1) == 1) }
    var typeMenu by remember { mutableStateOf(false) }
    var saving by remember { mutableStateOf(false) }

    val typeLabels = mapOf("braider" to "Braider", "barber" to "Barber", "both" to "Braider & Barber")

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(if (existing == null) "Add stylist" else "Edit stylist") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(name, { name = it }, label = { Text("Name") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(email, { email = it }, label = { Text("Email") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(phone, { phone = it }, label = { Text("Phone (optional)") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                ExposedDropdownMenuBox(expanded = typeMenu, onExpandedChange = { typeMenu = !typeMenu }) {
                    OutlinedTextField(
                        value = typeLabels[type] ?: "Braider", onValueChange = {}, readOnly = true,
                        label = { Text("Type") },
                        trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = typeMenu) },
                        modifier = Modifier.menuAnchor().fillMaxWidth()
                    )
                    ExposedDropdownMenu(expanded = typeMenu, onDismissRequest = { typeMenu = false }) {
                        typeLabels.forEach { (k, v) ->
                            DropdownMenuItem(text = { Text(v) }, onClick = { type = k; typeMenu = false })
                        }
                    }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedTextField(pct, { pct = it }, label = { Text("Commission %") }, singleLine = true,
                        keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(keyboardType = KeyboardType.Number),
                        modifier = Modifier.weight(1f))
                    OutlinedTextField(rate, { rate = it }, label = { Text("£/hr") }, singleLine = true,
                        keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(keyboardType = KeyboardType.Number),
                        modifier = Modifier.weight(1f))
                }
                if (!isOwner) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Checkbox(portal, { portal = it }); Text("Portal access (/login)")
                    }
                    if (existing != null) Row(verticalAlignment = Alignment.CenterVertically) {
                        Checkbox(active, { active = it }); Text("Active")
                    }
                }
            }
        },
        confirmButton = {
            TextButton(
                enabled = !saving,
                onClick = {
                    if (name.isBlank() || email.isBlank()) { onError("Name and email are required."); return@TextButton }
                    saving = true
                    val req = StylistRequest(
                        name = name.trim(), email = email.trim(), phone = phone.trim().ifBlank { null },
                        stylistType = type,
                        defaultCommissionPct = pct.toDoubleOrNull() ?: 0.0,
                        defaultHourlyRate = rate.toDoubleOrNull() ?: 0.0,
                        portalEnabled = portal, isActive = active
                    )
                    scope.launch {
                        try {
                            val res = if (existing == null) ApiClient.api.createStylist(req)
                                      else ApiClient.api.updateStylist(existing.id, req)
                            if (res.isSuccessful) onSaved() else onError("Save failed (${res.code()})")
                        } catch (_: Exception) { onError("Connection error") }
                        saving = false
                    }
                }
            ) { Text(if (saving) "Saving…" else "Save") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } }
    )
}
