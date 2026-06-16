package com.braidedbyagb.admin.ui.screens.requests

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
import com.braidedbyagb.admin.data.model.CustomRequest
import com.braidedbyagb.admin.data.model.ReplyRequest
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.ui.theme.Primary
import com.braidedbyagb.admin.ui.theme.Purple800
import com.braidedbyagb.admin.ui.theme.TextMuted
import com.braidedbyagb.admin.utils.NetworkUtils
import com.braidedbyagb.admin.utils.OfflineBanner
import kotlinx.coroutines.launch

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CustomRequestsScreen() {
    val context  = LocalContext.current
    val scope    = rememberCoroutineScope()
    val isOnline = remember { NetworkUtils.isOnline(context) }

    var requests    by remember { mutableStateOf<List<CustomRequest>>(emptyList()) }
    var loading     by remember { mutableStateOf(true) }
    var selectedTab by remember { mutableStateOf(0) }
    var expandedId  by remember { mutableStateOf<Int?>(null) }
    var actioningId by remember { mutableStateOf<Int?>(null) }
    var replyingTo  by remember { mutableStateOf<CustomRequest?>(null) }
    var replyText   by remember { mutableStateOf("") }
    var sending     by remember { mutableStateOf(false) }
    val snackState  = remember { SnackbarHostState() }

    suspend fun load() {
        try {
            val res = ApiClient.api.getCustomRequests()
            if (res.isSuccessful && res.body() != null) {
                requests = res.body()!!.requests
            }
        } catch (_: Exception) {}
        loading = false
    }

    LaunchedEffect(Unit) { load() }

    fun markViewed(req: CustomRequest) {
        if (!isOnline) { scope.launch { snackState.showSnackbar("You're offline.") }; return }
        scope.launch {
            actioningId = req.id
            try {
                val res = ApiClient.api.markRequestViewed(req.id)
                if (res.isSuccessful) {
                    requests = requests.map { if (it.id == req.id) it.copy(status = "viewed") else it }
                }
            } catch (_: Exception) { snackState.showSnackbar("Connection error") }
            actioningId = null
        }
    }

    fun sendReply(req: CustomRequest, reply: String) {
        scope.launch {
            sending = true
            try {
                val res = ApiClient.api.replyToRequest(req.id, ReplyRequest(reply = reply))
                if (res.isSuccessful) {
                    requests = requests.map {
                        if (it.id == req.id) it.copy(status = "replied", adminReply = reply) else it
                    }
                    replyingTo = null
                    replyText  = ""
                    snackState.showSnackbar("Reply sent ✓")
                } else {
                    snackState.showSnackbar("Failed to send reply")
                }
            } catch (_: Exception) { snackState.showSnackbar("Connection error") }
            sending = false
        }
    }

    val tabs     = listOf("All", "New", "Viewed", "Replied")
    val filtered = when (selectedTab) {
        1    -> requests.filter { it.status == "new" }
        2    -> requests.filter { it.status == "viewed" }
        3    -> requests.filter { it.status == "replied" }
        else -> requests
    }

    // Reply dialog
    replyingTo?.let { req ->
        AlertDialog(
            onDismissRequest = { if (!sending) { replyingTo = null; replyText = "" } },
            title   = { Text("Reply to ${req.name}") },
            text    = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(
                        "Style request: ${req.styleDesc.take(80)}${if (req.styleDesc.length > 80) "…" else ""}",
                        fontSize = 12.sp,
                        color    = TextMuted
                    )
                    OutlinedTextField(
                        value         = replyText,
                        onValueChange = { replyText = it },
                        label         = { Text("Your reply") },
                        modifier      = Modifier.fillMaxWidth(),
                        minLines      = 4,
                        enabled       = !sending
                    )
                }
            },
            confirmButton = {
                Button(
                    onClick  = { sendReply(req, replyText) },
                    enabled  = replyText.isNotBlank() && isOnline && !sending,
                    colors   = ButtonDefaults.buttonColors(containerColor = Primary)
                ) {
                    if (sending) CircularProgressIndicator(Modifier.size(14.dp), color = Color.White, strokeWidth = 2.dp)
                    else Text("Send Email")
                }
            },
            dismissButton = {
                TextButton(onClick = { if (!sending) { replyingTo = null; replyText = "" } }) {
                    Text("Cancel")
                }
            }
        )
    }

    Scaffold(snackbarHost = { SnackbarHost(snackState) }) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            if (!isOnline) OfflineBanner()

            Text(
                "Custom Requests",
                fontWeight = FontWeight.ExtraBold,
                fontSize   = 22.sp,
                color      = Purple800,
                modifier   = Modifier.padding(start = 16.dp, top = 16.dp, end = 16.dp, bottom = 8.dp)
            )

            ScrollableTabRow(
                selectedTabIndex = selectedTab,
                edgePadding      = 16.dp,
                containerColor   = MaterialTheme.colorScheme.surface
            ) {
                tabs.forEachIndexed { index, label ->
                    Tab(
                        selected = selectedTab == index,
                        onClick  = { selectedTab = index },
                        text     = { Text(label, fontSize = 13.sp) }
                    )
                }
            }

            when {
                loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(color = Primary)
                }
                filtered.isEmpty() -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Text("No requests here.", color = TextMuted, fontSize = 14.sp)
                }
                else -> LazyColumn(
                    modifier            = Modifier.fillMaxSize(),
                    contentPadding      = PaddingValues(16.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    items(filtered, key = { it.id }) { req ->
                        RequestCard(
                            req        = req,
                            expanded   = expandedId == req.id,
                            actioning  = actioningId == req.id,
                            isOnline   = isOnline,
                            onExpand   = { expandedId = if (expandedId == req.id) null else req.id },
                            onMarkRead = { markViewed(req) },
                            onReply    = { replyingTo = req; replyText = "" }
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun RequestCard(
    req:       CustomRequest,
    expanded:  Boolean,
    actioning: Boolean,
    isOnline:  Boolean,
    onExpand:  () -> Unit,
    onMarkRead: () -> Unit,
    onReply:   () -> Unit
) {
    Card(onClick = onExpand, modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp)) {

            // Header
            Row(
                Modifier.fillMaxWidth(),
                verticalAlignment     = Alignment.Top,
                horizontalArrangement = Arrangement.SpaceBetween
            ) {
                Column(Modifier.weight(1f)) {
                    Text(req.name, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                    Text(req.ref, fontSize = 11.sp, color = TextMuted)
                    Text(req.createdAt.take(10), fontSize = 11.sp, color = TextMuted)
                }
                RequestStatusChip(req.status)
            }

            Spacer(Modifier.height(6.dp))

            // Style description preview / full
            Text(
                text = if (expanded) req.styleDesc
                       else req.styleDesc.take(80) + if (req.styleDesc.length > 80) "…" else "",
                fontSize = 13.sp,
                color    = MaterialTheme.colorScheme.onSurfaceVariant
            )

            // Expanded details
            if (expanded) {
                Spacer(Modifier.height(8.dp))
                HorizontalDivider()
                Spacer(Modifier.height(8.dp))

                DetailRow("Email",          req.email)
                if (!req.phone.isNullOrBlank())        DetailRow("Phone",        req.phone)
                if (!req.hairLength.isNullOrBlank())   DetailRow("Hair Length",   req.hairLength)
                if (!req.preferredDate.isNullOrBlank()) DetailRow("Preferred Date", req.preferredDate)
                if (!req.budgetRange.isNullOrBlank())  DetailRow("Budget",       req.budgetRange)

                // Previous reply
                if (!req.adminReply.isNullOrBlank()) {
                    Spacer(Modifier.height(6.dp))
                    Surface(
                        color    = MaterialTheme.colorScheme.primaryContainer,
                        shape    = MaterialTheme.shapes.small,
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        Column(Modifier.padding(10.dp)) {
                            Text("Your reply", fontSize = 11.sp, fontWeight = FontWeight.Bold, color = Primary)
                            Text(req.adminReply, fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                    }
                }

                Spacer(Modifier.height(10.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (req.status == "new") {
                        OutlinedButton(
                            onClick  = onMarkRead,
                            enabled  = !actioning && isOnline,
                            modifier = Modifier.weight(1f)
                        ) {
                            if (actioning) CircularProgressIndicator(Modifier.size(14.dp), strokeWidth = 2.dp, color = Primary)
                            else Text("Mark Read", fontSize = 12.sp)
                        }
                    }
                    Button(
                        onClick  = onReply,
                        enabled  = isOnline,
                        colors   = ButtonDefaults.buttonColors(containerColor = Primary),
                        modifier = if (req.status == "new") Modifier.weight(1f) else Modifier.fillMaxWidth()
                    ) {
                        Text(if (req.adminReply.isNullOrBlank()) "Reply" else "Reply Again", fontSize = 12.sp)
                    }
                }
            }
        }
    }
}

@Composable
private fun DetailRow(label: String, value: String) {
    Row(Modifier.fillMaxWidth().padding(vertical = 2.dp)) {
        Text("$label: ", fontSize = 12.sp, fontWeight = FontWeight.Medium, color = TextMuted, modifier = Modifier.width(110.dp))
        Text(value, fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
private fun RequestStatusChip(status: String) {
    val (bg, fg) = when (status) {
        "new"     -> Pair(Color(0xFFFEF9C3), Color(0xFF854D0E))
        "viewed"  -> Pair(Color(0xFFE0E7FF), Color(0xFF3730A3))
        "replied" -> Pair(Color(0xFFD1FAE5), Color(0xFF065F46))
        else      -> Pair(MaterialTheme.colorScheme.surfaceVariant, MaterialTheme.colorScheme.onSurfaceVariant)
    }
    Surface(color = bg, shape = MaterialTheme.shapes.small) {
        Text(
            status.replaceFirstChar { it.uppercase() },
            color      = fg,
            fontSize   = 11.sp,
            fontWeight = FontWeight.Bold,
            modifier   = Modifier.padding(horizontal = 8.dp, vertical = 4.dp)
        )
    }
}
