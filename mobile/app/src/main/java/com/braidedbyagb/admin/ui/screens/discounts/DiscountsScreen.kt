package com.braidedbyagb.admin.ui.screens.discounts

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.braidedbyagb.admin.data.model.CreateDiscountRequest
import com.braidedbyagb.admin.data.model.DiscountCode
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.ui.theme.Primary
import com.braidedbyagb.admin.ui.theme.Purple800
import com.braidedbyagb.admin.ui.theme.TextMuted
import com.braidedbyagb.admin.utils.NetworkUtils
import com.braidedbyagb.admin.utils.OfflineBanner
import kotlinx.coroutines.launch

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DiscountsScreen() {
    val context  = LocalContext.current
    val scope    = rememberCoroutineScope()
    val isOnline = remember { NetworkUtils.isOnline(context) }

    var discounts   by remember { mutableStateOf<List<DiscountCode>>(emptyList()) }
    var loading     by remember { mutableStateOf(true) }
    var togglingId  by remember { mutableStateOf<Int?>(null) }
    var showCreate  by remember { mutableStateOf(false) }
    val snackState  = remember { SnackbarHostState() }

    // Create dialog fields
    var newCode    by remember { mutableStateOf("") }
    var newType    by remember { mutableStateOf("percent") }  // percent | fixed
    var newValue   by remember { mutableStateOf("") }
    var newMaxUses by remember { mutableStateOf("") }
    var newExpiry  by remember { mutableStateOf("") }
    var creating   by remember { mutableStateOf(false) }

    suspend fun load() {
        try {
            val res = ApiClient.api.getDiscounts()
            if (res.isSuccessful && res.body() != null) {
                discounts = res.body()!!.discounts
            }
        } catch (_: Exception) {}
        loading = false
    }

    LaunchedEffect(Unit) { load() }

    fun toggle(dc: DiscountCode) {
        if (!isOnline) { scope.launch { snackState.showSnackbar("You're offline. Connect to perform this action.") }; return }
        scope.launch {
            togglingId = dc.id
            try {
                val res = ApiClient.api.toggleDiscount(dc.id)
                if (res.isSuccessful) {
                    val newActive = res.body()?.isActive ?: (1 - dc.isActive)
                    discounts = discounts.map { if (it.id == dc.id) it.copy(isActive = newActive) else it }
                } else {
                    snackState.showSnackbar("Failed to update")
                }
            } catch (_: Exception) { snackState.showSnackbar("Connection error") }
            togglingId = null
        }
    }

    fun createDiscount() {
        val code  = newCode.trim().uppercase()
        val value = newValue.toDoubleOrNull() ?: 0.0
        val limit = newMaxUses.toIntOrNull()?.takeIf { it > 0 }
        val expiry = newExpiry.trim().takeIf { it.isNotBlank() }

        if (code.isBlank() || value <= 0.0) {
            scope.launch { snackState.showSnackbar("Code and value are required") }
            return
        }

        scope.launch {
            creating = true
            try {
                val res = ApiClient.api.createDiscount(
                    CreateDiscountRequest(
                        code       = code,
                        type       = newType,
                        value      = value,
                        usesLimit  = limit,
                        expiryDate = expiry
                    )
                )
                when {
                    res.isSuccessful -> {
                        // Reload list
                        load()
                        showCreate = false
                        newCode    = ""; newValue = ""; newMaxUses = ""; newExpiry = ""
                        snackState.showSnackbar("Discount code created ✓")
                    }
                    res.code() == 409 -> snackState.showSnackbar("Code already exists")
                    else -> snackState.showSnackbar("Failed to create code")
                }
            } catch (_: Exception) { snackState.showSnackbar("Connection error") }
            creating = false
        }
    }

    // Create dialog
    if (showCreate) {
        AlertDialog(
            onDismissRequest = { if (!creating) { showCreate = false } },
            title = { Text("New Discount Code") },
            text  = {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {

                    OutlinedTextField(
                        value         = newCode,
                        onValueChange = { newCode = it.uppercase() },
                        label         = { Text("Code (e.g. SUMMER20)") },
                        singleLine    = true,
                        modifier      = Modifier.fillMaxWidth(),
                        enabled       = !creating
                    )

                    // Type selector
                    Text("Discount type", fontSize = 12.sp, color = TextMuted)
                    Row(horizontalArrangement = Arrangement.spacedBy(16.dp), verticalAlignment = Alignment.CenterVertically) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            RadioButton(
                                selected = newType == "percent",
                                onClick  = { newType = "percent" },
                                enabled  = !creating
                            )
                            Text("% off", fontSize = 13.sp)
                        }
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            RadioButton(
                                selected = newType == "fixed",
                                onClick  = { newType = "fixed" },
                                enabled  = !creating
                            )
                            Text("£ off", fontSize = 13.sp)
                        }
                    }

                    OutlinedTextField(
                        value         = newValue,
                        onValueChange = { newValue = it },
                        label         = { Text(if (newType == "percent") "% Amount (e.g. 20)" else "£ Amount (e.g. 5.00)") },
                        singleLine    = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                        modifier      = Modifier.fillMaxWidth(),
                        enabled       = !creating
                    )

                    OutlinedTextField(
                        value         = newMaxUses,
                        onValueChange = { newMaxUses = it },
                        label         = { Text("Max uses (0 or blank = unlimited)") },
                        singleLine    = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                        modifier      = Modifier.fillMaxWidth(),
                        enabled       = !creating
                    )

                    OutlinedTextField(
                        value         = newExpiry,
                        onValueChange = { newExpiry = it },
                        label         = { Text("Expiry date (YYYY-MM-DD, optional)") },
                        singleLine    = true,
                        modifier      = Modifier.fillMaxWidth(),
                        enabled       = !creating
                    )
                }
            },
            confirmButton = {
                Button(
                    onClick  = { createDiscount() },
                    enabled  = !creating && isOnline && newCode.isNotBlank() && newValue.isNotBlank(),
                    colors   = ButtonDefaults.buttonColors(containerColor = Primary)
                ) {
                    if (creating) CircularProgressIndicator(Modifier.size(14.dp), color = Color.White, strokeWidth = 2.dp)
                    else Text("Create")
                }
            },
            dismissButton = {
                TextButton(onClick = { if (!creating) showCreate = false }) { Text("Cancel") }
            }
        )
    }

    Scaffold(
        snackbarHost = { SnackbarHost(snackState) },
        floatingActionButton = {
            FloatingActionButton(
                onClick        = {
                    if (isOnline) showCreate = true
                    else scope.launch { snackState.showSnackbar("You're offline. Connect to create discount codes.") }
                },
                containerColor = if (isOnline) Primary else Color(0xFF9CA3AF),
                contentColor   = Color.White
            ) { Icon(Icons.Default.Add, "New Discount Code") }
        }
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            if (!isOnline) OfflineBanner()

            when {
                loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(color = Primary)
                }
                else -> LazyColumn(
                    modifier       = Modifier.fillMaxSize(),
                    contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 16.dp, bottom = 88.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    item {
                        Text(
                            "Discount Codes",
                            fontWeight = FontWeight.ExtraBold,
                            fontSize   = 22.sp,
                            color      = Purple800
                        )
                        Spacer(Modifier.height(4.dp))
                    }

                    if (discounts.isEmpty()) {
                        item {
                            Text("No discount codes yet. Tap + to create one.", color = TextMuted, fontSize = 14.sp)
                        }
                    }

                    items(discounts, key = { it.id }) { dc ->
                        DiscountCard(
                            dc         = dc,
                            toggling   = togglingId == dc.id,
                            isOnline   = isOnline,
                            onToggle   = { toggle(dc) }
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun DiscountCard(
    dc:       DiscountCode,
    toggling: Boolean,
    isOnline: Boolean,
    onToggle: () -> Unit
) {
    val isActive = dc.isActive == 1
    Card(
        modifier = Modifier.fillMaxWidth(),
        colors   = CardDefaults.cardColors(
            containerColor = if (isActive) MaterialTheme.colorScheme.surface
                            else MaterialTheme.colorScheme.surfaceVariant
        )
    ) {
        Row(
            Modifier.padding(14.dp).fillMaxWidth(),
            verticalAlignment     = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.SpaceBetween
        ) {
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(
                        dc.code,
                        fontWeight = FontWeight.Bold,
                        fontSize   = 15.sp,
                        color      = Purple800
                    )
                    if (!isActive) {
                        Surface(color = MaterialTheme.colorScheme.errorContainer, shape = MaterialTheme.shapes.extraSmall) {
                            Text(
                                "Inactive",
                                fontSize = 10.sp,
                                color    = MaterialTheme.colorScheme.onErrorContainer,
                                modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp)
                            )
                        }
                    }
                }

                val discountStr = if (dc.type == "percent") "${dc.value.toInt()}% off" else "£%.2f off".format(dc.value)
                val usesStr     = when {
                    dc.usesLimit == null || dc.usesLimit == 0 -> "Unlimited uses"
                    else -> "${dc.usesCount} / ${dc.usesLimit} used"
                }
                Text(discountStr, fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                Text(usesStr, fontSize = 12.sp, color = TextMuted)
                if (!dc.expiryDate.isNullOrBlank()) {
                    Text("Expires ${dc.expiryDate}", fontSize = 11.sp, color = TextMuted)
                }
            }

            if (toggling) {
                CircularProgressIndicator(
                    Modifier.size(24.dp).padding(4.dp),
                    strokeWidth = 2.dp,
                    color       = Primary
                )
            } else {
                Switch(
                    checked         = isActive,
                    onCheckedChange = { onToggle() },
                    enabled         = isOnline,
                    colors          = SwitchDefaults.colors(
                        checkedThumbColor = Primary,
                        checkedTrackColor = Primary.copy(alpha = 0.4f)
                    )
                )
            }
        }
    }
}
