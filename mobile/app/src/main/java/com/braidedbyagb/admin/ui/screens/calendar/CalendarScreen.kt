package com.braidedbyagb.admin.ui.screens.calendar

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.braidedbyagb.admin.data.db.AppDatabase
import com.braidedbyagb.admin.data.db.CacheEntry
import com.braidedbyagb.admin.data.model.AvailabilityBlock
import com.braidedbyagb.admin.data.model.BlockRequest
import com.braidedbyagb.admin.data.model.BookingSummary
import com.braidedbyagb.admin.data.model.CalendarResponse
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.ui.theme.Purple800
import com.braidedbyagb.admin.utils.NetworkUtils
import com.braidedbyagb.admin.utils.OfflineBanner
import com.google.gson.Gson
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.time.LocalDate
import java.time.YearMonth
import java.time.format.TextStyle
import java.util.*

private val TIME_SLOTS = buildList {
    var h = 8; var m = 0
    while (h < 18) {
        add("%02d:%02d".format(h, m))
        m += 30; if (m == 60) { m = 0; h++ }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CalendarScreen() {
    val context   = LocalContext.current
    val scope     = rememberCoroutineScope()
    val gson      = remember { Gson() }
    val dao       = remember { AppDatabase.getInstance(context).cacheDao() }
    val isOnline  = remember { NetworkUtils.isOnline(context) }

    var ym       by remember { mutableStateOf(YearMonth.now()) }
    var bookings by remember { mutableStateOf<List<BookingSummary>>(emptyList()) }
    var blocks   by remember { mutableStateOf<List<AvailabilityBlock>>(emptyList()) }
    var loading  by remember { mutableStateOf(true) }
    var selectedDate  by remember { mutableStateOf<String?>(null) }
    var showSheet     by remember { mutableStateOf(false) }

    fun load() { scope.launch {
        val cacheKey = "calendar_${ym.year}_${ym.monthValue}"
        // Cache-first
        val entry = withContext(Dispatchers.IO) { dao.get(cacheKey) }
        if (entry != null) {
            runCatching { gson.fromJson(entry.json, CalendarResponse::class.java) }
                .onSuccess { bookings = it.bookings; blocks = it.blocks }
        }
        loading = bookings.isEmpty()
        // Fetch live
        try {
            val res = ApiClient.api.getCalendar(ym.year, ym.monthValue)
            if (res.isSuccessful && res.body() != null) {
                bookings = res.body()!!.bookings
                blocks   = res.body()!!.blocks
                withContext(Dispatchers.IO) { dao.put(CacheEntry(cacheKey, gson.toJson(res.body()))) }
            }
        } catch (_: Exception) {}
        loading = false
    }}

    LaunchedEffect(ym) { load() }

    // ── UI ────────────────────────────────────────────────
    Column(Modifier.fillMaxSize()) {
        if (!isOnline) OfflineBanner()

        Column(Modifier.fillMaxSize().padding(16.dp)) {
            // Month nav
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.SpaceBetween) {
                TextButton(onClick = { ym = ym.minusMonths(1) }) { Text("‹") }
                Text("${ym.month.getDisplayName(TextStyle.FULL, Locale.UK)} ${ym.year}", fontWeight = FontWeight.ExtraBold, color = Purple800, fontSize = 18.sp)
                TextButton(onClick = { ym = ym.plusMonths(1) }) { Text("›") }
            }

            // Day headers
            val dayNames = listOf("Sun","Mon","Tue","Wed","Thu","Fri","Sat")
            Row(Modifier.fillMaxWidth()) {
                dayNames.forEach { d -> Text(d, Modifier.weight(1f), textAlign = TextAlign.Center, fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurfaceVariant, fontWeight = FontWeight.Bold) }
            }

            Spacer(Modifier.height(4.dp))

            if (loading) { Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }; return@Column }

            // Build day cells
            val firstDay = ym.atDay(1).dayOfWeek.value % 7 // Sunday = 0
            val days = (1..ym.lengthOfMonth()).map { ym.atDay(it) }
            val cells = List(firstDay) { null } + days

            val bookingsByDate = bookings.groupBy { it.date }
            val blocksByDate   = blocks.groupBy { it.date }

            LazyVerticalGrid(columns = GridCells.Fixed(7), modifier = Modifier.fillMaxSize(), horizontalArrangement = Arrangement.spacedBy(2.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                items(cells) { date ->
                    if (date == null) { Box(Modifier.aspectRatio(1f)) {}; return@items }
                    val dateStr    = date.toString()
                    val dayBks     = bookingsByDate[dateStr] ?: emptyList()
                    val dayBlocks  = blocksByDate[dateStr] ?: emptyList()
                    val isFullDay  = dayBlocks.any { it.timeSlot == null }
                    val hasSlots   = dayBlocks.any { it.timeSlot != null }
                    val isToday    = date == LocalDate.now()
                    val isPast     = date.isBefore(LocalDate.now())
                    val bgColor    = when {
                        isFullDay -> Color(0xFFFEE2E2)
                        hasSlots  -> Color(0xFFFEF9C3)
                        else      -> MaterialTheme.colorScheme.surface
                    }
                    Box(
                        modifier = Modifier
                            .aspectRatio(1f)
                            .background(bgColor, MaterialTheme.shapes.small)
                            .then(if (!isPast && isOnline) Modifier.clickable { selectedDate = dateStr; showSheet = true } else Modifier),
                        contentAlignment = Alignment.TopCenter
                    ) {
                        Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.padding(2.dp)) {
                            Text(
                                date.dayOfMonth.toString(), fontSize = 12.sp,
                                fontWeight = if (isToday) FontWeight.ExtraBold else FontWeight.Normal,
                                color = if (isToday) Purple800 else if (isPast) MaterialTheme.colorScheme.onSurfaceVariant else MaterialTheme.colorScheme.onSurface
                            )
                            if (dayBks.isNotEmpty()) {
                                Box(Modifier.size(6.dp).background(Purple800, MaterialTheme.shapes.small))
                            }
                            if (isFullDay) Text("🔴", fontSize = 8.sp)
                            else if (hasSlots) Text("🟡", fontSize = 8.sp)
                        }
                    }
                }
            }
        }
    }

    // Bottom sheet for blocking/unblocking
    if (showSheet && selectedDate != null && isOnline) {
        val date = selectedDate!!
        val dayBlocks = blocks.filter { it.date == date }
        val isFullDay = dayBlocks.any { it.timeSlot == null }
        var selectedSlot by remember { mutableStateOf("all") }
        var reason by remember { mutableStateOf("") }
        var saving by remember { mutableStateOf(false) }

        val dayBookings = bookings.filter { it.date == date }.sortedBy { it.time }

        ModalBottomSheet(onDismissRequest = { showSheet = false }) {
            Column(Modifier.padding(20.dp).fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text(date, fontWeight = FontWeight.ExtraBold, color = Purple800, fontSize = 16.sp)

                // Appointments on this day — shows the duration window (start → end)
                if (dayBookings.isNotEmpty()) {
                    Text("Appointments (${dayBookings.size})", fontWeight = FontWeight.Bold, fontSize = 14.sp)
                    dayBookings.forEach { bk ->
                        val window = bk.endTime?.let { "${bk.time.take(5)} → ${it.take(5)}" } ?: bk.time.take(5)
                        Column(Modifier.fillMaxWidth()) {
                            Text(
                                "$window · ${bk.clientName}" + (bk.guestName?.let { " (for $it)" } ?: ""),
                                fontSize = 13.sp, fontWeight = FontWeight.Medium, color = Purple800
                            )
                            Text(
                                bk.serviceName + (bk.variantName?.let { " — $it" } ?: "") +
                                    (bk.cartGroupRef?.let { "  👪 group" } ?: ""),
                                fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant
                            )
                        }
                    }
                    HorizontalDivider()
                }

                if (isFullDay) {
                    Button(
                        onClick = {
                            scope.launch {
                                saving = true
                                ApiClient.api.unblockSlot(BlockRequest(date = date, timeSlot = null))
                                saving = false; showSheet = false; load()
                            }
                        },
                        enabled = isOnline,
                        colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF16A34A)),
                        modifier = Modifier.fillMaxWidth()
                    ) { Text("✓ Unblock Full Day") }
                }

                dayBlocks.filter { it.timeSlot != null }.forEach { b ->
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                        Text("🟡 ${b.timeSlot?.take(5)} blocked${b.reason?.let { " — $it" } ?: ""}", fontSize = 13.sp)
                        TextButton(
                            onClick = {
                                scope.launch { ApiClient.api.unblockSlot(BlockRequest(date = date, timeSlot = b.timeSlot)); load() }
                            },
                            enabled = isOnline
                        ) { Text("Remove", color = MaterialTheme.colorScheme.error) }
                    }
                }

                HorizontalDivider()
                Text("Add a block", fontWeight = FontWeight.Bold)

                var expanded by remember { mutableStateOf(false) }
                ExposedDropdownMenuBox(expanded = expanded, onExpandedChange = { expanded = it }) {
                    OutlinedTextField(
                        value         = if (selectedSlot == "all") "All day" else selectedSlot,
                        onValueChange = {},
                        readOnly      = true,
                        label         = { Text("Time slot") },
                        trailingIcon  = { ExposedDropdownMenuDefaults.TrailingIcon(expanded) },
                        modifier      = Modifier.menuAnchor().fillMaxWidth()
                    )
                    ExposedDropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
                        DropdownMenuItem(text = { Text("All day") }, onClick = { selectedSlot = "all"; expanded = false })
                        TIME_SLOTS.forEach { t ->
                            DropdownMenuItem(text = { Text(t) }, onClick = { selectedSlot = t; expanded = false })
                        }
                    }
                }

                OutlinedTextField(
                    value = reason, onValueChange = { reason = it },
                    label = { Text("Reason (optional)") }, singleLine = true,
                    modifier = Modifier.fillMaxWidth()
                )

                Button(
                    onClick = {
                        scope.launch {
                            saving = true
                            val slot = if (selectedSlot == "all") null else selectedSlot
                            ApiClient.api.blockSlot(BlockRequest(date = date, timeSlot = slot, reason = reason.takeIf { it.isNotBlank() }))
                            saving = false; showSheet = false; load()
                        }
                    },
                    enabled  = !saving && isOnline,
                    modifier = Modifier.fillMaxWidth(),
                    colors   = ButtonDefaults.buttonColors(containerColor = MaterialTheme.colorScheme.error)
                ) { Text(if (saving) "Saving…" else "Block") }

                Spacer(Modifier.height(16.dp))
            }
        }
    }
}
