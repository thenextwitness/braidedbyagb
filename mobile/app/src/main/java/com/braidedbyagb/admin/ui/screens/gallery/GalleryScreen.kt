package com.braidedbyagb.admin.ui.screens.gallery

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.braidedbyagb.admin.data.model.GalleryImage
import com.braidedbyagb.admin.data.model.GalleryServiceOption
import com.braidedbyagb.admin.data.model.GalleryUpdateRequest
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.ui.theme.Primary
import com.braidedbyagb.admin.ui.theme.Purple800
import com.braidedbyagb.admin.ui.theme.TextMuted
import com.braidedbyagb.admin.utils.NetworkUtils
import com.braidedbyagb.admin.utils.OfflineBanner
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody

private const val SITE = "https://braidedbyagb.co.uk"

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun GalleryScreen(onBack: () -> Unit) {
    val context  = LocalContext.current
    val scope    = rememberCoroutineScope()
    val isOnline = remember { NetworkUtils.isOnline(context) }

    var images   by remember { mutableStateOf<List<GalleryImage>>(emptyList()) }
    var services by remember { mutableStateOf<List<GalleryServiceOption>>(emptyList()) }
    var loading  by remember { mutableStateOf(true) }
    var error    by remember { mutableStateOf<String?>(null) }
    var pendingUri by remember { mutableStateOf<Uri?>(null) }
    var editing  by remember { mutableStateOf<GalleryImage?>(null) }
    var uploading by remember { mutableStateOf(false) }
    val snackState = remember { SnackbarHostState() }

    suspend fun load() {
        try {
            val res = ApiClient.api.getGallery()
            if (res.isSuccessful && res.body() != null) {
                images = res.body()!!.images
                services = res.body()!!.services
                error = null
            } else if (images.isEmpty()) error = "Failed to load gallery"
        } catch (_: Exception) { if (images.isEmpty()) error = "Connection error" }
    }
    LaunchedEffect(Unit) { load(); loading = false }

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri != null) pendingUri = uri
    }

    fun fullUrl(path: String) = if (path.startsWith("http")) path else SITE + path

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Gallery", fontWeight = FontWeight.Bold) },
                navigationIcon = { IconButton(onClick = onBack) { Text("‹", fontSize = 26.sp, color = Primary) } }
            )
        },
        snackbarHost = { SnackbarHost(snackState) },
        floatingActionButton = {
            FloatingActionButton(
                onClick = { if (isOnline) picker.launch("image/*") else scope.launch { snackState.showSnackbar("You're offline.") } },
                containerColor = if (isOnline) Primary else Color(0xFF9CA3AF),
                contentColor = Color.White
            ) { Icon(Icons.Default.Add, "Add photo") }
        }
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            if (!isOnline) OfflineBanner()
            when {
                loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Primary) }
                error != null && images.isEmpty() ->
                    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { Text(error!!, color = MaterialTheme.colorScheme.error) }
                else -> LazyVerticalGrid(
                    columns = GridCells.Fixed(2),
                    modifier = Modifier.fillMaxSize(),
                    contentPadding = PaddingValues(12.dp),
                    horizontalArrangement = Arrangement.spacedBy(10.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp)
                ) {
                    if (images.isEmpty()) item { Text("No photos yet. Tap + to upload.", color = TextMuted, fontSize = 14.sp) }
                    items(images, key = { it.id }) { img ->
                        Card(
                            Modifier.fillMaxWidth(),
                            colors = CardDefaults.cardColors(containerColor = if (img.isActive == 1) MaterialTheme.colorScheme.surface else MaterialTheme.colorScheme.surfaceVariant)
                        ) {
                            Column(Modifier.clickable(enabled = isOnline) { editing = img }) {
                                AsyncImage(
                                    model = fullUrl(img.imageUrl),
                                    contentDescription = img.caption ?: "Gallery image",
                                    contentScale = ContentScale.Crop,
                                    modifier = Modifier.fillMaxWidth().height(150.dp)
                                )
                                Column(Modifier.padding(8.dp)) {
                                    Text(img.caption?.takeIf { it.isNotBlank() } ?: "(no caption)",
                                        fontSize = 12.sp, color = Purple800, maxLines = 2)
                                    Text(
                                        (img.serviceName ?: "General") + (if (img.isActive == 1) "" else " · hidden"),
                                        fontSize = 11.sp, color = TextMuted
                                    )
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    // ── Add (after picking a photo) ───────────────────────────
    pendingUri?.let { uri ->
        GalleryFormDialog(
            title = "New photo",
            services = services,
            initialServiceId = null,
            initialCaption = "",
            initialActive = true,
            showActive = false,
            busy = uploading,
            onDismiss = { if (!uploading) pendingUri = null },
            onDelete = null,
            onSave = { serviceId, caption, _ ->
                scope.launch {
                    uploading = true
                    try {
                        val bytes = withContext(Dispatchers.IO) {
                            context.contentResolver.openInputStream(uri)?.use { it.readBytes() }
                        }
                        if (bytes == null) { snackState.showSnackbar("Couldn't read that image."); uploading = false; return@launch }
                        val body = bytes.toRequestBody("image/*".toMediaTypeOrNull())
                        val part = MultipartBody.Part.createFormData("image", "upload.jpg", body)
                        val sid  = serviceId?.let { it.toString().toRequestBody("text/plain".toMediaTypeOrNull()) }
                        val cap  = caption.toRequestBody("text/plain".toMediaTypeOrNull())
                        val res  = ApiClient.api.uploadGalleryImage(part, sid, cap)
                        if (res.isSuccessful) { pendingUri = null; snackState.showSnackbar("Photo uploaded"); load() }
                        else snackState.showSnackbar("Upload failed (${res.code()})")
                    } catch (_: Exception) { snackState.showSnackbar("Connection error") }
                    uploading = false
                }
            }
        )
    }

    // ── Edit existing ─────────────────────────────────────────
    editing?.let { img ->
        GalleryFormDialog(
            title = "Edit photo",
            services = services,
            initialServiceId = img.serviceId,
            initialCaption = img.caption ?: "",
            initialActive = img.isActive == 1,
            showActive = true,
            busy = false,
            onDismiss = { editing = null },
            onDelete = {
                scope.launch {
                    try {
                        val res = ApiClient.api.deleteGalleryImage(img.id)
                        if (res.isSuccessful) { editing = null; snackState.showSnackbar("Deleted"); load() }
                        else snackState.showSnackbar("Delete failed")
                    } catch (_: Exception) { snackState.showSnackbar("Connection error") }
                }
            },
            onSave = { serviceId, caption, active ->
                scope.launch {
                    try {
                        val res = ApiClient.api.updateGalleryImage(img.id,
                            GalleryUpdateRequest(serviceId = serviceId, caption = caption, isActive = active, displayOrder = img.displayOrder))
                        if (res.isSuccessful) { editing = null; snackState.showSnackbar("Saved"); load() }
                        else snackState.showSnackbar("Save failed")
                    } catch (_: Exception) { snackState.showSnackbar("Connection error") }
                }
            }
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun GalleryFormDialog(
    title: String,
    services: List<GalleryServiceOption>,
    initialServiceId: Int?,
    initialCaption: String,
    initialActive: Boolean,
    showActive: Boolean,
    busy: Boolean,
    onDismiss: () -> Unit,
    onDelete: (() -> Unit)?,
    onSave: (serviceId: Int?, caption: String, active: Boolean) -> Unit
) {
    var serviceId by remember { mutableStateOf(initialServiceId) }
    var caption   by remember { mutableStateOf(initialCaption) }
    var active    by remember { mutableStateOf(initialActive) }
    var menu      by remember { mutableStateOf(false) }
    val serviceName = services.firstOrNull { it.id == serviceId }?.name ?: "General (no service)"

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                ExposedDropdownMenuBox(expanded = menu, onExpandedChange = { menu = !menu }) {
                    OutlinedTextField(
                        value = serviceName, onValueChange = {}, readOnly = true, label = { Text("Service") },
                        trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = menu) },
                        modifier = Modifier.menuAnchor().fillMaxWidth()
                    )
                    ExposedDropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                        DropdownMenuItem(text = { Text("General (no service)") }, onClick = { serviceId = null; menu = false })
                        services.forEach { s -> DropdownMenuItem(text = { Text(s.name) }, onClick = { serviceId = s.id; menu = false }) }
                    }
                }
                OutlinedTextField(caption, { caption = it }, label = { Text("Caption") }, modifier = Modifier.fillMaxWidth())
                if (showActive) Row(verticalAlignment = Alignment.CenterVertically) {
                    Checkbox(active, { active = it }); Text("Live on website")
                }
                if (busy) Row(verticalAlignment = Alignment.CenterVertically) {
                    CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp, color = Primary)
                    Spacer(Modifier.width(8.dp)); Text("Uploading…", fontSize = 12.sp, color = TextMuted)
                }
            }
        },
        confirmButton = { TextButton(enabled = !busy, onClick = { onSave(serviceId, caption.trim(), active) }) { Text("Save") } },
        dismissButton = {
            Row {
                if (onDelete != null) TextButton(onClick = onDelete) { Text("Delete", color = Color(0xFFDC2626)) }
                TextButton(enabled = !busy, onClick = onDismiss) { Text("Cancel") }
            }
        }
    )
}
