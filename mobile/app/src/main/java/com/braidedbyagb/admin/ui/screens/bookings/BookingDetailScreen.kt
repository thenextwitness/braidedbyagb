package com.braidedbyagb.admin.ui.screens.bookings

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.braidedbyagb.admin.data.db.AppDatabase
import com.braidedbyagb.admin.data.db.CacheEntry
import com.braidedbyagb.admin.data.model.BookingDetail
import com.braidedbyagb.admin.data.model.NotesRequest
import com.braidedbyagb.admin.data.model.RescheduleRequest
import com.braidedbyagb.admin.data.model.SetDurationRequest
import com.braidedbyagb.admin.data.model.StatusRequest
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.ui.screens.dashboard.StatusChip
import com.braidedbyagb.admin.ui.theme.Primary
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
fun BookingDetailScreen(
    bookingId:      Int,
    onBack:         () -> Unit,
    onCollectPayment: (Int) -> Unit = {}
) {
    val scope     = rememberCoroutineScope()
    val context   = LocalContext.current
    val clipboard = LocalClipboardManager.current
    val gson      = remember { Gson() }
    val dao       = remember { AppDatabase.getInstance(context).cacheDao() }
    val isOnline  = remember { NetworkUtils.isOnline(context) }
    val cacheKey  = "booking_$bookingId"

    var bk      by remember { mutableStateOf<BookingDetail?>(null) }
    var loading by remember { mutableStateOf(true) }
    var error   by remember { mutableStateOf<String?>(null) }
    var snack   by remember { mutableStateOf<String?>(null) }

    // Admin notes editing
    var editingNotes by remember { mutableStateOf(false) }
    var notesText    by remember { mutableStateOf("") }
    var notesSaving  by remember { mutableStateOf(false) }

    // Payment link state
    var linkLoading by remember { mutableStateOf(false) }

    // Dialog flags
    var showReschedule    by remember { mutableStateOf(false) }
    var showDeleteConfirm by remember { mutableStateOf(false) }
    var showRevokeConfirm by remember { mutableStateOf(false) }
    var showDurationDialog by remember { mutableStateOf(false) }

    fun reload() { scope.launch {
        // Cache-first
        val entry = withContext(Dispatchers.IO) { dao.get(cacheKey) }
        if (entry != null) {
            runCatching { gson.fromJson(entry.json, BookingDetail::class.java) }
                .onSuccess { bk = it; notesText = it.adminNotes ?: "" }
        }
        loading = bk == null
        // Fetch live
        try {
            val res = ApiClient.api.getBooking(bookingId)
            if (res.isSuccessful && res.body() != null) {
                bk = res.body()
                notesText = bk?.adminNotes ?: ""
                withContext(Dispatchers.IO) { dao.put(CacheEntry(cacheKey, gson.toJson(bk))) }
                error = null
            } else if (bk == null) {
                error = "Failed to load"
            }
        } catch (e: Exception) {
            if (bk == null) error = "Connection error"
        }
        loading = false
    }}

    LaunchedEffect(Unit) { reload() }

    // ── Reschedule dialog ─────────────────────────────────────
    if (showReschedule) {
        var newDate by remember { mutableStateOf(bk?.date ?: "") }
        var newTime by remember { mutableStateOf(bk?.time?.take(5) ?: "") }
        var rLoading by remember { mutableStateOf(false) }
        AlertDialog(
            onDismissRequest = { showReschedule = false },
            title = { Text("Reschedule Appointment") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    OutlinedTextField(
                        value = newDate,
                        onValueChange = { newDate = it },
                        label = { Text("New Date (YYYY-MM-DD)") },
                        placeholder = { Text("2026-06-15") },
                        modifier = Modifier.fillMaxWidth()
                    )
                    OutlinedTextField(
                        value = newTime,
                        onValueChange = { newTime = it },
                        label = { Text("New Time (HH:MM)") },
                        placeholder = { Text("10:00") },
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                        modifier = Modifier.fillMaxWidth()
                    )
                    Text(
                        "A rescheduling email will be sent to the client automatically.",
                        fontSize = 12.sp, color = TextMuted
                    )
                }
            },
            confirmButton = {
                Button(
                    onClick = {
                        if (newDate.isBlank() || newTime.isBlank()) return@Button
                        scope.launch {
                            rLoading = true
                            try {
                                val res = ApiClient.api.rescheduleBooking(
                                    bookingId, RescheduleRequest(newDate, newTime)
                                )
                                if (res.isSuccessful) {
                                    snack = "Appointment rescheduled"
                                    showReschedule = false
                                    reload()
                                } else {
                                    val errBody = res.errorBody()?.string() ?: ""
                                    snack = if (errBody.contains("already booked")) "That slot is already taken" else "Reschedule failed"
                                }
                            } catch (e: Exception) { snack = "Connection error" }
                            rLoading = false
                        }
                    },
                    enabled = !rLoading,
                    colors = ButtonDefaults.buttonColors(containerColor = Primary)
                ) {
                    if (rLoading) CircularProgressIndicator(Modifier.size(16.dp), color = Color.White, strokeWidth = 2.dp)
                    else Text("Confirm")
                }
            },
            dismissButton = { TextButton(onClick = { showReschedule = false }) { Text("Cancel") } }
        )
    }

    // ── Revoke payment link dialog ────────────────────────────
    if (showRevokeConfirm) {
        AlertDialog(
            onDismissRequest = { showRevokeConfirm = false },
            title = { Text("Revoke Payment Link") },
            text  = { Text("This will invalidate the current payment link. The client will no longer be able to pay via that link.") },
            confirmButton = {
                TextButton(onClick = {
                    scope.launch {
                        linkLoading = true
                        try {
                            val res = ApiClient.api.revokePaymentLink(bookingId)
                            if (res.isSuccessful) { snack = "Payment link revoked"; reload() }
                            else snack = "Failed to revoke link"
                        } catch (e: Exception) { snack = "Connection error" }
                        linkLoading = false; showRevokeConfirm = false
                    }
                }) { Text("Revoke", color = Color(0xFFDC2626)) }
            },
            dismissButton = { TextButton(onClick = { showRevokeConfirm = false }) { Text("Cancel") } }
        )
    }

    // ── Delete confirm dialog ─────────────────────────────────
    if (showDeleteConfirm) {
        AlertDialog(
            onDismissRequest = { showDeleteConfirm = false },
            title = { Text("Delete Booking") },
            text = { Text("Permanently delete this cancelled booking? This cannot be undone.") },
            confirmButton = {
                TextButton(onClick = {
                    scope.launch {
                        try {
                            val res = ApiClient.api.deleteBooking(bookingId)
                            if (res.isSuccessful) {
                                showDeleteConfirm = false
                                onBack()
                            } else {
                                snack = "Cannot delete: booking may have a payment record"
                                showDeleteConfirm = false
                            }
                        } catch (e: Exception) { snack = "Connection error"; showDeleteConfirm = false }
                    }
                }) { Text("Delete", color = Color(0xFFDC2626)) }
            },
            dismissButton = { TextButton(onClick = { showDeleteConfirm = false }) { Text("Cancel") } }
        )
    }

    // ── Edit Duration dialog ──────────────────────────────────
    if (showDurationDialog && bk != null) {
        val b = bk!!
        val prefillMins = b.durationMins ?: b.serviceDurationMins ?: 60
        var durationText by remember { mutableStateOf(prefillMins.toString()) }
        var dLoading by remember { mutableStateOf(false) }
        AlertDialog(
            onDismissRequest = { showDurationDialog = false },
            title = { Text("Set Booking Duration") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text(
                        "Service default: ${formatDuration(b.serviceDurationMins ?: 60)}. Enter an override in minutes below.",
                        fontSize = 12.sp, color = TextMuted
                    )
                    OutlinedTextField(
                        value = durationText,
                        onValueChange = { durationText = it.filter { c -> c.isDigit() } },
                        label = { Text("Duration (minutes)") },
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                        modifier = Modifier.fillMaxWidth()
                    )
                }
            },
            confirmButton = {
                Button(
                    onClick = {
                        val mins = durationText.toIntOrNull() ?: 0
                        if (mins < 1) return@Button
                        scope.launch {
                            dLoading = true
                            try {
                                val res = ApiClient.api.setBookingDuration(bookingId, SetDurationRequest(mins))
                                if (res.isSuccessful) {
                                    snack = "Duration updated to ${formatDuration(mins)}"
                                    showDurationDialog = false
                                    reload()
                                } else snack = "Failed to update duration"
                            } catch (e: Exception) { snack = "Connection error" }
                            dLoading = false
                        }
                    },
                    enabled = !dLoading,
                    colors = ButtonDefaults.buttonColors(containerColor = Primary)
                ) {
                    if (dLoading) CircularProgressIndicator(Modifier.size(16.dp), color = Color.White, strokeWidth = 2.dp)
                    else Text("Save")
                }
            },
            dismissButton = { TextButton(onClick = { showDurationDialog = false }) { Text("Cancel") } }
        )
    }

    val snackbarHostState = remember { SnackbarHostState() }
    LaunchedEffect(snack) {
        snack?.let { snackbarHostState.showSnackbar(it); snack = null }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(bk?.ref ?: "Booking", fontWeight = FontWeight.Bold) },
                navigationIcon = { IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, null) } }
            )
        },
        snackbarHost = { SnackbarHost(snackbarHostState) }
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            if (!isOnline) OfflineBanner()
            when {
            loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                CircularProgressIndicator(color = Primary)
            }
            error != null && bk == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                Text(error!!, color = MaterialTheme.colorScheme.error)
            }
            bk != null -> {
                val b = bk!!
                LazyColumn(
                    Modifier.fillMaxSize(),
                    contentPadding = PaddingValues(16.dp),
                    verticalArrangement = Arrangement.spacedBy(12.dp)
                ) {

                    // Status + action buttons
                    item {
                        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            Row(
                                horizontalArrangement = Arrangement.spacedBy(8.dp),
                                verticalAlignment = Alignment.CenterVertically
                            ) {
                                StatusChip(b.status)
                                Spacer(Modifier.weight(1f))
                                // Confirm (pending only)
                                if (b.status == "pending") {
                                    Button(
                                        onClick = {
                                            scope.launch {
                                                ApiClient.api.updateBookingStatus(b.id, StatusRequest("confirmed"))
                                                snack = "Booking confirmed"; reload()
                                            }
                                        },
                                        enabled = isOnline,
                                        colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF16A34A))
                                    ) { Text("Confirm", fontSize = 12.sp) }
                                }
                                // Complete (confirmed only)
                                if (b.status == "confirmed") {
                                    Button(
                                        onClick = {
                                            scope.launch {
                                                ApiClient.api.updateBookingStatus(b.id, StatusRequest("completed"))
                                                snack = "Booking completed ✓"; reload()
                                            }
                                        },
                                        enabled = isOnline,
                                        colors = ButtonDefaults.buttonColors(containerColor = Primary)
                                    ) { Text("Complete", fontSize = 12.sp) }
                                }
                                // Cancel (pending or confirmed)
                                if (b.status in listOf("pending", "confirmed")) {
                                    OutlinedButton(
                                        onClick = {
                                            scope.launch {
                                                ApiClient.api.updateBookingStatus(b.id, StatusRequest("cancelled"))
                                                snack = "Booking cancelled"; reload()
                                            }
                                        },
                                        enabled = isOnline
                                    ) { Text("Cancel", fontSize = 12.sp) }
                                }
                            }
                            // Reschedule (pending or confirmed)
                            if (b.status in listOf("pending", "confirmed")) {
                                OutlinedButton(
                                    onClick = { showReschedule = true },
                                    modifier = Modifier.fillMaxWidth(),
                                    enabled = isOnline,
                                    colors = ButtonDefaults.outlinedButtonColors(contentColor = Primary)
                                ) { Text("📅 Reschedule") }
                            }
                            // Collect card payment (pending/confirmed with balance outstanding)
                            val hasBalance = b.status in listOf("pending", "confirmed") &&
                                (b.depositPaid == 0 || b.balance > 0.01)
                            if (hasBalance) {
                                Button(
                                    onClick  = { onCollectPayment(bookingId) },
                                    modifier = Modifier.fillMaxWidth(),
                                    enabled  = isOnline,
                                    colors   = ButtonDefaults.buttonColors(containerColor = Color(0xFF1A56CC))
                                ) { Text("💳 Collect Card Payment") }
                            }
                            // Delete (cancelled only)
                            if (b.status == "cancelled") {
                                OutlinedButton(
                                    onClick = { showDeleteConfirm = true },
                                    modifier = Modifier.fillMaxWidth(),
                                    colors = ButtonDefaults.outlinedButtonColors(contentColor = Color(0xFFDC2626))
                                ) { Text("🗑 Delete Booking") }
                            }
                        }
                    }

                    // Booking info
                    item {
                        InfoCard("Booking Details") {
                            InfoRow("Service", b.serviceName + (b.variantName?.let { " — $it" } ?: ""))
                            if (!b.guestName.isNullOrBlank()) InfoRow("For", b.guestName)
                            if (!b.cartGroupRef.isNullOrBlank()) InfoRow("Group", "👪 Booked with others (${b.cartGroupRef})")
                            InfoRow("Date", b.date)
                            InfoRow("Time", b.time.take(5))
                            InfoRow("Created", b.createdAt.take(10))
                        }
                    }

                    // Duration card
                    item {
                        val effectiveMins = b.durationMins ?: b.serviceDurationMins ?: 60
                        val isOverride    = b.durationMins != null
                        val startTime     = b.time.take(5)   // "HH:MM"
                        val parts         = startTime.split(":")
                        val startH        = parts.getOrNull(0)?.toIntOrNull() ?: 0
                        val startM        = parts.getOrNull(1)?.toIntOrNull() ?: 0
                        val totalMins     = startH * 60 + startM + effectiveMins
                        val endH          = (totalMins / 60) % 24
                        val endM          = totalMins % 60
                        val endTime       = "%02d:%02d".format(endH, endM)
                        InfoCard("Appointment Duration") {
                            InfoRow(
                                "Window",
                                "$startTime → $endTime"
                            )
                            InfoRow(
                                "Duration",
                                if (isOverride) "${formatDuration(effectiveMins)}  ✓ admin override"
                                else "${formatDuration(effectiveMins)}  (service default)"
                            )
                            if (b.status in listOf("pending", "confirmed")) {
                                Spacer(Modifier.height(4.dp))
                                TextButton(
                                    onClick = { showDurationDialog = true },
                                    enabled = isOnline,
                                    contentPadding = PaddingValues(0.dp)
                                ) { Text("⏱ Edit Duration", fontSize = 12.sp, color = Primary) }
                            }
                        }
                    }

                    // Client info
                    item {
                        InfoCard("Client") {
                            InfoRow("Name", b.clientName)
                            InfoRow("Email", b.clientEmail ?: "—")
                            InfoRow("Phone", b.clientPhone ?: "—")
                            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                if (!b.clientPhone.isNullOrBlank()) {
                                    OutlinedButton(onClick = {
                                        val phone = b.clientPhone.trimStart('0').let { "44$it" }
                                        context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("https://wa.me/$phone")))
                                    }) { Text("WhatsApp", fontSize = 12.sp) }
                                }
                                if (!b.clientEmail.isNullOrBlank()) {
                                    OutlinedButton(onClick = {
                                        context.startActivity(Intent(Intent.ACTION_SENDTO, Uri.parse("mailto:${b.clientEmail}")))
                                    }) { Text("Email", fontSize = 12.sp) }
                                }
                            }
                        }
                    }

                    // Payment
                    item {
                        InfoCard("Payment") {
                            InfoRow("Total", "£%.2f".format(b.totalPrice))
                            InfoRow("Deposit", "£%.2f".format(b.depositAmount) + if (b.depositPaid == 1) " ✓ Paid" else " — Unpaid")
                            InfoRow("Balance due", "£%.2f".format(b.balance))
                            InfoRow("Method", b.paymentMethod?.replace("_", " ")?.replaceFirstChar { it.uppercase() } ?: "Unknown")
                            if (b.paymentMethod == "bank_transfer" && b.depositPaid == 0) {
                                Button(
                                    onClick = {
                                        scope.launch {
                                            ApiClient.api.confirmDeposit(b.id)
                                            snack = "Deposit marked as received"; reload()
                                        }
                                    },
                                    enabled = isOnline,
                                    colors = ButtonDefaults.buttonColors(containerColor = Primary)
                                ) { Text("Mark Deposit Received") }
                            }
                        }
                    }

                    // Payment link card (shown when token exists and deposit not yet paid)
                    if (!b.paymentToken.isNullOrBlank() && b.depositPaid == 0) {
                        item {
                            val payLink = "https://braidedbyagb.co.uk/pay?token=${b.paymentToken}"
                            InfoCard("Payment Link") {
                                Text(
                                    payLink,
                                    fontSize = 12.sp,
                                    color    = Primary,
                                    modifier = Modifier.fillMaxWidth()
                                )
                                Spacer(Modifier.height(8.dp))
                                Row(
                                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                                    modifier = Modifier.fillMaxWidth()
                                ) {
                                    // Copy
                                    OutlinedButton(
                                        onClick  = {
                                            clipboard.setText(AnnotatedString(payLink))
                                            snack = "Link copied to clipboard"
                                        },
                                        modifier = Modifier.weight(1f)
                                    ) { Text("Copy", fontSize = 12.sp) }
                                    // WhatsApp share
                                    OutlinedButton(
                                        onClick  = {
                                            val msg = "Hi, here's your payment link: $payLink"
                                            context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("https://wa.me/?text=${Uri.encode(msg)}")))
                                        },
                                        modifier = Modifier.weight(1f),
                                        colors   = ButtonDefaults.outlinedButtonColors(contentColor = Color(0xFF25D366))
                                    ) { Text("WhatsApp", fontSize = 12.sp) }
                                }
                                Row(
                                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                                    modifier = Modifier.fillMaxWidth().padding(top = 4.dp)
                                ) {
                                    // Regenerate
                                    Button(
                                        onClick  = {
                                            scope.launch {
                                                linkLoading = true
                                                try {
                                                    val res = ApiClient.api.regeneratePaymentLink(bookingId)
                                                    if (res.isSuccessful) { snack = "New link generated"; reload() }
                                                    else snack = "Failed to regenerate"
                                                } catch (e: Exception) { snack = "Connection error" }
                                                linkLoading = false
                                            }
                                        },
                                        enabled  = !linkLoading,
                                        colors   = ButtonDefaults.buttonColors(containerColor = Primary),
                                        modifier = Modifier.weight(1f)
                                    ) {
                                        if (linkLoading) CircularProgressIndicator(Modifier.size(14.dp), color = Color.White, strokeWidth = 2.dp)
                                        else Text("Regenerate", fontSize = 12.sp)
                                    }
                                    // Revoke
                                    OutlinedButton(
                                        onClick  = { showRevokeConfirm = true },
                                        enabled  = !linkLoading,
                                        colors   = ButtonDefaults.outlinedButtonColors(contentColor = Color(0xFFDC2626)),
                                        modifier = Modifier.weight(1f)
                                    ) { Text("Revoke", fontSize = 12.sp) }
                                }
                            }
                        }
                    }

                    // Add-ons
                    if (b.addons.isNotEmpty()) {
                        item {
                            InfoCard("Add-ons") {
                                b.addons.forEach { a -> InfoRow(a.name, "£%.2f".format(a.price)) }
                            }
                        }
                    }

                    // Payments log
                    if (b.payments.isNotEmpty()) {
                        item {
                            InfoCard("Payment Log") {
                                b.payments.forEach { p ->
                                    Text(
                                        "${p.type.replaceFirstChar { it.uppercase() }} · £%.2f · ${p.status}".format(p.amount),
                                        fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant
                                    )
                                }
                            }
                        }
                    }

                    // Client notes (read-only)
                    if (!b.clientNotes.isNullOrBlank()) {
                        item { InfoCard("Client Notes") { Text(b.clientNotes, fontSize = 14.sp) } }
                    }

                    // Admin notes (editable)
                    item {
                        InfoCard("Admin Notes") {
                            if (editingNotes) {
                                OutlinedTextField(
                                    value       = notesText,
                                    onValueChange = { notesText = it },
                                    placeholder = { Text("Internal notes for this booking…", fontSize = 13.sp) },
                                    modifier    = Modifier.fillMaxWidth(),
                                    minLines    = 3
                                )
                                Row(
                                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                                    modifier = Modifier.padding(top = 8.dp)
                                ) {
                                    Button(
                                        onClick = {
                                            scope.launch {
                                                notesSaving = true
                                                try {
                                                    val res = ApiClient.api.updateBookingNotes(bookingId, NotesRequest(notesText))
                                                    if (res.isSuccessful) {
                                                        snack = "Notes saved"
                                                        editingNotes = false
                                                        reload()
                                                    } else snack = "Failed to save notes"
                                                } catch (e: Exception) { snack = "Connection error" }
                                                notesSaving = false
                                            }
                                        },
                                        enabled = !notesSaving,
                                        colors  = ButtonDefaults.buttonColors(containerColor = Primary)
                                    ) {
                                        if (notesSaving) CircularProgressIndicator(Modifier.size(14.dp), color = Color.White, strokeWidth = 2.dp)
                                        else Text("Save")
                                    }
                                    OutlinedButton(onClick = {
                                        notesText    = b.adminNotes ?: ""
                                        editingNotes = false
                                    }) { Text("Cancel") }
                                }
                            } else {
                                Text(
                                    text     = b.adminNotes?.takeIf { it.isNotBlank() } ?: "(no admin notes)",
                                    fontSize = 14.sp,
                                    color    = if (b.adminNotes.isNullOrBlank()) TextMuted else MaterialTheme.colorScheme.onSurface
                                )
                                Spacer(Modifier.height(6.dp))
                                TextButton(
                                    onClick = { editingNotes = true },
                                    enabled = isOnline,
                                    contentPadding = PaddingValues(0.dp)
                                ) { Text("✏ Edit Notes", fontSize = 12.sp, color = Primary) }
                            }
                        }
                    }
                }
            }
        }
        } // closes Column
    }
}

@Composable
fun InfoCard(title: String, content: @Composable ColumnScope.() -> Unit) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(title, fontWeight = FontWeight.Bold, color = Primary, fontSize = 14.sp)
            HorizontalDivider()
            content()
        }
    }
}

@Composable
fun InfoRow(label: String, value: String) {
    Row(Modifier.fillMaxWidth()) {
        Text(label, fontSize = 13.sp, fontWeight = FontWeight.Medium,
             modifier = Modifier.width(110.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
        Text(value, fontSize = 13.sp, modifier = Modifier.weight(1f))
    }
}
