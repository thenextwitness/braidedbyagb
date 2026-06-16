package com.braidedbyagb.admin.ui.screens.customers

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavController
import com.braidedbyagb.admin.data.db.AppDatabase
import com.braidedbyagb.admin.data.db.CacheEntry
import com.braidedbyagb.admin.data.model.*
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.utils.NetworkUtils
import com.braidedbyagb.admin.utils.OfflineBanner
import com.google.gson.Gson
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CustomerDetailScreen(customerId: Int, navController: NavController) {
    val context   = LocalContext.current
    val scope     = rememberCoroutineScope()
    val gson      = remember { Gson() }
    val dao       = remember { AppDatabase.getInstance(context).cacheDao() }
    val isOnline  = remember { NetworkUtils.isOnline(context) }

    var customer  by remember { mutableStateOf<Customer?>(null) }
    var isLoading by remember { mutableStateOf(true) }
    var error     by remember { mutableStateOf<String?>(null) }

    // Dialog state
    var showNoteDialog   by remember { mutableStateOf(false) }
    var showPointsDialog by remember { mutableStateOf(false) }
    var showBlockDialog  by remember { mutableStateOf(false) }
    var showDeleteDialog by remember { mutableStateOf(false) }
    var deleting         by remember { mutableStateOf(false) }
    var deleteError      by remember { mutableStateOf<String?>(null) }

    val cacheKey = "customer_$customerId"

    fun refresh() { scope.launch {
        // Cache-first
        val entry = withContext(Dispatchers.IO) { dao.get(cacheKey) }
        if (entry != null) {
            runCatching { gson.fromJson(entry.json, Customer::class.java) }
                .onSuccess { customer = it }
        }
        isLoading = customer == null
        error = null
        // Fetch live
        try {
            val res = ApiClient.api.getCustomer(customerId)
            if (res.isSuccessful && res.body() != null) {
                customer = res.body()
                withContext(Dispatchers.IO) { dao.put(CacheEntry(cacheKey, gson.toJson(customer))) }
            } else if (customer == null) {
                error = "Failed to load customer"
            }
        } catch (e: Exception) {
            if (customer == null) error = e.message
        }
        isLoading = false
    }}

    LaunchedEffect(customerId) { refresh() }

    // ── Add note dialog ───────────────────────────────────
    if (showNoteDialog) {
        var noteText by remember { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { showNoteDialog = false },
            title = { Text("Add Note") },
            text = {
                OutlinedTextField(
                    value = noteText, onValueChange = { noteText = it },
                    label = { Text("Note") }, modifier = Modifier.fillMaxWidth()
                )
            },
            confirmButton = {
                TextButton(onClick = {
                    if (noteText.isNotBlank()) {
                        scope.launch {
                            ApiClient.api.addCustomerNote(customerId, NoteRequest(noteText.trim()))
                            showNoteDialog = false; refresh()
                        }
                    }
                }) { Text("Add") }
            },
            dismissButton = { TextButton(onClick = { showNoteDialog = false }) { Text("Cancel") } }
        )
    }

    // ── Adjust points dialog ──────────────────────────────
    if (showPointsDialog) {
        var ptsText by remember { mutableStateOf("") }
        var ptsDesc by remember { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { showPointsDialog = false },
            title = { Text("Adjust Loyalty Points") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedTextField(value = ptsText, onValueChange = { ptsText = it },
                        label = { Text("Points (+ add / − remove)") }, modifier = Modifier.fillMaxWidth())
                    OutlinedTextField(value = ptsDesc, onValueChange = { ptsDesc = it },
                        label = { Text("Reason (optional)") }, modifier = Modifier.fillMaxWidth())
                }
            },
            confirmButton = {
                TextButton(onClick = {
                    val pts = ptsText.toIntOrNull() ?: return@TextButton
                    scope.launch {
                        ApiClient.api.adjustLoyalty(customerId, LoyaltyRequest(pts, ptsDesc.ifBlank { null }))
                        showPointsDialog = false; refresh()
                    }
                }) { Text("Apply") }
            },
            dismissButton = { TextButton(onClick = { showPointsDialog = false }) { Text("Cancel") } }
        )
    }

    // ── Delete dialog ─────────────────────────────────────
    if (showDeleteDialog) {
        val name = customer?.name ?: "this client"
        AlertDialog(
            onDismissRequest = { if (!deleting) { showDeleteDialog = false; deleteError = null } },
            title = { Text("Delete $name?") },
            text  = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(
                        "This will permanently delete all their bookings, orders, loyalty history and notes. This cannot be undone.",
                        fontSize = 13.sp
                    )
                    if (deleteError != null) {
                        Text(deleteError!!, color = Color(0xFFDC2626), fontSize = 12.sp)
                    }
                }
            },
            confirmButton = {
                Button(
                    onClick = {
                        scope.launch {
                            deleting = true
                            deleteError = null
                            try {
                                val res = ApiClient.api.deleteCustomer(customerId)
                                when {
                                    res.isSuccessful -> {
                                        showDeleteDialog = false
                                        navController.popBackStack()
                                    }
                                    res.code() == 409 -> deleteError = res.errorBody()?.string()
                                        ?.let { Gson().fromJson(it, Map::class.java)["error"] as? String }
                                        ?: "Client has active bookings — cancel them first."
                                    else -> deleteError = "Failed to delete. Please try again."
                                }
                            } catch (_: Exception) {
                                deleteError = "Connection error. Please try again."
                            }
                            deleting = false
                        }
                    },
                    enabled  = !deleting,
                    colors   = ButtonDefaults.buttonColors(containerColor = Color(0xFF7F1D1D))
                ) {
                    if (deleting) CircularProgressIndicator(Modifier.size(14.dp), color = Color.White, strokeWidth = 2.dp)
                    else Text("Delete Permanently")
                }
            },
            dismissButton = {
                TextButton(onClick = { if (!deleting) { showDeleteDialog = false; deleteError = null } }) {
                    Text("Cancel")
                }
            }
        )
    }

    // ── Block/Unblock dialog ──────────────────────────────
    if (showBlockDialog) {
        val isBlocked = customer?.isBlocked == 1
        var reasonText by remember { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { showBlockDialog = false },
            title = { Text(if (isBlocked) "Unblock Client" else "Block Client") },
            text = {
                if (!isBlocked) {
                    OutlinedTextField(value = reasonText, onValueChange = { reasonText = it },
                        label = { Text("Reason (internal only)") }, modifier = Modifier.fillMaxWidth())
                } else {
                    Text("Remove this client's block and restore their booking access?")
                }
            },
            confirmButton = {
                TextButton(onClick = {
                    scope.launch {
                        if (isBlocked) {
                            ApiClient.api.blockCustomer(customerId, BlockRequest2(false))
                        } else {
                            ApiClient.api.blockCustomer(customerId, BlockRequest2(true, reasonText.ifBlank { null }))
                        }
                        showBlockDialog = false; refresh()
                    }
                }) { Text(if (isBlocked) "Unblock" else "Block", color = if (isBlocked) Color(0xFF16A34A) else Color(0xFFDC2626)) }
            },
            dismissButton = { TextButton(onClick = { showBlockDialog = false }) { Text("Cancel") } }
        )
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(customer?.name ?: "Customer", fontWeight = FontWeight.ExtraBold) },
                navigationIcon = {
                    IconButton(onClick = { navController.popBackStack() }) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                }
            )
        }
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            if (!isOnline) OfflineBanner()

            when {
                isLoading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(color = Color(0xFFCC1A8A))
                }
                error != null && customer == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Text(error ?: "Error", color = Color(0xFFDC2626))
                }
                customer == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Text("Customer not found", color = Color(0xFF7A4A70))
                }
                else -> {
                    val c = customer!!
                    LazyColumn(
                        modifier = Modifier.fillMaxSize(),
                        contentPadding = PaddingValues(16.dp),
                        verticalArrangement = Arrangement.spacedBy(12.dp)
                    ) {
                        // Blocked banner
                        if (c.isBlocked == 1) {
                            item {
                                Card(
                                    colors = CardDefaults.cardColors(containerColor = Color(0xFFFEF2F2)),
                                    shape = RoundedCornerShape(8.dp)
                                ) {
                                    Row(Modifier.padding(12.dp), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                                        Column(Modifier.weight(1f)) {
                                            Text("⚠ Client is blocked", fontWeight = FontWeight.Bold, color = Color(0xFFDC2626), fontSize = 14.sp)
                                            if (!c.blockReason.isNullOrBlank()) Text(c.blockReason, color = Color(0xFF9F1239), fontSize = 12.sp)
                                        }
                                        TextButton(onClick = { if (isOnline) showBlockDialog = true }, enabled = isOnline) {
                                            Text("Unblock", color = Color(0xFFDC2626))
                                        }
                                    }
                                }
                            }
                        }

                        // Identity
                        item {
                            Card(modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(12.dp)) {
                                Column(Modifier.padding(16.dp)) {
                                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                                        Box(
                                            Modifier.size(52.dp).clip(CircleShape).background(Color(0xFFCC1A8A)),
                                            contentAlignment = Alignment.Center
                                        ) {
                                            Text(c.name.firstOrNull()?.uppercaseChar()?.toString() ?: "?", color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 22.sp)
                                        }
                                        Column {
                                            Text(c.name, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
                                            Text("Client since ${c.createdAt.take(7)}", color = Color(0xFF7A4A70), fontSize = 12.sp)
                                        }
                                    }
                                    Spacer(Modifier.height(12.dp))
                                    Text("📧 ${c.email}", fontSize = 13.sp)
                                    if (!c.phone.isNullOrBlank()) Text("📱 ${c.phone}", fontSize = 13.sp)
                                    if (!c.tags.isNullOrBlank()) {
                                        Spacer(Modifier.height(8.dp))
                                        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                            c.tags.split(",").filter { it.isNotBlank() }.forEach { tag ->
                                                Surface(shape = RoundedCornerShape(12.dp), color = Color(0xFFFAF5FF)) {
                                                    Text(tag.trim(), modifier = Modifier.padding(horizontal = 10.dp, vertical = 3.dp), fontSize = 11.sp, fontWeight = FontWeight.Bold, color = Color(0xFFCC1A8A))
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }

                        // Metrics
                        item {
                            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                MetricTile(Modifier.weight(1f), "LTV", "£%.2f".format(c.ltv), Color(0xFFCC1A8A))
                                MetricTile(Modifier.weight(1f), "Completed", "${c.completedCount}", Color(0xFF16A34A))
                                MetricTile(Modifier.weight(1f), "Points", "${c.loyaltyPoints} pts", Color(0xFFCA8A04))
                            }
                        }

                        // Action buttons (disabled when offline)
                        item {
                            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                Button(
                                    onClick = { showNoteDialog = true },
                                    modifier = Modifier.weight(1f),
                                    enabled = isOnline,
                                    colors = ButtonDefaults.buttonColors(containerColor = Color(0xFFCC1A8A))
                                ) { Text("+ Note", fontSize = 13.sp) }
                                Button(
                                    onClick = { showPointsDialog = true },
                                    modifier = Modifier.weight(1f),
                                    enabled = isOnline,
                                    colors = ButtonDefaults.buttonColors(containerColor = Color(0xFFCA8A04))
                                ) { Text("⭐ Points", fontSize = 13.sp) }
                                Button(
                                    onClick = { showBlockDialog = true },
                                    modifier = Modifier.weight(1f),
                                    enabled = isOnline,
                                    colors = ButtonDefaults.buttonColors(containerColor = if (c.isBlocked == 1) Color(0xFF16A34A) else Color(0xFFDC2626))
                                ) { Text(if (c.isBlocked == 1) "Unblock" else "Block", fontSize = 13.sp) }
                            }
                        }

                        // Delete button — separated below action row for safety
                        item {
                            OutlinedButton(
                                onClick  = { showDeleteDialog = true },
                                enabled  = isOnline,
                                modifier = Modifier.fillMaxWidth(),
                                colors   = ButtonDefaults.outlinedButtonColors(contentColor = Color(0xFF7F1D1D)),
                                border   = androidx.compose.foundation.BorderStroke(1.dp, Color(0xFF7F1D1D))
                            ) { Text("🗑  Delete Client", fontSize = 13.sp) }
                        }

                        // Loyalty history
                        if (!c.loyaltyHistory.isNullOrEmpty()) {
                            item { Text("Loyalty History", fontWeight = FontWeight.Bold, fontSize = 13.sp, color = Color(0xFF7A4A70)) }
                            items(c.loyaltyHistory!!) { t ->
                                Card(shape = RoundedCornerShape(8.dp), modifier = Modifier.fillMaxWidth()) {
                                    Row(Modifier.padding(12.dp), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                                        Column(Modifier.weight(1f)) {
                                            Text(t.description ?: t.type, fontSize = 13.sp)
                                            Text(t.createdAt.take(10), fontSize = 11.sp, color = Color(0xFF7A4A70))
                                        }
                                        Text(
                                            "${if (t.points > 0) "+" else ""}${t.points} pts",
                                            fontWeight = FontWeight.Bold,
                                            color = if (t.points > 0) Color(0xFF16A34A) else Color(0xFFDC2626),
                                            fontSize = 14.sp
                                        )
                                    }
                                }
                            }
                        }

                        // Admin notes
                        if (!c.notes.isNullOrEmpty()) {
                            item { Text("Admin Notes", fontWeight = FontWeight.Bold, fontSize = 13.sp, color = Color(0xFF7A4A70)) }
                            items(c.notes!!) { n ->
                                Card(shape = RoundedCornerShape(8.dp), modifier = Modifier.fillMaxWidth()) {
                                    Column(Modifier.padding(12.dp)) {
                                        Text(n.note, fontSize = 13.sp)
                                        Text(n.createdAt.take(16).replace("T", " "), fontSize = 11.sp, color = Color(0xFF7A4A70), modifier = Modifier.padding(top = 4.dp))
                                    }
                                }
                            }
                        }

                        // Booking history
                        if (!c.bookings.isNullOrEmpty()) {
                            item { Text("Booking History", fontWeight = FontWeight.Bold, fontSize = 13.sp, color = Color(0xFF7A4A70)) }
                            items(c.bookings!!) { b ->
                                Card(shape = RoundedCornerShape(8.dp), modifier = Modifier.fillMaxWidth()) {
                                    Row(Modifier.padding(12.dp), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                                        Column(Modifier.weight(1f)) {
                                            Text(b.serviceName, fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
                                            Text(b.date, fontSize = 12.sp, color = Color(0xFF7A4A70))
                                        }
                                        Column(horizontalAlignment = Alignment.End) {
                                            StatusChip(b.status)
                                            Text("£%.2f".format(b.totalPrice), fontSize = 12.sp, color = Color(0xFF7A4A70), modifier = Modifier.padding(top = 4.dp))
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun MetricTile(modifier: Modifier, label: String, value: String, color: Color) {
    Card(modifier = modifier, shape = RoundedCornerShape(10.dp)) {
        Column(Modifier.padding(12.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Text(value, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, color = color)
            Text(label, fontSize = 11.sp, color = Color(0xFF7A4A70))
        }
    }
}

@Composable
private fun StatusChip(status: String) {
    val (label, color, bg) = when (status) {
        "confirmed"      -> Triple("Confirmed",   Color(0xFF16A34A), Color(0xFFF0FDF4))
        "completed"      -> Triple("Completed",   Color(0xFF7C3AED), Color(0xFFF5F3FF))
        "pending"        -> Triple("Pending",     Color(0xFFD97706), Color(0xFFFFFBEB))
        "cancelled"      -> Triple("Cancelled",   Color(0xFFDC2626), Color(0xFFFEF2F2))
        "late_cancelled" -> Triple("Late Cancel", Color(0xFFDC2626), Color(0xFFFEF2F2))
        "no_show"        -> Triple("No Show",     Color(0xFF9CA3AF), Color(0xFFF9FAFB))
        else             -> Triple(status,        Color(0xFF6B7280), Color(0xFFF3F4F6))
    }
    Surface(shape = RoundedCornerShape(12.dp), color = bg) {
        Text(label, modifier = Modifier.padding(horizontal = 8.dp, vertical = 2.dp), fontSize = 11.sp, fontWeight = FontWeight.Bold, color = color)
    }
}
