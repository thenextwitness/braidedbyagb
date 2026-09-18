package com.braidedbyagb.admin.ui.screens.payouts

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.braidedbyagb.admin.data.model.OwedStylist
import com.braidedbyagb.admin.data.model.PayoutRecord
import com.braidedbyagb.admin.data.model.PayoutRequest
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.ui.theme.Primary
import com.braidedbyagb.admin.ui.theme.Purple800
import com.braidedbyagb.admin.ui.theme.TextMuted
import com.braidedbyagb.admin.utils.NetworkUtils
import com.braidedbyagb.admin.utils.OfflineBanner
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

private fun money(v: Double) = "£%,.2f".format(v)
private fun today() = SimpleDateFormat("yyyy-MM-dd", Locale.UK).format(Date())

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PayoutsScreen(onBack: () -> Unit) {
    val context   = LocalContext.current
    val scope     = rememberCoroutineScope()
    val isOnline  = remember { NetworkUtils.isOnline(context) }

    var owed     by remember { mutableStateOf<List<OwedStylist>>(emptyList()) }
    var history  by remember { mutableStateOf<List<PayoutRecord>>(emptyList()) }
    var loading  by remember { mutableStateOf(true) }
    var error    by remember { mutableStateOf<String?>(null) }
    var paying   by remember { mutableStateOf<OwedStylist?>(null) }   // stylist whose pay dialog is open
    val snackState = remember { SnackbarHostState() }

    suspend fun load() {
        try {
            val res = ApiClient.api.getPayouts()
            if (res.isSuccessful && res.body() != null) {
                owed = res.body()!!.owed
                history = res.body()!!.history
                error = null
            } else if (owed.isEmpty() && history.isEmpty()) error = "Failed to load payouts"
        } catch (_: Exception) {
            if (owed.isEmpty() && history.isEmpty()) error = "Connection error"
        }
    }

    LaunchedEffect(Unit) { load(); loading = false }

    fun voidPayout(p: PayoutRecord) {
        if (!isOnline) { scope.launch { snackState.showSnackbar("You're offline.") }; return }
        scope.launch {
            try {
                val res = ApiClient.api.voidPayout(p.id)
                if (res.isSuccessful) { snackState.showSnackbar("Payout voided"); load() }
                else snackState.showSnackbar("Void failed")
            } catch (_: Exception) { snackState.showSnackbar("Connection error") }
        }
    }

    val grandOwed = owed.sumOf { it.total }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Payouts", fontWeight = FontWeight.Bold) },
                navigationIcon = { IconButton(onClick = onBack) { Text("‹", fontSize = 26.sp, color = Primary) } }
            )
        },
        snackbarHost = { SnackbarHost(snackState) }
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            if (!isOnline) OfflineBanner()
            when {
                loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Primary) }
                error != null && owed.isEmpty() && history.isEmpty() ->
                    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { Text(error!!, color = MaterialTheme.colorScheme.error) }
                else -> LazyColumn(
                    modifier = Modifier.fillMaxSize(),
                    contentPadding = PaddingValues(16.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp)
                ) {
                    item {
                        Text("Owed now", fontWeight = FontWeight.ExtraBold, fontSize = 20.sp, color = Purple800)
                        Text("${money(grandOwed)} across ${owed.size} stylist${if (owed.size == 1) "" else "s"}",
                            fontSize = 13.sp, color = TextMuted)
                    }
                    if (owed.isEmpty()) item {
                        Text("Nothing owed right now. Earnings appear here once bookings are completed.",
                            fontSize = 13.sp, color = TextMuted)
                    }
                    items(owed, key = { it.stylistId }) { o ->
                        Card(Modifier.fillMaxWidth()) {
                            Row(Modifier.fillMaxWidth().padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text(o.name, fontWeight = FontWeight.Bold, fontSize = 15.sp, color = Purple800)
                                    Text("Commission ${money(o.commission)} • Hourly ${money(o.hourly)} • ${o.count} job${if (o.count == 1) "" else "s"}",
                                        fontSize = 12.sp, color = TextMuted)
                                }
                                Column(horizontalAlignment = Alignment.End) {
                                    Text(money(o.total), fontWeight = FontWeight.Bold, color = Primary)
                                    Spacer(Modifier.height(4.dp))
                                    Button(
                                        onClick = { if (isOnline) paying = o else scope.launch { snackState.showSnackbar("You're offline.") } },
                                        enabled = isOnline,
                                        colors = ButtonDefaults.buttonColors(containerColor = Primary),
                                        contentPadding = PaddingValues(horizontal = 14.dp, vertical = 4.dp)
                                    ) { Text("Pay", fontSize = 12.sp) }
                                }
                            }
                        }
                    }

                    item {
                        Text("Payout history", fontWeight = FontWeight.ExtraBold, fontSize = 20.sp,
                            color = Purple800, modifier = Modifier.padding(top = 12.dp))
                    }
                    if (history.isEmpty()) item { Text("No payouts yet.", fontSize = 13.sp, color = TextMuted) }
                    items(history, key = { it.id }) { p ->
                        Card(Modifier.fillMaxWidth()) {
                            Row(Modifier.fillMaxWidth().padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text("${p.stylistName} — ${money(p.amount)}", fontWeight = FontWeight.Bold, fontSize = 14.sp, color = Purple800)
                                    val method = p.method.replace("_", " ").replaceFirstChar { it.uppercase() }
                                    Text("${p.date} • $method${if (!p.reference.isNullOrBlank()) " • ${p.reference}" else ""}",
                                        fontSize = 12.sp, color = TextMuted)
                                }
                                TextButton(onClick = { voidPayout(p) }, enabled = isOnline) {
                                    Text("Void", color = Color(0xFFDC2626), fontSize = 12.sp)
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    paying?.let { stylist ->
        PayDialog(
            stylist = stylist,
            onDismiss = { paying = null },
            onPaid = { paying = null; scope.launch { load() } },
            onMessage = { m -> scope.launch { snackState.showSnackbar(m) } }
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun PayDialog(
    stylist: OwedStylist,
    onDismiss: () -> Unit,
    onPaid: () -> Unit,
    onMessage: (String) -> Unit
) {
    val scope = rememberCoroutineScope()
    var method by remember { mutableStateOf("bank_transfer") }
    var date   by remember { mutableStateOf(today()) }
    var reference by remember { mutableStateOf("") }
    var adjustment by remember { mutableStateOf("0") }
    var notes by remember { mutableStateOf("") }
    var methodMenu by remember { mutableStateOf(false) }
    var saving by remember { mutableStateOf(false) }

    val methods = mapOf("bank_transfer" to "Bank transfer", "cash" to "Cash", "other" to "Other")
    val adj = adjustment.toDoubleOrNull() ?: 0.0
    val projected = stylist.total + adj

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Pay ${stylist.name}") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Owed: ${money(stylist.total)} • Paying: ${money(projected)}", fontSize = 13.sp, color = TextMuted)
                ExposedDropdownMenuBox(expanded = methodMenu, onExpandedChange = { methodMenu = !methodMenu }) {
                    OutlinedTextField(
                        value = methods[method] ?: "Bank transfer", onValueChange = {}, readOnly = true,
                        label = { Text("Method") },
                        trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = methodMenu) },
                        modifier = Modifier.menuAnchor().fillMaxWidth()
                    )
                    ExposedDropdownMenu(expanded = methodMenu, onDismissRequest = { methodMenu = false }) {
                        methods.forEach { (k, v) -> DropdownMenuItem(text = { Text(v) }, onClick = { method = k; methodMenu = false }) }
                    }
                }
                OutlinedTextField(date, { date = it }, label = { Text("Date (YYYY-MM-DD)") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(reference, { reference = it }, label = { Text("Reference (optional)") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(adjustment, { adjustment = it }, label = { Text("Adjustment ± £") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(notes, { notes = it }, label = { Text("Notes (optional)") }, singleLine = true, modifier = Modifier.fillMaxWidth())
            }
        },
        confirmButton = {
            TextButton(enabled = !saving, onClick = {
                saving = true
                val req = PayoutRequest(
                    stylistId = stylist.stylistId, method = method,
                    reference = reference.trim().ifBlank { null }, adjustment = adj,
                    notes = notes.trim().ifBlank { null }, payoutDate = date.trim()
                )
                scope.launch {
                    try {
                        val res = ApiClient.api.createPayout(req)
                        if (res.isSuccessful && res.body()?.success == true) { onMessage("Payout recorded"); onPaid() }
                        else onMessage(res.body()?.let { "Could not record payout" } ?: "Failed (${res.code()})")
                    } catch (_: Exception) { onMessage("Connection error") }
                    saving = false
                }
            }) { Text(if (saving) "Paying…" else "Record payout") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } }
    )
}
