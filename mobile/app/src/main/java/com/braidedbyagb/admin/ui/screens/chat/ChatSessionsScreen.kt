package com.braidedbyagb.admin.ui.screens.chat

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Chat
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.braidedbyagb.admin.data.model.ChatSession
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.ui.theme.Primary
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.*

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChatSessionsScreen(
    onSessionClick: (Int) -> Unit
) {
    val scope    = rememberCoroutineScope()
    var sessions by remember { mutableStateOf<List<ChatSession>>(emptyList()) }
    var loading  by remember { mutableStateOf(true) }
    var error    by remember { mutableStateOf<String?>(null) }
    var tab      by remember { mutableStateOf("active") }

    fun load() {
        scope.launch {
            loading = true
            error   = null
            try {
                val res = ApiClient.api.getChatSessions(status = tab)
                sessions = if (res.isSuccessful) res.body() ?: emptyList() else emptyList()
            } catch (e: Exception) {
                error = "Could not load chats"
            } finally {
                loading = false
            }
        }
    }

    // Auto-refresh every 8 seconds
    LaunchedEffect(tab) {
        load()
        while (true) {
            delay(8000)
            try {
                val res = ApiClient.api.getChatSessions(status = tab)
                if (res.isSuccessful) sessions = res.body() ?: emptyList()
            } catch (_: Exception) {}
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Live Chat", fontWeight = FontWeight.Bold) },
                actions = {
                    IconButton(onClick = { load() }) {
                        Icon(Icons.Default.Refresh, "Refresh")
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = MaterialTheme.colorScheme.surface)
            )
        }
    ) { pad ->

        Column(Modifier.fillMaxSize().padding(pad)) {

            // ── Tab row ──────────────────────────────────────────
            Row(
                Modifier
                    .fillMaxWidth()
                    .padding(horizontal = 16.dp, vertical = 8.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                listOf("active" to "Active", "closed" to "Closed").forEach { (key, label) ->
                    val selected = tab == key
                    FilterChip(
                        selected = selected,
                        onClick  = { if (tab != key) { tab = key } },
                        label    = { Text(label, fontWeight = if (selected) FontWeight.Bold else FontWeight.Normal) },
                        colors   = FilterChipDefaults.filterChipColors(
                            selectedContainerColor = Primary,
                            selectedLabelColor     = Color.White
                        )
                    )
                }
            }

            // ── Content ──────────────────────────────────────────
            when {
                loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(color = Primary)
                }
                error != null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Column(horizontalAlignment = Alignment.CenterHorizontally) {
                        Text(error!!, color = MaterialTheme.colorScheme.error)
                        Spacer(Modifier.height(12.dp))
                        Button(onClick = { load() }) { Text("Retry") }
                    }
                }
                sessions.isEmpty() -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Column(horizontalAlignment = Alignment.CenterHorizontally) {
                        Icon(Icons.AutoMirrored.Filled.Chat, null, tint = MaterialTheme.colorScheme.outlineVariant,
                            modifier = Modifier.size(56.dp))
                        Spacer(Modifier.height(12.dp))
                        Text("No ${tab} conversations", color = MaterialTheme.colorScheme.outline)
                    }
                }
                else -> LazyColumn(
                    contentPadding = PaddingValues(horizontal = 16.dp, vertical = 8.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    items(sessions, key = { it.id }) { session ->
                        ChatSessionCard(session = session, onClick = { onSessionClick(session.id) })
                    }
                }
            }
        }
    }
}

@Composable
private fun ChatSessionCard(session: ChatSession, onClick: () -> Unit) {
    val hasUnread = session.unreadAdmin > 0

    Card(
        modifier = Modifier
            .fillMaxWidth()
            .clickable { onClick() },
        shape  = RoundedCornerShape(12.dp),
        colors = CardDefaults.cardColors(
            containerColor = if (hasUnread)
                Primary.copy(alpha = 0.07f)
            else MaterialTheme.colorScheme.surfaceVariant
        ),
        elevation = CardDefaults.cardElevation(defaultElevation = if (hasUnread) 2.dp else 0.dp)
    ) {
        Row(
            Modifier.padding(14.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            // Avatar
            Box(
                Modifier
                    .size(44.dp)
                    .clip(CircleShape)
                    .background(Primary.copy(alpha = 0.15f)),
                contentAlignment = Alignment.Center
            ) {
                Text(
                    text  = session.customerName?.take(1)?.uppercase() ?: "?",
                    color = Primary,
                    fontWeight = FontWeight.Bold,
                    fontSize   = 18.sp
                )
            }

            Spacer(Modifier.width(12.dp))

            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        text       = session.customerName ?: "Unknown",
                        fontWeight = FontWeight.Bold,
                        fontSize   = 15.sp,
                        modifier   = Modifier.weight(1f)
                    )
                    if (hasUnread) {
                        Box(
                            Modifier
                                .clip(CircleShape)
                                .background(Color(0xFFDC2626))
                                .padding(horizontal = 7.dp, vertical = 2.dp)
                        ) {
                            Text(
                                text      = session.unreadAdmin.toString(),
                                color     = Color.White,
                                fontSize  = 11.sp,
                                fontWeight = FontWeight.Bold
                            )
                        }
                    }
                }

                session.customerEmail?.let {
                    Text(it, fontSize = 12.sp, color = MaterialTheme.colorScheme.outline,
                        maxLines = 1, overflow = TextOverflow.Ellipsis)
                }

                session.lastMsg?.let {
                    Spacer(Modifier.height(4.dp))
                    Text(
                        text     = it,
                        fontSize = 13.sp,
                        color    = MaterialTheme.colorScheme.onSurfaceVariant,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis
                    )
                }
            }

            Spacer(Modifier.width(8.dp))

            // Time
            session.lastMsgAt?.let {
                Text(
                    text     = formatTime(it),
                    fontSize = 11.sp,
                    color    = MaterialTheme.colorScheme.outline
                )
            }
        }
    }
}

private fun formatTime(dt: String): String {
    return try {
        val sdf     = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.UK)
        val date    = sdf.parse(dt) ?: return ""
        val now     = Calendar.getInstance()
        val cal     = Calendar.getInstance().also { it.time = date }
        if (now.get(Calendar.DATE) == cal.get(Calendar.DATE))
            SimpleDateFormat("HH:mm", Locale.UK).format(date)
        else
            SimpleDateFormat("d MMM", Locale.UK).format(date)
    } catch (_: Exception) { "" }
}
