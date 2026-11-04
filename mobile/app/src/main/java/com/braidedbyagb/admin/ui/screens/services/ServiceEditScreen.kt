package com.braidedbyagb.admin.ui.screens.services

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.foundation.shape.RoundedCornerShape
import coil.compose.AsyncImage
import com.braidedbyagb.admin.data.model.*
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.ui.theme.Primary
import com.braidedbyagb.admin.ui.theme.Purple800
import com.braidedbyagb.admin.ui.theme.TextMuted
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody

private const val SITE = "https://braidedbyagb.co.uk"

/**
 * Unified create / edit screen for a service.
 * serviceId == null  → create mode
 * serviceId != null  → edit mode (loads the service on enter)
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ServiceEditScreen(
    serviceId: Int?,
    onBack:    () -> Unit,
    onSaved:   (Int) -> Unit    // called with the service id after create/update
) {
    val isNew  = serviceId == null
    val scope  = rememberCoroutineScope()
    val snack  = remember { SnackbarHostState() }

    // ── Form state ────────────────────────────────────────
    var name         by remember { mutableStateOf("") }
    var description  by remember { mutableStateOf("") }
    var priceFrom    by remember { mutableStateOf("") }
    var durationMins by remember { mutableStateOf("60") }
    var category     by remember { mutableStateOf("") }
    var prepNotes    by remember { mutableStateOf("") }
    var rebookWeeks  by remember { mutableStateOf("") }
    var showNewBadge by remember { mutableStateOf(false) }
    var imageUrl     by remember { mutableStateOf<String?>(null) }
    var uploading    by remember { mutableStateOf(false) }
    // Set when an existing service couldn't be loaded. Saving is then blocked, so a
    // half-empty form can never overwrite the real service.
    var loadError    by remember { mutableStateOf<String?>(null) }
    val context      = LocalContext.current

    // Variant + add-on lists (loaded from API when editing)
    var variants     by remember { mutableStateOf<List<ServiceVariantFull>>(emptyList()) }
    var addons       by remember { mutableStateOf<List<ServiceAddonFull>>(emptyList()) }
    var savedId      by remember { mutableStateOf(serviceId) }   // non-null once created

    var loading  by remember { mutableStateOf(!isNew) }
    var saving   by remember { mutableStateOf(false) }

    // Inline "add variant" form
    var showAddVariant  by remember { mutableStateOf(false) }
    var variantName     by remember { mutableStateOf("") }
    var variantPrice    by remember { mutableStateOf("") }
    var variantDuration by remember { mutableStateOf("") }
    var savingVariant   by remember { mutableStateOf(false) }

    // Inline "add add-on" form
    var showAddAddon  by remember { mutableStateOf(false) }
    var addonName     by remember { mutableStateOf("") }
    var addonPrice    by remember { mutableStateOf("") }
    var savingAddon   by remember { mutableStateOf(false) }

    // ── Load existing service ─────────────────────────────
    LaunchedEffect(serviceId) {
        if (serviceId == null) return@LaunchedEffect
        try {
            val res = ApiClient.api.getServicesAdmin()
            if (res.isSuccessful) {
                val svc = res.body()?.services?.find { it.id == serviceId }
                if (svc != null) {
                    name         = svc.name
                    description  = svc.description ?: ""
                    priceFrom    = svc.priceFrom.toString()
                    durationMins = svc.durationMins.toString()
                    category     = svc.category ?: ""
                    prepNotes    = svc.prepNotes ?: ""
                    rebookWeeks  = svc.rebookWeeks?.toString() ?: ""
                    showNewBadge = svc.isNew == 1
                    imageUrl     = svc.imageUrl?.takeIf { it.isNotBlank() }
                    variants     = svc.variants
                    addons       = svc.addons      // per-service add-ons
                } else {
                    loadError = "This service no longer exists on the website."
                }
            } else {
                loadError = "Couldn't load this service (error ${res.code()}). Go back and try again."
            }
        } catch (_: Exception) {
            loadError = "Couldn't load this service. Check your connection, then go back and try again."
        }
        loading = false
    }

    // ── Save service details ──────────────────────────────
    fun saveService() {
        val price = priceFrom.toDoubleOrNull() ?: 0.0
        val dur   = durationMins.toIntOrNull() ?: 60
        if (name.isBlank()) { scope.launch { snack.showSnackbar("Name is required") }; return }
        if (loadError != null) { scope.launch { snack.showSnackbar("Not saved: $loadError") }; return }

        scope.launch {
            saving = true
            try {
                if (savedId == null) {
                    // Create
                    val res = ApiClient.api.createService(
                        ServiceCreateRequest(
                            name         = name.trim(),
                            description  = description.trim().takeIf { it.isNotBlank() },
                            priceFrom    = price,
                            durationMins = dur,
                            category     = category.trim().takeIf { it.isNotBlank() }
                        )
                    )
                    if (res.isSuccessful && res.body()?.success == true) {
                        val newId = res.body()!!.id!!
                        savedId = newId
                        snack.showSnackbar("Service created")
                        onSaved(newId)
                    } else {
                        snack.showSnackbar("Failed to create service")
                    }
                } else {
                    // Update
                    val res = ApiClient.api.updateService(
                        id  = savedId!!,
                        req = ServiceUpdateRequest(
                            name         = name.trim(),
                            description  = description.trim().takeIf { it.isNotBlank() },
                            priceFrom    = price,
                            durationMins = dur,
                            category     = category.trim().takeIf { it.isNotBlank() },
                            prepNotes    = prepNotes.trim(),
                            isNew        = showNewBadge,
                            rebookWeeks  = rebookWeeks.trim()
                        )
                    )
                    if (res.isSuccessful) {
                        snack.showSnackbar("Saved")
                        onSaved(savedId!!)
                    } else if (res.code() == 404) {
                        snack.showSnackbar("Not saved: this service no longer exists on the website.")
                    } else {
                        snack.showSnackbar("Not saved (error ${res.code()}). Nothing was changed.")
                    }
                }
            } catch (_: Exception) { snack.showSnackbar("Connection error") }
            saving = false
        }
    }

    // ── Service photo ─────────────────────────────────────
    fun uploadPhoto(uri: Uri) {
        val id = savedId ?: return
        scope.launch {
            uploading = true
            try {
                val bytes = withContext(Dispatchers.IO) {
                    context.contentResolver.openInputStream(uri)?.use { it.readBytes() }
                }
                if (bytes == null) { snack.showSnackbar("Couldn't read that photo."); uploading = false; return@launch }
                if (bytes.size > 5 * 1024 * 1024) { snack.showSnackbar("Not uploaded: photo is over 5 MB."); uploading = false; return@launch }
                val mime = context.contentResolver.getType(uri) ?: "image/jpeg"
                val part = MultipartBody.Part.createFormData("image", "service-photo", bytes.toRequestBody(mime.toMediaTypeOrNull()))
                val res  = ApiClient.api.uploadServiceImage(id, part)
                if (res.isSuccessful && res.body()?.imageUrl != null) {
                    imageUrl = res.body()!!.imageUrl
                    snack.showSnackbar("Photo saved")
                } else {
                    snack.showSnackbar("Photo not saved (error ${res.code()}). Use a JPG, PNG or WebP under 5 MB.")
                }
            } catch (_: Exception) { snack.showSnackbar("Photo not saved: connection error") }
            uploading = false
        }
    }
    val photoPicker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri != null) uploadPhoto(uri)
    }

    // ── Add variant ───────────────────────────────────────
    fun submitVariant() {
        val id = savedId ?: return
        val price = variantPrice.toDoubleOrNull() ?: 0.0
        val dur   = variantDuration.toIntOrNull()
        if (variantName.isBlank()) { scope.launch { snack.showSnackbar("Variant name required") }; return }
        scope.launch {
            savingVariant = true
            try {
                val res = ApiClient.api.addVariant(
                    serviceId = id,
                    req       = VariantCreateRequest(variantName.trim(), price, dur)
                )
                if (res.isSuccessful && res.body()?.success == true) {
                    val newId = res.body()!!.id!!
                    variants = variants + ServiceVariantFull(newId, id, variantName.trim(), price, dur)
                    variantName = ""; variantPrice = ""; variantDuration = ""
                    showAddVariant = false
                } else {
                    snack.showSnackbar("Failed to add variant")
                }
            } catch (_: Exception) { snack.showSnackbar("Connection error") }
            savingVariant = false
        }
    }

    // ── Delete variant ────────────────────────────────────
    fun deleteVariant(vid: Int) {
        scope.launch {
            try {
                val res = ApiClient.api.deleteVariant(vid)
                if (res.isSuccessful) variants = variants.filter { it.id != vid }
                else snack.showSnackbar("Failed to delete variant")
            } catch (_: Exception) { snack.showSnackbar("Connection error") }
        }
    }

    // ── Add add-on (belongs to this service) ──────────────
    fun submitAddon() {
        val sid = savedId ?: return
        val price = addonPrice.toDoubleOrNull() ?: 0.0
        if (addonName.isBlank()) { scope.launch { snack.showSnackbar("Add-on name required") }; return }
        scope.launch {
            savingAddon = true
            try {
                val res = ApiClient.api.addAddon(
                    req = AddonCreateRequest(sid, addonName.trim(), price)
                )
                if (res.isSuccessful && res.body()?.success == true) {
                    val newId = res.body()!!.id!!
                    addons = addons + ServiceAddonFull(newId, sid, addonName.trim(), price)
                    addonName = ""; addonPrice = ""
                    showAddAddon = false
                } else {
                    snack.showSnackbar("Failed to add add-on")
                }
            } catch (_: Exception) { snack.showSnackbar("Connection error") }
            savingAddon = false
        }
    }

    // ── Edit add-on in place (name / price) ───────────────
    fun updateAddon(aid: Int, newName: String, newPrice: Double) {
        if (newName.isBlank()) { scope.launch { snack.showSnackbar("Add-on name required") }; return }
        scope.launch {
            try {
                val res = ApiClient.api.updateAddon(AddonUpdateRequest(aid, newName.trim(), newPrice))
                if (res.isSuccessful) {
                    addons = addons.map { if (it.id == aid) it.copy(name = newName.trim(), price = newPrice) else it }
                    snack.showSnackbar("Add-on updated")
                } else snack.showSnackbar("Failed to update add-on")
            } catch (_: Exception) { snack.showSnackbar("Connection error") }
        }
    }

    // ── Delete add-on ─────────────────────────────────────
    fun deleteAddon(aid: Int) {
        scope.launch {
            try {
                val res = ApiClient.api.deleteAddon(aid)
                if (res.isSuccessful) addons = addons.filter { it.id != aid }
                else snack.showSnackbar("Failed to delete add-on")
            } catch (_: Exception) { snack.showSnackbar("Connection error") }
        }
    }

    // ── UI ────────────────────────────────────────────────
    Scaffold(
        snackbarHost = { SnackbarHost(snack) },
        topBar = {
            TopAppBar(
                title = { Text(if (isNew) "New Service" else "Edit Service", fontWeight = FontWeight.Bold, color = Purple800) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.Default.ArrowBack, "Back")
                    }
                }
            )
        }
    ) { padding ->
        if (loading) {
            Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                CircularProgressIndicator(color = Primary)
            }
        } else if (loadError != null) {
            Box(Modifier.fillMaxSize().padding(padding).padding(24.dp), contentAlignment = Alignment.Center) {
                Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Text(loadError ?: "", color = Purple800, fontWeight = FontWeight.SemiBold)
                    OutlinedButton(onClick = onBack) { Text("Back to services") }
                }
            }
        } else {

        LazyColumn(
            modifier       = Modifier.fillMaxSize().padding(padding),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp)
        ) {
            // ── Core fields ──────────────────────────────
            item {
                SectionHeader("Service Details")
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {

                        OutlinedTextField(
                            value         = name,
                            onValueChange = { name = it },
                            label         = { Text("Service Name *") },
                            singleLine    = true,
                            modifier      = Modifier.fillMaxWidth(),
                            keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Words)
                        )

                        OutlinedTextField(
                            value         = category,
                            onValueChange = { category = it },
                            label         = { Text("Category") },
                            placeholder   = { Text("e.g. Braiding, Extensions…", fontSize = 12.sp) },
                            singleLine    = true,
                            modifier      = Modifier.fillMaxWidth(),
                            keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Words)
                        )

                        OutlinedTextField(
                            value         = description,
                            onValueChange = { description = it },
                            label         = { Text("Description") },
                            modifier      = Modifier.fillMaxWidth(),
                            minLines      = 2,
                            maxLines      = 5
                        )

                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            OutlinedTextField(
                                value         = priceFrom,
                                onValueChange = { priceFrom = it },
                                label         = { Text("Price From (£)") },
                                singleLine    = true,
                                modifier      = Modifier.weight(1f),
                                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                                prefix        = { Text("£") }
                            )
                            OutlinedTextField(
                                value         = durationMins,
                                onValueChange = { durationMins = it },
                                label         = { Text("Duration (min)") },
                                singleLine    = true,
                                modifier      = Modifier.weight(1f),
                                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number)
                            )
                        }

                        OutlinedTextField(
                            value         = prepNotes,
                            onValueChange = { prepNotes = it },
                            label         = { Text("Prep notes (shown to clients)") },
                            modifier      = Modifier.fillMaxWidth(),
                            minLines      = 2,
                            maxLines      = 5
                        )

                        OutlinedTextField(
                            value         = rebookWeeks,
                            onValueChange = { v -> rebookWeeks = v.filter { it.isDigit() }.take(2) },
                            label         = { Text("Rebook reminder (weeks)") },
                            supportingText = { Text("Blank = default from Settings. 0 = never remind.", fontSize = 11.sp) },
                            singleLine    = true,
                            modifier      = Modifier.fillMaxWidth(),
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number)
                        )

                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Checkbox(checked = showNewBadge, onCheckedChange = { showNewBadge = it })
                            Text("Show the \"New\" badge on the website")
                        }
                    }
                }
            }

            // ── Photo (once the service exists) ──────────
            if (savedId != null) {
                item {
                    SectionHeader("Photo")
                    Card(Modifier.fillMaxWidth()) {
                        Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                            val url = imageUrl
                            if (url != null) {
                                AsyncImage(
                                    model = if (url.startsWith("http")) url else SITE + url,
                                    contentDescription = "Service photo",
                                    contentScale = ContentScale.Crop,
                                    modifier = Modifier.size(84.dp).clip(RoundedCornerShape(8.dp))
                                )
                            } else {
                                Text("No photo yet", color = TextMuted, fontSize = 13.sp, modifier = Modifier.width(84.dp))
                            }
                            Button(
                                onClick = { photoPicker.launch("image/*") },
                                enabled = !uploading,
                                colors  = ButtonDefaults.buttonColors(containerColor = Primary)
                            ) {
                                if (uploading) CircularProgressIndicator(Modifier.size(18.dp), color = Color.White, strokeWidth = 2.dp)
                                else Text(if (url != null) "Change photo" else "Add photo")
                            }
                        }
                    }
                }
            }

            // ── Save button ──────────────────────────────
            item {
                Button(
                    onClick  = { saveService() },
                    enabled  = !saving,
                    modifier = Modifier.fillMaxWidth(),
                    colors   = ButtonDefaults.buttonColors(containerColor = Primary)
                ) {
                    if (saving) CircularProgressIndicator(Modifier.size(18.dp), color = Color.White, strokeWidth = 2.dp)
                    else Text(if (isNew) "Create Service" else "Save Changes")
                }
            }

            // Variants and add-ons only shown once the service exists
            if (savedId != null) {

                // ── Variants ──────────────────────────────
                item { SectionHeader("Pricing Variants") }

                if (variants.isEmpty()) {
                    item {
                        Text(
                            "No variants yet. Add variants for different sizes/lengths.",
                            fontSize = 13.sp, color = TextMuted
                        )
                    }
                }

                items(variants, key = { "v${it.id}" }) { v ->
                    Card(Modifier.fillMaxWidth()) {
                        Row(
                            Modifier.padding(horizontal = 14.dp, vertical = 10.dp),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.SpaceBetween
                        ) {
                            Column(Modifier.weight(1f)) {
                                Text(v.variantName, fontWeight = FontWeight.Medium, fontSize = 14.sp)
                                Text(
                                    "£%.2f${v.durationMins?.let { " • $it min" } ?: ""}".format(v.price),
                                    fontSize = 12.sp, color = TextMuted
                                )
                            }
                            IconButton(onClick = { deleteVariant(v.id) }) {
                                Icon(Icons.Default.Delete, "Delete", tint = MaterialTheme.colorScheme.error, modifier = Modifier.size(18.dp))
                            }
                        }
                    }
                }

                // Add variant form / button
                item {
                    if (!showAddVariant) {
                        OutlinedButton(
                            onClick  = { showAddVariant = true },
                            modifier = Modifier.fillMaxWidth()
                        ) { Text("+ Add Variant") }
                    } else {
                        Card(Modifier.fillMaxWidth()) {
                            Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                                Text("New Variant", fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
                                OutlinedTextField(
                                    value         = variantName,
                                    onValueChange = { variantName = it },
                                    label         = { Text("Variant Name *") },
                                    singleLine    = true,
                                    modifier      = Modifier.fillMaxWidth()
                                )
                                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    OutlinedTextField(
                                        value         = variantPrice,
                                        onValueChange = { variantPrice = it },
                                        label         = { Text("Price (£)") },
                                        singleLine    = true,
                                        modifier      = Modifier.weight(1f),
                                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                                        prefix        = { Text("£") }
                                    )
                                    OutlinedTextField(
                                        value         = variantDuration,
                                        onValueChange = { variantDuration = it },
                                        label         = { Text("Duration (min)") },
                                        singleLine    = true,
                                        modifier      = Modifier.weight(1f),
                                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number)
                                    )
                                }
                                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    Button(
                                        onClick  = { submitVariant() },
                                        enabled  = !savingVariant,
                                        modifier = Modifier.weight(1f),
                                        colors   = ButtonDefaults.buttonColors(containerColor = Primary)
                                    ) {
                                        if (savingVariant) CircularProgressIndicator(Modifier.size(14.dp), color = Color.White, strokeWidth = 2.dp)
                                        else Text("Add")
                                    }
                                    OutlinedButton(
                                        onClick  = { showAddVariant = false; variantName = ""; variantPrice = ""; variantDuration = "" },
                                        modifier = Modifier.weight(1f)
                                    ) { Text("Cancel") }
                                }
                            }
                        }
                    }
                }

                // ── Add-ons (this service only) ───────────
                item { SectionHeader("Add-ons for this service") }

                if (addons.isEmpty()) {
                    item {
                        Text(
                            "No add-ons yet. Add-ons created here apply only to this service and have their own prices.",
                            fontSize = 13.sp, color = TextMuted
                        )
                    }
                }

                items(addons, key = { "a${it.id}" }) { a ->
                    AddonEditRow(
                        addon    = a,
                        onSave   = { newName, newPrice -> updateAddon(a.id, newName, newPrice) },
                        onDelete = { deleteAddon(a.id) }
                    )
                }

                // Add add-on form / button
                item {
                    if (!showAddAddon) {
                        OutlinedButton(
                            onClick  = { showAddAddon = true },
                            modifier = Modifier.fillMaxWidth()
                        ) { Text("+ Add Add-on") }
                    } else {
                        Card(Modifier.fillMaxWidth()) {
                            Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                                Text("New Add-on", fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
                                OutlinedTextField(
                                    value         = addonName,
                                    onValueChange = { addonName = it },
                                    label         = { Text("Add-on Name *") },
                                    singleLine    = true,
                                    modifier      = Modifier.fillMaxWidth()
                                )
                                OutlinedTextField(
                                    value         = addonPrice,
                                    onValueChange = { addonPrice = it },
                                    label         = { Text("Price (£)") },
                                    singleLine    = true,
                                    modifier      = Modifier.fillMaxWidth(),
                                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                                    prefix        = { Text("£") }
                                )
                                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    Button(
                                        onClick  = { submitAddon() },
                                        enabled  = !savingAddon,
                                        modifier = Modifier.weight(1f),
                                        colors   = ButtonDefaults.buttonColors(containerColor = Primary)
                                    ) {
                                        if (savingAddon) CircularProgressIndicator(Modifier.size(14.dp), color = Color.White, strokeWidth = 2.dp)
                                        else Text("Add")
                                    }
                                    OutlinedButton(
                                        onClick  = { showAddAddon = false; addonName = ""; addonPrice = "" },
                                        modifier = Modifier.weight(1f)
                                    ) { Text("Cancel") }
                                }
                            }
                        }
                    }
                }
            } // end if savedId != null
        }
        } // end else (not loading)
    }
}

@Composable
private fun SectionHeader(title: String) {
    Text(
        title,
        fontWeight = FontWeight.Bold,
        fontSize   = 13.sp,
        color      = Primary,
        modifier   = Modifier.padding(top = 4.dp, bottom = 2.dp)
    )
}

/**
 * Editable add-on row — change the name or price and press ✓ to save in place
 * (no delete-and-recreate). The save button enables only when something changed.
 */
