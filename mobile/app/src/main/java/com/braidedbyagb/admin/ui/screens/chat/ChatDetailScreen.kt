package com.braidedbyagb.admin.ui.screens.chat

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.TextFieldValue
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.braidedbyagb.admin.data.model.ChatMessage
import com.braidedbyagb.admin.data.model.ChatMessagesResponse
import com.braidedbyagb.admin.data.model.ChatReplyRequest
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.ui.theme.Primary
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.*

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChatDetailScreen(
    sessionId: Int,
    onBack: () -> Unit
) {
    val scope     = rememberCoroutineScope()
    val listState = rememberLazyListState()

    var data      by remember { mutableStateOf<ChatMessagesResponse?>(null) }
    var messages  by remember { mutableStateOf<List<ChatMessage>>(emptyList()) }
    var loading   by remember { mutableStateOf(true) }
    var isClosed  by remember { mutableStateOf(false) }
    var reply     by remember { mutableStateOf(TextFieldValue("")) }
    var sending   by remember { mutableStateOf(false) }
    var snack     by remember { mutableStateOf<String?>(null) }
    val snackState = remember { SnackbarHostState() }

    // Show snackbar
    LaunchedEffect(snack) {
        snack?.let { snackState.showSnackbar(it); snack = null }
    }

    fun loadMessages(markRead: Boolean = true) {
        scope.launch {
            try {
                val res = ApiClient.api.getChatMessages(sessionId)
                if (res.isSuccessful) {
                    val body = res.body()!!
                    data     = body
                    messages = body.messages
                    isClosed = body.session.status == "closed"
                    loading  = false
                    // Scroll to bottom
                    if (messages.isNotEmpty()) {
                        listState.animateScrollToItem(messages.lastIndex)
                    }
                }
            } catch (_: Exception) {
                if (loading) loading = false
            }
        }
    }

    // Initial load + 5-second polling while screen is open
    LaunchedEffect(sessionId) {
        loadMessages()
        while (true) {
            delay(5000)
            if (!isClosed) loadMessages(markRead = false)
        }
    }

    fun sendReply() {
        val text = reply.text.trim()
        if (text.isBlank() || sending || isClosed) return
        sending = true
        scope.launch {
            try {
                val res = ApiClient.api.sendChatReply(sessionId, ChatReplyRequest(text))
                if (res.isSuccessful && res.body()?.success == true) {
                    reply = TextFieldValue("")
                    loadMessages(markRead = false)
                } else {
                    snack = "Failed to send reply"
                }
            } catch (_: Exception) {
                snack = "Network error — could not send"
            } finally {
                sending = false
            }
        }
    }

    fun closeSession() {
        scope.launch {
            try {
                val res = ApiClient.api.closeChatSession(sessionId)
                if (res.isSuccessful) {
                    isClosed = true
                    snack = "Chat closed"
                }
            } catch (_: Exception) { snack = "Failed to close chat" }
        }
    }

    Scaffold(
        snackbarHost = { SnackbarHost(snackState) },
        topBar = {
            TopAppBar(
                title = {
                    Column {
                        Text(
                            text       = data?.session?.customerName ?: "Chat",
                            fontWeight = FontWeight.Bold,
                            fontSize   = 16.sp
                        )
                        data?.session?.customerEmail?.let {
                            Text(it, fontSize = 12.sp, color = MaterialTheme.colorScheme.outline)
                        }
                    }
                },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, "Back")
                    }
                },
                actions = {
                    if (!isClosed) {
                        TextButton(
                            onClick = { closeSession() },
                            colors  = ButtonDefaults.textButtonColors(contentColor = Color(0xFFDC2626))
                        ) {
                            Text("End Chat", fontWeight = FontWeight.Bold, fontSize = 13.sp)
                        }
                    } else {
                        Text(
                            text     = "CLOSED",
                            color    = MaterialTheme.colorScheme.outline,
                            fontSize = 12.sp,
                            fontWeight = FontWeight.Bold,
                            modifier = Modifier.padding(end = 16.dp)
                        )
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = MaterialTheme.colorScheme.surface)
            )
        }
    ) { pad ->
        if (loading) {
            Box(Modifier.fillMaxSize().padding(pad), contentAlignment = Alignment.Center) {
                CircularProgressIndicator(color = Primary)
            }
        } else {
            Column(Modifier.fillMaxSize().padding(pad)) {

                // ── Message list ─────────────────────────────────
                LazyColumn(
                    state           = listState,
                    modifier        = Modifier.weight(1f),
                    contentPadding  = PaddingValues(16.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    items(messages, key = { it.id }) { msg ->
                        MessageBubble(msg)
                    }
                    if (isClosed) {
                        item {
                            Text(
                                text     = "Chat ended",
                                modifier = Modifier.fillMaxWidth().padding(top = 12.dp),
                                color    = MaterialTheme.colorScheme.outline,
                                fontSize = 12.sp,
                                textAlign = androidx.compose.ui.text.style.TextAlign.Center
                            )
                        }
                    }
                }

                // ── Reply input ──────────────────────────────────
                if (!isClosed) {
                    HorizontalDivider()
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .padding(horizontal = 12.dp, vertical = 8.dp)
                            .navigationBarsPadding()
                            .imePadding(),
                        verticalAlignment = Alignment.Bottom,
                        horizontalArrangement = Arrangement.spacedBy(8.dp)
                    ) {
                        OutlinedTextField(
                            value       = reply,
                            onValueChange = { reply = it },
                            modifier    = Modifier.weight(1f),
                            placeholder = { Text("Type your reply…") },
                            minLines    = 1,
                            maxLines    = 4,
                            shape       = RoundedCornerShape(20.dp),
                            colors      = OutlinedTextFieldDefaults.colors(
                                focusedBorderColor   = Primary,
                                unfocusedBorderColor = MaterialTheme.colorScheme.outlineVariant
                            )
                        )
                        IconButton(
                            onClick  = { sendReply() },
                            enabled  = reply.text.isNotBlank() && !sending,
                            modifier = Modifier
                                .size(48.dp)
                                .background(
                                    if (reply.text.isNotBlank() && !sending) Primary
                                    else MaterialTheme.colorScheme.outlineVariant,
                                    shape = RoundedCornerShape(50)
                                )
                        ) {
                            if (sending) {
                                CircularProgressIndicator(
                                    Modifier.size(20.dp),
                                    color = Color.White,
                                    strokeWidth = 2.dp
                                )
                            } else {
                                Icon(
                                    Icons.AutoMirrored.Filled.Send,
                                    "Send",
                                    tint = Color.White
                                )
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun MessageBubble(msg: ChatMessage) {
    val isAdmin = msg.sender == "admin"

    Row(
        Modifier.fillMaxWidth(),
        horizontalArrangement = if (isAdmin) Arrangement.End else Arrangement.Start
    ) {
        Column(horizontalAlignment = if (isAdmin) Alignment.End else Alignment.Start) {
            Text(
                text     = if (isAdmin) "You" else "Customer",
                fontSize = 10.sp,
                color    = MaterialTheme.colorScheme.outline,
                modifier = Modifier.padding(horizontal = 4.dp, vertical = 2.dp)
            )
            Box(
                Modifier
                    .widthIn(max = 280.dp)
                    .background(
                        color = if (isAdmin) Primary else MaterialTheme.colorScheme.surfaceVariant,
                        shape = RoundedCornerShape(
                            topStart    = 16.dp,
                            topEnd      = 16.dp,
                            bottomStart = if (isAdmin) 16.dp else 4.dp,
                            bottomEnd   = if (isAdmin) 4.dp else 16.dp
                        )
                    )
                    .padding(horizontal = 14.dp, vertical = 10.dp)
            ) {
                Text(
                    text     = msg.body,
                    color    = if (isAdmin) Color.White else MaterialTheme.colorScheme.onSurfaceVariant,
                    fontSize = 14.sp,
                    lineHeight = 20.sp
                )
            }
            Text(
                text     = formatMsgTime(msg.createdAt),
                fontSize = 10.sp,
                color    = MaterialTheme.colorScheme.outline,
                modifier = Modifier.padding(horizontal = 4.dp, vertical = 2.dp)
            )
        }
    }
}

private fun formatMsgTime(dt: String): String {
    return try {
        val sdf  = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.UK)
        val date = sdf.parse(dt) ?: return ""
        SimpleDateFormat("HH:mm", Locale.UK).format(date)
    } catch (_: Exception) { "" }
}
