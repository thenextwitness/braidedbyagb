package com.braidedbyagb.admin.ui.screens.reviews

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
import com.braidedbyagb.admin.data.model.Review
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.ui.theme.Primary
import com.braidedbyagb.admin.ui.theme.Purple800
import com.braidedbyagb.admin.ui.theme.TextMuted
import com.braidedbyagb.admin.utils.NetworkUtils
import com.braidedbyagb.admin.utils.OfflineBanner
import kotlinx.coroutines.launch

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ReviewsScreen() {
    val context  = LocalContext.current
    val scope    = rememberCoroutineScope()
    val isOnline = remember { NetworkUtils.isOnline(context) }

    var reviews     by remember { mutableStateOf<List<Review>>(emptyList()) }
    var loading     by remember { mutableStateOf(true) }
    var selectedTab by remember { mutableStateOf(0) }
    var expandedId  by remember { mutableStateOf<Int?>(null) }
    var actioningId by remember { mutableStateOf<Int?>(null) }
    val snackState  = remember { SnackbarHostState() }

    suspend fun load() {
        try {
            val res = ApiClient.api.getReviews()
            if (res.isSuccessful && res.body() != null) {
                reviews = res.body()!!.reviews
            }
        } catch (_: Exception) {}
        loading = false
    }

    LaunchedEffect(Unit) { load() }

    fun approve(id: Int) {
        if (!isOnline) { scope.launch { snackState.showSnackbar("You're offline. Connect to perform this action.") }; return }
        scope.launch {
            actioningId = id
            try {
                val res = ApiClient.api.approveReview(id)
                if (res.isSuccessful) {
                    reviews = reviews.map { if (it.id == id) it.copy(status = "approved") else it }
                    snackState.showSnackbar("Review approved ✓")
                } else {
                    snackState.showSnackbar("Failed to approve")
                }
            } catch (_: Exception) { snackState.showSnackbar("Connection error") }
            actioningId = null
        }
    }

    fun reject(id: Int) {
        if (!isOnline) { scope.launch { snackState.showSnackbar("You're offline. Connect to perform this action.") }; return }
        scope.launch {
            actioningId = id
            try {
                val res = ApiClient.api.rejectReview(id)
                if (res.isSuccessful) {
                    reviews = reviews.map { if (it.id == id) it.copy(status = "rejected") else it }
                    snackState.showSnackbar("Review rejected")
                } else {
                    snackState.showSnackbar("Failed to reject")
                }
            } catch (_: Exception) { snackState.showSnackbar("Connection error") }
            actioningId = null
        }
    }

    val tabs     = listOf("All", "Pending", "Approved", "Rejected")
    val filtered = when (selectedTab) {
        1    -> reviews.filter { it.status == "pending" }
        2    -> reviews.filter { it.status == "approved" }
        3    -> reviews.filter { it.status == "rejected" }
        else -> reviews
    }

    Scaffold(snackbarHost = { SnackbarHost(snackState) }) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            if (!isOnline) OfflineBanner()

            Text(
                "Reviews",
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
                    Text("No reviews here.", color = TextMuted, fontSize = 14.sp)
                }
                else -> LazyColumn(
                    modifier            = Modifier.fillMaxSize(),
                    contentPadding      = PaddingValues(16.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    items(filtered, key = { it.id }) { review ->
                        ReviewCard(
                            review     = review,
                            expanded   = expandedId == review.id,
                            actioning  = actioningId == review.id,
                            isOnline   = isOnline,
                            onExpand   = { expandedId = if (expandedId == review.id) null else review.id },
                            onApprove  = { approve(review.id) },
                            onReject   = { reject(review.id) }
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun ReviewCard(
    review:    Review,
    expanded:  Boolean,
    actioning: Boolean,
    isOnline:  Boolean,
    onExpand:  () -> Unit,
    onApprove: () -> Unit,
    onReject:  () -> Unit
) {
    Card(onClick = onExpand, modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp)) {

            // Header row
            Row(
                Modifier.fillMaxWidth(),
                verticalAlignment     = Alignment.Top,
                horizontalArrangement = Arrangement.SpaceBetween
            ) {
                Column(Modifier.weight(1f)) {
                    Text(review.customerName, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                    val subject = listOfNotNull(review.serviceName, review.productName).firstOrNull()
                    if (!subject.isNullOrBlank()) {
                        Text(subject, fontSize = 12.sp, color = TextMuted)
                    }
                    // Star rating
                    val filled = review.rating.coerceIn(0, 5)
                    Text(
                        "★".repeat(filled) + "☆".repeat(5 - filled),
                        fontSize = 15.sp,
                        color    = Color(0xFFF59E0B),
                        modifier = Modifier.padding(top = 2.dp)
                    )
                }
                ReviewStatusChip(review.status)
            }

            Spacer(Modifier.height(6.dp))

            // Review text — truncated unless expanded
            Text(
                text  = if (expanded) review.reviewText
                        else review.reviewText.take(100) + if (review.reviewText.length > 100) "…" else "",
                fontSize = 13.sp,
                color    = MaterialTheme.colorScheme.onSurfaceVariant
            )

            Text(
                review.submittedAt.take(10),
                fontSize = 11.sp,
                color    = TextMuted,
                modifier = Modifier.padding(top = 4.dp)
            )

            // Action buttons — only for pending, only when expanded
            if (expanded && review.status == "pending") {
                Spacer(Modifier.height(10.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(
                        onClick  = onApprove,
                        enabled  = !actioning && isOnline,
                        colors   = ButtonDefaults.buttonColors(containerColor = Color(0xFF059669)),
                        modifier = Modifier.weight(1f)
                    ) {
                        if (actioning) {
                            CircularProgressIndicator(Modifier.size(14.dp), color = Color.White, strokeWidth = 2.dp)
                        } else {
                            Text("Approve", color = Color.White)
                        }
                    }
                    OutlinedButton(
                        onClick  = onReject,
                        enabled  = !actioning && isOnline,
                        modifier = Modifier.weight(1f),
                        colors   = ButtonDefaults.outlinedButtonColors(contentColor = Color(0xFFDC2626))
                    ) { Text("Reject") }
                }
            }
        }
    }
}

@Composable
private fun ReviewStatusChip(status: String) {
    val (bg, fg) = when (status) {
        "approved" -> Pair(Color(0xFFD1FAE5), Color(0xFF065F46))
        "rejected" -> Pair(Color(0xFFFEE2E2), Color(0xFF991B1B))
        else       -> Pair(Color(0xFFFEF9C3), Color(0xFF854D0E))
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
