package com.braidedbyagb.admin.ui.screens.accounting

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
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
import com.braidedbyagb.admin.data.db.AppDatabase
import com.braidedbyagb.admin.data.db.CacheEntry
import com.braidedbyagb.admin.data.model.*
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.sync.SyncWorker
import com.braidedbyagb.admin.utils.NetworkUtils
import com.braidedbyagb.admin.utils.OfflineBanner
import com.google.gson.Gson
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.time.LocalDate

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AccountingScreen(onBack: () -> Unit = {}) {
    val context   = LocalContext.current
    val scope     = rememberCoroutineScope()
    val gson      = remember { Gson() }
    val dao       = remember { AppDatabase.getInstance(context).cacheDao() }
    val isOnline  = remember { NetworkUtils.isOnline(context) }

    var stats     by remember { mutableStateOf<AccountingStats?>(null) }
    var isLoading by remember { mutableStateOf(true) }
    var tab       by remember { mutableStateOf(0) }
    var msg       by remember { mutableStateOf<String?>(null) }

    // Expense form state
    var expAmount   by remember { mutableStateOf("") }
    var expDesc     by remember { mutableStateOf("") }
    var expCategory by remember { mutableStateOf("Business Expenses") }
    var expDate     by remember { mutableStateOf(LocalDate.now().toString()) }
    var expLoading  by remember { mutableStateOf(false) }

    // Draw form state
    var drawAmount  by remember { mutableStateOf("") }
    var drawNotes   by remember { mutableStateOf("") }
    var drawDate    by remember { mutableStateOf(LocalDate.now().toString()) }
    var drawLoading by remember { mutableStateOf(false) }

    val categories = listOf("Business Expenses", "Cost of Sales", "Marketing", "Equipment", "Transport", "Other")

    fun refresh() { scope.launch {
        // Cache-first
        val entry = withContext(Dispatchers.IO) { dao.get(SyncWorker.KEY_ACCOUNTING) }
        if (entry != null) {
            runCatching { gson.fromJson(entry.json, AccountingStats::class.java) }
                .onSuccess { stats = it }
        }
        isLoading = stats == null
        // Fetch live
        try {
            val res = ApiClient.api.getAccounting()
            if (res.isSuccessful && res.body() != null) {
                stats = res.body()
                withContext(Dispatchers.IO) { dao.put(CacheEntry(SyncWorker.KEY_ACCOUNTING, gson.toJson(stats))) }
            }
        } catch (_: Exception) {}
        isLoading = false
    }}

    LaunchedEffect(Unit) { refresh() }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Finance", fontWeight = FontWeight.Bold) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, "Back")
                    }
                }
            )
        }
    ) { innerPadding ->
    Column(Modifier.fillMaxSize().padding(innerPadding)) {
        if (!isOnline) OfflineBanner()

        LazyColumn(
            modifier = Modifier.fillMaxSize(),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {

            if (isLoading) {
                item { Box(Modifier.fillMaxWidth().height(200.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Color(0xFFCC1A8A)) } }
                return@LazyColumn
            }

            if (msg != null) {
                item {
                    Card(colors = CardDefaults.cardColors(containerColor = Color(0xFFF0FDF4)), shape = RoundedCornerShape(8.dp)) {
                        Text("✅ $msg", Modifier.padding(12.dp), color = Color(0xFF166534), fontWeight = FontWeight.SemiBold)
                    }
                }
            }

            // Summary cards
            if (stats != null) {
                item {
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        SummaryCard(Modifier.weight(1f), "Today", "£%.2f".format(stats!!.todayTakings), Color(0xFF16A34A))
                        SummaryCard(Modifier.weight(1f), "Month Rev", "£%.2f".format(stats!!.monthRevenue), Color(0xFFCC1A8A))
                    }
                }
                item {
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        SummaryCard(Modifier.weight(1f), "Expenses", "£%.2f".format(stats!!.monthExpenses), Color(0xFFDC2626))
                        SummaryCard(
                            Modifier.weight(1f),
                            "Net Profit",
                            "${if (stats!!.monthProfit < 0) "-" else ""}£%.2f".format(Math.abs(stats!!.monthProfit)),
                            if (stats!!.monthProfit >= 0) Color(0xFF16A34A) else Color(0xFFDC2626)
                        )
                    }
                }
            }

            // Tabs
            item {
                TabRow(selectedTabIndex = tab, containerColor = Color.White) {
                    Tab(selected = tab == 0, onClick = { tab = 0; msg = null }) { Text("Log Expense", Modifier.padding(vertical = 12.dp)) }
                    Tab(selected = tab == 1, onClick = { tab = 1; msg = null }) { Text("Pay Myself", Modifier.padding(vertical = 12.dp)) }
                }
            }

            // ── Log Expense form ─────────────────────────────────
            if (tab == 0) {
                item {
                    Card(shape = RoundedCornerShape(12.dp), modifier = Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                            OutlinedTextField(
                                value = expAmount, onValueChange = { expAmount = it },
                                label = { Text("Amount (£)") },
                                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                                enabled = isOnline,
                                modifier = Modifier.fillMaxWidth()
                            )
                            OutlinedTextField(
                                value = expDesc, onValueChange = { expDesc = it },
                                label = { Text("Description *") },
                                enabled = isOnline,
                                modifier = Modifier.fillMaxWidth()
                            )
                            var catExpanded by remember { mutableStateOf(false) }
                            ExposedDropdownMenuBox(expanded = catExpanded, onExpandedChange = { if (isOnline) catExpanded = it }) {
                                OutlinedTextField(
                                    value = expCategory, onValueChange = {},
                                    readOnly = true, label = { Text("Category") },
                                    trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = catExpanded) },
                                    enabled = isOnline,
                                    modifier = Modifier.fillMaxWidth().menuAnchor()
                                )
                                ExposedDropdownMenu(expanded = catExpanded, onDismissRequest = { catExpanded = false }) {
                                    categories.forEach { cat ->
                                        DropdownMenuItem(text = { Text(cat) }, onClick = { expCategory = cat; catExpanded = false })
                                    }
                                }
                            }
                            OutlinedTextField(
                                value = expDate, onValueChange = { expDate = it },
                                label = { Text("Date (YYYY-MM-DD)") },
                                enabled = isOnline,
                                modifier = Modifier.fillMaxWidth()
                            )
                            Button(
                                onClick = {
                                    val amt = expAmount.toDoubleOrNull() ?: return@Button
                                    if (expDesc.isBlank()) return@Button
                                    scope.launch {
                                        expLoading = true
                                        try {
                                            val res = ApiClient.api.logExpense(ExpenseRequest(amt, expDesc.trim(), expCategory, expDate))
                                            if (res.isSuccessful) {
                                                msg = "Expense of £%.2f logged".format(amt)
                                                expAmount = ""; expDesc = ""; expDate = LocalDate.now().toString()
                                                refresh()
                                            }
                                        } catch (_: Exception) {}
                                        expLoading = false
                                    }
                                },
                                modifier = Modifier.fillMaxWidth(),
                                enabled = !expLoading && isOnline,
                                colors = ButtonDefaults.buttonColors(containerColor = Color(0xFFCC1A8A))
                            ) {
                                if (expLoading) CircularProgressIndicator(Modifier.size(18.dp), color = Color.White, strokeWidth = 2.dp)
                                else Text(if (isOnline) "Log Expense" else "Offline")
                            }
                        }
                    }
                }

                if (!stats?.recentExpenses.isNullOrEmpty()) {
                    item { Text("Recent Expenses", fontWeight = FontWeight.SemiBold, fontSize = 13.sp, color = Color(0xFF7A4A70)) }
                    items(stats!!.recentExpenses) { e ->
                        Card(shape = RoundedCornerShape(8.dp), modifier = Modifier.fillMaxWidth()) {
                            Row(Modifier.padding(12.dp), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text(e.description, fontSize = 13.sp, fontWeight = FontWeight.Medium)
                                    Text("${e.date} · ${e.category ?: "Expense"}", fontSize = 11.sp, color = Color(0xFF7A4A70))
                                }
                                Text("£%.2f".format(e.amount), fontWeight = FontWeight.Bold, color = Color(0xFFDC2626), fontSize = 14.sp)
                            }
                        }
                    }
                }
            }

            // ── Pay Myself form ──────────────────────────────────
            if (tab == 1) {
                item {
                    Card(shape = RoundedCornerShape(12.dp), modifier = Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                            OutlinedTextField(
                                value = drawAmount, onValueChange = { drawAmount = it },
                                label = { Text("Amount (£)") },
                                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                                enabled = isOnline,
                                modifier = Modifier.fillMaxWidth()
                            )
                            OutlinedTextField(
                                value = drawNotes, onValueChange = { drawNotes = it },
                                label = { Text("Notes (optional)") },
                                enabled = isOnline,
                                modifier = Modifier.fillMaxWidth()
                            )
                            OutlinedTextField(
                                value = drawDate, onValueChange = { drawDate = it },
                                label = { Text("Date (YYYY-MM-DD)") },
                                enabled = isOnline,
                                modifier = Modifier.fillMaxWidth()
                            )
                            Button(
                                onClick = {
                                    val amt = drawAmount.toDoubleOrNull() ?: return@Button
                                    scope.launch {
                                        drawLoading = true
                                        try {
                                            val res = ApiClient.api.logDraw(DrawRequest(amt, drawDate, drawNotes.ifBlank { null }))
                                            if (res.isSuccessful) {
                                                msg = "£%.2f draw logged".format(amt)
                                                drawAmount = ""; drawNotes = ""; drawDate = LocalDate.now().toString()
                                                refresh()
                                            }
                                        } catch (_: Exception) {}
                                        drawLoading = false
                                    }
                                },
                                modifier = Modifier.fillMaxWidth(),
                                enabled = !drawLoading && isOnline,
                                colors = ButtonDefaults.buttonColors(containerColor = Color(0xFFCC1A8A))
                            ) {
                                if (drawLoading) CircularProgressIndicator(Modifier.size(18.dp), color = Color.White, strokeWidth = 2.dp)
                                else Text(if (isOnline) "💸 Pay Myself" else "Offline")
                            }
                        }
                    }
                }

                if (!stats?.recentDraws.isNullOrEmpty()) {
                    item { Text("Recent Draws", fontWeight = FontWeight.SemiBold, fontSize = 13.sp, color = Color(0xFF7A4A70)) }
                    items(stats!!.recentDraws) { d ->
                        Card(shape = RoundedCornerShape(8.dp), modifier = Modifier.fillMaxWidth()) {
                            Row(Modifier.padding(12.dp), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text(if (!d.notes.isNullOrBlank()) d.notes else "Owner draw", fontSize = 13.sp)
                                    Text(d.date, fontSize = 11.sp, color = Color(0xFF7A4A70))
                                }
                                Text("£%.2f".format(d.amount), fontWeight = FontWeight.Bold, color = Color(0xFF2A0020), fontSize = 14.sp)
                            }
                        }
                    }
                }
            }
        }
    }
    } // end Scaffold
}

@Composable
private fun SummaryCard(modifier: Modifier, label: String, value: String, color: Color) {
    Card(modifier = modifier, shape = RoundedCornerShape(10.dp)) {
        Column(Modifier.padding(14.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Text(value, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, color = color)
            Text(label, fontSize = 11.sp, color = Color(0xFF7A4A70))
        }
    }
}