@Composable
private fun AddonEditRow(
    addon: ServiceAddonFull,
    onSave: (String, Double) -> Unit,
    onDelete: () -> Unit
) {
    var name  by remember(addon.id) { mutableStateOf(addon.name) }
    var price by remember(addon.id) { mutableStateOf("%.2f".format(addon.price)) }
    val parsedPrice = price.toDoubleOrNull()
    val dirty = name.trim() != addon.name || (parsedPrice != null && parsedPrice != addon.price)
    val canSave = dirty && name.isNotBlank() && parsedPrice != null

    Card(Modifier.fillMaxWidth()) {
        Row(
            Modifier.padding(horizontal = 14.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            OutlinedTextField(
                value         = name,
                onValueChange = { name = it },
                label         = { Text("Name") },
                singleLine    = true,
                modifier      = Modifier.weight(1f)
            )
            OutlinedTextField(
                value         = price,
                onValueChange = { price = it },
                label         = { Text("Price") },
                singleLine    = true,
                modifier      = Modifier.width(110.dp),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                prefix        = { Text("£") }
            )
            IconButton(onClick = { onSave(name, parsedPrice ?: addon.price) }, enabled = canSave) {
                Icon(
                    Icons.Default.Check, "Save",
                    tint = if (canSave) Primary else TextMuted,
                    modifier = Modifier.size(20.dp)
                )
            }
            IconButton(onClick = onDelete) {
                Icon(Icons.Default.Delete, "Delete", tint = MaterialTheme.colorScheme.error, modifier = Modifier.size(18.dp))
            }
        }
    }
}
