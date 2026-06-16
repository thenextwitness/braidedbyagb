package com.braidedbyagb.admin.ui.screens.bookings

import android.app.DatePickerDialog
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.CalendarMonth
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
import com.braidedbyagb.admin.data.model.*
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.ui.theme.Gold
import com.braidedbyagb.admin.ui.theme.Primary
import com.braidedbyagb.admin.ui.theme.Purple800
import com.braidedbyagb.admin.ui.theme.TextMuted
import kotlinx.coroutines.launch
import java.util.*

private val TIME_SLOTS_CB = listOf(
    "09:00","09:30","10:00","10:30","11:00","11:30","12:00","12:30",
    "13:00","13:30","14:00","14:30","15:00","15:30","16:00","16:30"
)

private val PAYMENT_METHODS = mapOf(
    "bank_transfer" to "Bank Transfer",
    "stripe"        to "Card (Stripe)",
    "cash"          to "Cash"
)

private val PAYMENT_ALLOWED = mapOf(
    "both"          to "Both — Card & Bank Transfer",
    "stripe"        to "Card only (Stripe)",
    "bank_transfer" to "Bank Transfer only"
)

private val STATUSES = listOf("pending","confirmed","completed","cancelled")

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CreateBookingScreen(onBack: () -> Unit, onCreated: (Int) -> Unit) {
    val scope   = rememberCoroutineScope()
    val context = LocalContext.current

    // ── Load data ─────────────────────────────────────────────
    var services  by remember { mutableStateOf<List<Service>>(emptyList()) }
    var customers by remember { mutableStateOf<List<Customer>>(emptyList()) }
    var dataLoading by remember { mutableStateOf(true) }
    var dataError   by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(Unit) {
        try {
            val svcRes  = ApiClient.api.getServices()
            val custRes = ApiClient.api.getCustomers()
            if (svcRes.isSuccessful)  services  = svcRes.body()?.services   ?: emptyList()
            if (custRes.isSuccessful) customers = custRes.body()?.customers ?: emptyList()
        } catch (e: Exception) {
            dataError = "Failed to load — check connection"
        } finally { dataLoading = false }
    }

    // ── Form state ────────────────────────────────────────────
    // Client
    var clientTab     by remember { mutableIntStateOf(0) }   // 0=existing 1=new
    var custSearch    by remember { mutableStateOf("") }
    var selectedCust  by remember { mutableStateOf<Customer?>(null) }
    var newName       by remember { mutableStateOf("") }
    var newEmail      by remember { mutableStateOf("") }
    var newPhone      by remember { mutableStateOf("") }
    var showCustDrop  by remember { mutableStateOf(false) }

    // Service
    var isCustomStyle   by remember { mutableStateOf(false) }
    var customStyleName by remember { mutableStateOf("") }
    var customStyleDesc by remember { mutableStateOf("") }
    var selectedSvc     by remember { mutableStateOf<Service?>(null) }
    var selectedVariant by remember { mutableStateOf<ServiceVariant?>(null) }
    var selectedAddons  by remember { mutableStateOf<Set<Int>>(emptySet()) }
    var svcExpanded     by remember { mutableStateOf(false) }
    var varExpanded     by remember { mutableStateOf(false) }

    // Date & time
    var bookedDate by remember { mutableStateOf("") }
    var bookedTime by remember { mutableStateOf("") }

    // Payment
    var payMethod    by remember { mutableStateOf("bank_transfer") }
    var payAllowed   by remember { mutableStateOf("both") }
    var status       by remember { mutableStateOf("pending") }
    var depositPaid  by remember { mutableStateOf(false) }
    var manualPrice  by remember { mutableStateOf("") }
    var payExpanded  by remember { mutableStateOf(false) }
    var allowExpanded by remember { mutableStateOf(false) }
    var statusExpanded by remember { mutableStateOf(false) }

    // Notes
    var clientNotes by remember { mutableStateOf("") }
    var adminNotes  by remember { mutableStateOf("") }

    // Submission
    var submitting by remember { mutableStateOf(false) }
    var error      by remember { mutableStateOf<String?>(null) }

    val snackState = remember { SnackbarHostState() }
    LaunchedEffect(error) { error?.let { snackState.showSnackbar(it); error = null } }

    // ── Derived price ─────────────────────────────────────────
    val basePrice: Double = when {
        manualPrice.isNotBlank() && manualPrice.toDoubleOrNull() != null -> manualPrice.toDouble()
        isCustomStyle           -> 0.0          // custom style price must be set via manualPrice
        selectedVariant != null -> selectedVariant!!.price
        selectedSvc != null     -> selectedSvc!!.priceFrom
        else -> 0.0
    }
    val addonTotal = selectedSvc?.addons
        ?.filter { it.id in selectedAddons }
        ?.sumOf { it.price } ?: 0.0
    val totalPrice    = basePrice + addonTotal
    val depositAmount = totalPrice * 0.30   // 30% default (could load from settings)
    val balanceDue    = totalPrice - depositAmount

    // ── Date picker dialog ────────────────────────────────────
    fun openDatePicker() {
        val cal = Calendar.getInstance()
        DatePickerDialog(context,
            { _, y, m, d -> bookedDate = "%04d-%02d-%02d".format(y, m + 1, d) },
            cal.get(Calendar.YEAR), cal.get(Calendar.MONTH), cal.get(Calendar.DAY_OF_MONTH)
        ).also { it.datePicker.minDate = System.currentTimeMillis() - 1000 }.show()
    }

    // ── Customer filter ───────────────────────────────────────
    val filteredCustomers = if (custSearch.length >= 2) {
        val q = custSearch.lowercase()
        customers.filter { it.name.lowercase().contains(q) || it.email.lowercase().contains(q) }.take(8)
    } else emptyList()

    // ── Submit ────────────────────────────────────────────────
    fun submit() {
        val custMode = if (clientTab == 0) "existing" else "new"
        if (custMode == "existing" && selectedCust == null) { error = "Select an existing client"; return }
        if (custMode == "new" && (newName.isBlank() || newEmail.isBlank())) { error = "Name and email are required for a new client"; return }
        if (isCustomStyle && customStyleName.isBlank()) { error = "Enter the custom style name"; return }
        if (!isCustomStyle && selectedSvc == null) { error = "Select a service"; return }
        if (bookedDate.isBlank()) { error = "Select a date"; return }
        if (bookedTime.isBlank()) { error = "Select a time"; return }

        scope.launch {
            submitting = true
            try {
                val req = CreateBookingRequest(
                    customerMode         = custMode,
                    customerId           = if (custMode == "existing") selectedCust?.id else null,
                    newName              = if (custMode == "new") newName.trim() else null,
                    newEmail             = if (custMode == "new") newEmail.trim() else null,
                    newPhone             = if (custMode == "new") newPhone.trim().ifBlank { null } else null,
                    serviceId            = if (isCustomStyle) 0 else selectedSvc!!.id,
                    variantId            = if (isCustomStyle) null else selectedVariant?.id,
                    addonIds             = if (isCustomStyle) emptyList() else selectedAddons.toList(),
                    bookedDate           = bookedDate,
                    bookedTime           = bookedTime,
                    status               = status,
                    paymentMethod        = payMethod,
                    paymentMethodAllowed = payAllowed,
                    depositPaid          = depositPaid,
                    clientNotes          = clientNotes.trim().ifBlank { null },
                    adminNotes           = adminNotes.trim().ifBlank { null },
                    manualPrice          = manualPrice.toDoubleOrNull(),
                    isCustomStyle        = isCustomStyle,
                    customStyleName      = if (isCustomStyle) customStyleName.trim() else null,
                    customStyleDesc      = if (isCustomStyle) customStyleDesc.trim().ifBlank { null } else null
                )
                val res = ApiClient.api.createBooking(req)
                if (res.isSuccessful && res.body()?.success == true) {
                    onCreated(res.body()!!.id)
                } else {
                    error = "Failed to create booking. Check all fields."
                }
            } catch (e: Exception) {
                error = "Connection error — try again"
            } finally { submitting = false }
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("New Booking", fontWeight = FontWeight.Bold) },
                navigationIcon = { IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, null) } }
            )
        },
        snackbarHost = { SnackbarHost(snackState) }
    ) { pad ->
        if (dataLoading) {
            Box(Modifier.fillMaxSize().padding(pad), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Primary) }
        } else if (dataError != null) {
            Box(Modifier.fillMaxSize().padding(pad), contentAlignment = Alignment.Center) {
                Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Text(dataError!!, color = MaterialTheme.colorScheme.error)
                    Button(onClick = { /* trigger reload */ }) { Text("Retry") }
                }
            }
        } else {

        LazyColumn(
            modifier = Modifier.fillMaxSize().padding(pad),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {

            // ── CLIENT ───────────────────────────────────────
            item {
                SectionCard("👤 Client") {
                    TabRow(selectedTabIndex = clientTab, containerColor = MaterialTheme.colorScheme.surface) {
                        Tab(selected = clientTab == 0, onClick = { clientTab = 0; selectedCust = null; custSearch = "" }) { Text("Existing Client", Modifier.padding(10.dp), fontSize = 13.sp) }
                        Tab(selected = clientTab == 1, onClick = { clientTab = 1 }) { Text("+ New Client", Modifier.padding(10.dp), fontSize = 13.sp) }
                    }
                    Spacer(Modifier.height(12.dp))

                    if (clientTab == 0) {
                        // Existing client search
                        OutlinedTextField(
                            value = custSearch,
                            onValueChange = { custSearch = it; showCustDrop = it.length >= 2 },
                            label = { Text("Search name or email") },
                            singleLine = true,
                            modifier = Modifier.fillMaxWidth()
                        )
                        if (showCustDrop && filteredCustomers.isNotEmpty()) {
                            Card(Modifier.fillMaxWidth(), elevation = CardDefaults.cardElevation(4.dp)) {
                                filteredCustomers.forEach { c ->
                                    TextButton(
                                        onClick = { selectedCust = c; custSearch = c.name; showCustDrop = false },
                                        modifier = Modifier.fillMaxWidth()
                                    ) {
                                        Column(Modifier.fillMaxWidth()) {
                                            Text(c.name, fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
                                            Text(c.email ?: "", fontSize = 11.sp, color = TextMuted)
                                        }
                                    }
                                    HorizontalDivider()
                                }
                            }
                        }
                        if (selectedCust != null) {
                            Spacer(Modifier.height(8.dp))
                            Card(colors = CardDefaults.cardColors(containerColor = Color(0xFFF0FDF4))) {
                                Row(Modifier.padding(10.dp).fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                                    Column {
                                        Text(selectedCust!!.name, fontWeight = FontWeight.Bold, fontSize = 13.sp)
                                        Text(selectedCust!!.email ?: "", fontSize = 11.sp, color = TextMuted)
                                    }
                                    TextButton(onClick = { selectedCust = null; custSearch = "" }) { Text("✕ Clear", fontSize = 12.sp) }
                                }
                            }
                        }
                    } else {
                        // New client form
                        OutlinedTextField(value = newName, onValueChange = { newName = it }, label = { Text("Full Name *") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                        Spacer(Modifier.height(8.dp))
                        OutlinedTextField(value = newEmail, onValueChange = { newEmail = it }, label = { Text("Email *") }, singleLine = true, modifier = Modifier.fillMaxWidth(), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email))
                        Spacer(Modifier.height(8.dp))
                        OutlinedTextField(value = newPhone, onValueChange = { newPhone = it }, label = { Text("Phone") }, singleLine = true, modifier = Modifier.fillMaxWidth(), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone))
                    }
                }
            }

            // ── SERVICE ──────────────────────────────────────
            item {
                SectionCard("✂️ Service") {

                    // Custom Style toggle
                    Row(
                        Modifier.fillMaxWidth().padding(bottom = 8.dp),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text("Custom Style", fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
                            Text("For styles not in the service list", fontSize = 11.sp, color = TextMuted)
                        }
                        Switch(
                            checked = isCustomStyle,
                            onCheckedChange = {
                                isCustomStyle = it
                                if (it) { selectedSvc = null; selectedVariant = null; selectedAddons = emptySet() }
                                else { customStyleName = ""; customStyleDesc = "" }
                            },
                            colors = SwitchDefaults.colors(checkedThumbColor = Primary, checkedTrackColor = Primary.copy(alpha = 0.4f))
                        )
                    }

                    if (isCustomStyle) {
                        // Custom style fields
                        OutlinedTextField(
                            value = customStyleName,
                            onValueChange = { customStyleName = it },
                            label = { Text("Style Name *") },
                            placeholder = { Text("e.g. Senegalese Twists, Knotless Box Braids") },
                            singleLine = true,
                            modifier = Modifier.fillMaxWidth()
                        )
                        Spacer(Modifier.height(8.dp))
                        OutlinedTextField(
                            value = customStyleDesc,
                            onValueChange = { customStyleDesc = it },
                            label = { Text("Style Description (optional)") },
                            placeholder = { Text("Hair length, colour, specific instructions…") },
                            modifier = Modifier.fillMaxWidth(),
                            minLines = 2,
                            maxLines = 4
                        )
                        Spacer(Modifier.height(6.dp))
                        Text(
                            "Set the price using the Price Override field below.",
                            fontSize = 11.sp,
                            color = Primary,
                            modifier = Modifier.padding(horizontal = 4.dp)
                        )
                    } else {
                    // Service dropdown
                    ExposedDropdownMenuBox(expanded = svcExpanded, onExpandedChange = { svcExpanded = it }) {
                        OutlinedTextField(
                            value = selectedSvc?.name ?: "— Select a service —",
                            onValueChange = {}, readOnly = true,
                            label = { Text("Service") },
                            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(svcExpanded) },
                            modifier = Modifier.menuAnchor().fillMaxWidth()
                        )
                        ExposedDropdownMenu(expanded = svcExpanded, onDismissRequest = { svcExpanded = false }) {
                            services.forEach { svc ->
                                DropdownMenuItem(
                                    text = { Column {
                                        Text(svc.name, fontSize = 13.sp)
                                        Text("from £%.2f".format(svc.priceFrom), fontSize = 11.sp, color = TextMuted)
                                    }},
                                    onClick = {
                                        selectedSvc = svc; selectedVariant = null; selectedAddons = emptySet()
                                        svcExpanded = false
                                    }
                                )
                            }
                        }
                    }

                    // Variant dropdown
                    if (selectedSvc?.variants?.isNotEmpty() == true) {
                        Spacer(Modifier.height(10.dp))
                        ExposedDropdownMenuBox(expanded = varExpanded, onExpandedChange = { varExpanded = it }) {
                            OutlinedTextField(
                                value = selectedVariant?.variantName ?: "— Select variant —",
                                onValueChange = {}, readOnly = true,
                                label = { Text("Variant / Length") },
                                trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(varExpanded) },
                                modifier = Modifier.menuAnchor().fillMaxWidth()
                            )
                            ExposedDropdownMenu(expanded = varExpanded, onDismissRequest = { varExpanded = false }) {
                                DropdownMenuItem(text = { Text("— No variant —", color = TextMuted, fontSize = 13.sp) }, onClick = { selectedVariant = null; varExpanded = false })
                                selectedSvc!!.variants.forEach { v ->
                                    DropdownMenuItem(
                                        text = { Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                                            Text(v.variantName, fontSize = 13.sp)
                                            Text("£%.2f".format(v.price), fontSize = 13.sp, color = Primary)
                                        }},
                                        onClick = { selectedVariant = v; varExpanded = false }
                                    )
                                }
                            }
                        }
                    }

                    // Add-ons
                    if (selectedSvc?.addons?.isNotEmpty() == true) {
                        Spacer(Modifier.height(10.dp))
                        Text("Add-ons", fontSize = 12.sp, fontWeight = FontWeight.Medium, color = TextMuted)
                        Spacer(Modifier.height(6.dp))
                        selectedSvc!!.addons.forEach { addon ->
                            Row(
                                Modifier.fillMaxWidth().padding(vertical = 2.dp),
                                verticalAlignment = Alignment.CenterVertically
                            ) {
                                Checkbox(
                                    checked = addon.id in selectedAddons,
                                    onCheckedChange = { checked ->
                                        selectedAddons = if (checked) selectedAddons + addon.id else selectedAddons - addon.id
                                    },
                                    colors = CheckboxDefaults.colors(checkedColor = Primary)
                                )
                                Text(addon.name, Modifier.weight(1f), fontSize = 13.sp)
                                Text("+£%.2f".format(addon.price), fontSize = 13.sp, color = TextMuted)
                            }
                        }
                    }
                    } // end else (not isCustomStyle)
                }
            }

            // ── DATE & TIME ──────────────────────────────────
            item {
                SectionCard("📅 Date & Time") {
                    // Date picker
                    OutlinedTextField(
                        value = bookedDate,
                        onValueChange = {},
                        label = { Text("Date") },
                        placeholder = { Text("Tap to pick a date") },
                        readOnly = true,
                        trailingIcon = { Icon(Icons.Default.CalendarMonth, null, tint = Primary) },
                        modifier = Modifier.fillMaxWidth(),
                        enabled = true
                    )
                    // Overlay a clickable box since readOnly prevents the click propagating well
                    Box(Modifier.fillMaxWidth().height(0.dp)) {
                        // This is handled by the onClick on the outer clickable:
                    }
                    TextButton(onClick = { openDatePicker() }, modifier = Modifier.fillMaxWidth()) {
                        Text(if (bookedDate.isBlank()) "📅 Tap to select date" else "📅 $bookedDate — tap to change", color = Primary, fontSize = 13.sp)
                    }

                    Spacer(Modifier.height(8.dp))
                    Text("Time", fontSize = 12.sp, fontWeight = FontWeight.Medium, color = TextMuted)
                    Spacer(Modifier.height(6.dp))

                    // Time grid — 4 per row
                    TIME_SLOTS_CB.chunked(4).forEach { rowSlots ->
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            rowSlots.forEach { t ->
                                val isSelected = bookedTime == t
                                OutlinedButton(
                                    onClick = { bookedTime = t },
                                    modifier = Modifier.weight(1f),
                                    colors = ButtonDefaults.outlinedButtonColors(
                                        containerColor = if (isSelected) Primary else Color.Transparent,
                                        contentColor   = if (isSelected) Color.White else MaterialTheme.colorScheme.onSurface
                                    ),
                                    contentPadding = PaddingValues(4.dp)
                                ) { Text(t, fontSize = 11.sp, fontWeight = if (isSelected) FontWeight.Bold else FontWeight.Normal) }
                            }
                        }
                        Spacer(Modifier.height(4.dp))
                    }
                }
            }

            // ── PAYMENT ──────────────────────────────────────
            item {
                SectionCard("💳 Payment") {
                    // Payment method
                    ExposedDropdownMenuBox(expanded = payExpanded, onExpandedChange = { payExpanded = it }) {
                        OutlinedTextField(
                            value = PAYMENT_METHODS[payMethod] ?: payMethod,
                            onValueChange = {}, readOnly = true,
                            label = { Text("Payment Method") },
                            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(payExpanded) },
                            modifier = Modifier.menuAnchor().fillMaxWidth()
                        )
                        ExposedDropdownMenu(expanded = payExpanded, onDismissRequest = { payExpanded = false }) {
                            PAYMENT_METHODS.forEach { (k, v) ->
                                DropdownMenuItem(text = { Text(v, fontSize = 13.sp) }, onClick = { payMethod = k; payExpanded = false })
                            }
                        }
                    }

                    Spacer(Modifier.height(10.dp))

                    // Status
                    ExposedDropdownMenuBox(expanded = statusExpanded, onExpandedChange = { statusExpanded = it }) {
                        OutlinedTextField(
                            value = status.replaceFirstChar { it.uppercase() },
                            onValueChange = {}, readOnly = true,
                            label = { Text("Booking Status") },
                            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(statusExpanded) },
                            modifier = Modifier.menuAnchor().fillMaxWidth()
                        )
                        ExposedDropdownMenu(expanded = statusExpanded, onDismissRequest = { statusExpanded = false }) {
                            STATUSES.forEach { s ->
                                DropdownMenuItem(text = { Text(s.replaceFirstChar { it.uppercase() }, fontSize = 13.sp) }, onClick = { status = s; statusExpanded = false })
                            }
                        }
                    }

                    Spacer(Modifier.height(10.dp))

                    // Payment link options
                    ExposedDropdownMenuBox(expanded = allowExpanded, onExpandedChange = { allowExpanded = it }) {
                        OutlinedTextField(
                            value = PAYMENT_ALLOWED[payAllowed] ?: payAllowed,
                            onValueChange = {}, readOnly = true,
                            label = { Text("Payment Link Method") },
                            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(allowExpanded) },
                            modifier = Modifier.menuAnchor().fillMaxWidth()
                        )
                        ExposedDropdownMenu(expanded = allowExpanded, onDismissRequest = { allowExpanded = false }) {
                            PAYMENT_ALLOWED.forEach { (k, v) ->
                                DropdownMenuItem(text = { Text(v, fontSize = 13.sp) }, onClick = { payAllowed = k; allowExpanded = false })
                            }
                        }
                    }

                    Spacer(Modifier.height(10.dp))

                    // Deposit already paid toggle
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                        Checkbox(checked = depositPaid, onCheckedChange = { depositPaid = it }, colors = CheckboxDefaults.colors(checkedColor = Primary))
                        Text("Mark deposit as already paid", fontSize = 13.sp, modifier = Modifier.weight(1f))
                    }

                    Spacer(Modifier.height(10.dp))

                    // Manual price override
                    OutlinedTextField(
                        value = manualPrice,
                        onValueChange = { manualPrice = it },
                        label = { Text("Price Override (optional — leave blank for auto)") },
                        placeholder = { Text("£ Auto") },
                        prefix = { Text("£") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal)
                    )

                    // Price preview
                    if (totalPrice > 0) {
                        Spacer(Modifier.height(12.dp))
                        Card(colors = CardDefaults.cardColors(containerColor = Color(0xFFF5F0FF))) {
                            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                PriceRow("Service price", "£%.2f".format(basePrice))
                                if (addonTotal > 0) PriceRow("Add-ons", "+£%.2f".format(addonTotal))
                                HorizontalDivider(Modifier.padding(vertical = 4.dp))
                                PriceRow("Total", "£%.2f".format(totalPrice), bold = true, color = Primary)
                                PriceRow("Deposit (30%)", "£%.2f".format(depositAmount))
                                PriceRow("Balance on day", "£%.2f".format(balanceDue))
                            }
                        }
                    }
                }
            }

            // ── NOTES ────────────────────────────────────────
            item {
                SectionCard("📝 Notes") {
                    OutlinedTextField(
                        value = clientNotes,
                        onValueChange = { clientNotes = it },
                        label = { Text("Client Notes") },
                        placeholder = { Text("Hair type, style preferences, references…") },
                        modifier = Modifier.fillMaxWidth(),
                        minLines = 3,
                        maxLines = 5
                    )
                    Spacer(Modifier.height(10.dp))
                    OutlinedTextField(
                        value = adminNotes,
                        onValueChange = { adminNotes = it },
                        label = { Text("Admin Notes (internal only)") },
                        placeholder = { Text("Internal notes — not visible to client…") },
                        modifier = Modifier.fillMaxWidth(),
                        minLines = 2,
                        maxLines = 4
                    )
                }
            }

            // ── SUBMIT ───────────────────────────────────────
            item {
                Button(
                    onClick = { submit() },
                    enabled = !submitting,
                    modifier = Modifier.fillMaxWidth().height(52.dp),
                    colors = ButtonDefaults.buttonColors(containerColor = Primary)
                ) {
                    if (submitting) CircularProgressIndicator(Modifier.size(20.dp), color = Color.White, strokeWidth = 2.dp)
                    else Text("✓ Create Booking", fontWeight = FontWeight.Bold, fontSize = 15.sp)
                }
                Spacer(Modifier.height(8.dp))
            }
        }
        } // end else (data loaded)
    }
}

@Composable
private fun SectionCard(title: String, content: @Composable ColumnScope.() -> Unit) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(0.dp)) {
            Text(title, fontWeight = FontWeight.Bold, color = Purple800, fontSize = 13.sp)
            Spacer(Modifier.height(12.dp))
            content()
        }
    }
}

@Composable
private fun PriceRow(label: String, value: String, bold: Boolean = false, color: Color = Color.Unspecified) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
        Text(label, fontSize = 13.sp, color = TextMuted)
        Text(value, fontSize = 13.sp, fontWeight = if (bold) FontWeight.Bold else FontWeight.Normal,
             color = if (color == Color.Unspecified) MaterialTheme.colorScheme.onSurface else color)
    }
}
