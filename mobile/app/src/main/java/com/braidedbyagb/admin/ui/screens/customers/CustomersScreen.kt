package com.braidedbyagb.admin.ui.screens.customers

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.braidedbyagb.admin.data.db.AppDatabase
import com.braidedbyagb.admin.data.db.CacheEntry
import com.braidedbyagb.admin.data.model.Customer
import com.braidedbyagb.admin.data.model.CustomersResponse
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.sync.SyncWorker
import com.braidedbyagb.admin.utils.NetworkUtils
import com.braidedbyagb.admin.utils.OfflineBanner
import com.google.gson.Gson
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

@Composable
fun CustomersScreen(onCustomerClick: (Int) -> Unit = {}) {
    val context   = LocalContext.current
    val gson      = remember { Gson() }
    val dao       = remember { AppDatabase.getInstance(context).cacheDao() }
    val isOnline  = remember { NetworkUtils.isOnline(context) }

    var customers by remember { mutableStateOf<List<Customer>>(emptyList()) }
    var loading   by remember { mutableStateOf(true) }
    var query     by remember { mutableStateOf("") }
    var error     by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(query) {
        error = null
        // For empty query, try cache first
        if (query.isBlank()) {
            val entry = withContext(Dispatchers.IO) { dao.get(SyncWorker.KEY_CUSTOMERS) }
            if (entry != null) {
                runCatching { gson.fromJson(entry.json, CustomersResponse::class.java) }
                    .onSuccess { customers = it.customers }
            }
        }
        loading = customers.isEmpty()
        // Fetch live
        try {
            val res = ApiClient.api.getCustomers(q = query.takeIf { it.isNotBlank() })
            if (res.isSuccessful) {
                customers = res.body()?.customers ?: emptyList()
                // Cache the unfiltered result
                if (query.isBlank()) {
                    withContext(Dispatchers.IO) { dao.put(CacheEntry(SyncWorker.KEY_CUSTOMERS, gson.toJson(res.body()))) }
                }
            } else if (customers.isEmpty()) {
                error = "Failed to load"
            }
        } catch (e: Exception) {
            if (customers.isEmpty()) error = if (isOnline) "Connection error" else "No cached data yet — connect to sync"
        }
        loading = false
    }

    Column(Modifier.fillMaxSize()) {
        if (!isOnline) OfflineBanner()

        Text(
            "Clients",
            fontWeight = FontWeight.ExtraBold,
            fontSize = 22.sp,
            color = Color(0xFF2A0020),
            modifier = Modifier.padding(16.dp)
        )
        OutlinedTextField(
            value = query,
            onValueChange = { query = it },
            placeholder = { Text("Search name, email, phone…", fontSize = 13.sp) },
            singleLine = true,
            enabled = isOnline,
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 16.dp)
        )
        Spacer(Modifier.height(8.dp))
        when {
            loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                CircularProgressIndicator(color = Color(0xFFCC1A8A))
            }
            error != null && customers.isEmpty() -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                Text(error!!, color = Color(0xFFDC2626))
            }
            customers.isEmpty() -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                Text("No clients found.", color = Color(0xFF7A4A70))
            }
            else -> LazyColumn(
                contentPadding = PaddingValues(16.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                items(customers, key = { it.id }) { c ->
                    CustomerCard(customer = c, onClick = { onCustomerClick(c.id) })
                }
            }
        }
    }
}

@Composable
private fun CustomerCard(customer: Customer, onClick: () -> Unit) {
    Card(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick),
        shape = RoundedCornerShape(10.dp)
    ) {
        Row(
            Modifier.padding(14.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            Box(
                Modifier
                    .size(44.dp)
                    .clip(CircleShape)
                    .background(Color(0xFFCC1A8A)),
                contentAlignment = Alignment.Center
            ) {
                Text(
                    customer.name.firstOrNull()?.uppercaseChar()?.toString() ?: "?",
                    color = Color.White,
                    fontWeight = FontWeight.ExtraBold,
                    fontSize = 18.sp
                )
            }

            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text(customer.name, fontWeight = FontWeight.Bold, fontSize = 15.sp, color = Color(0xFF2A0020))
                    if (customer.isBlocked == 1) {
                        Surface(shape = RoundedCornerShape(4.dp), color = Color(0xFFFEF2F2)) {
                            Text(
                                "BLOCKED",
                                modifier = Modifier.padding(horizontal = 5.dp, vertical = 1.dp),
                                fontSize = 9.sp,
                                fontWeight = FontWeight.ExtraBold,
                                color = Color(0xFFDC2626)
                            )
                        }
                    }
                }

                Text(customer.email, fontSize = 12.sp, color = Color(0xFF7A4A70))
                customer.phone?.let { Text(it, fontSize = 12.sp, color = Color(0xFF7A4A70)) }

                val tagList = customer.tags
                    ?.split(",")
                    ?.map { it.trim() }
                    ?.filter { it.isNotBlank() }
                    ?: emptyList()

                if (tagList.isNotEmpty()) {
                    Spacer(Modifier.height(4.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                        tagList.forEach { tag ->
                            Surface(shape = RoundedCornerShape(10.dp), color = Color(0xFFFAF5FF)) {
                                Text(
                                    tag,
                                    modifier = Modifier.padding(horizontal = 7.dp, vertical = 2.dp),
                                    fontSize = 10.sp,
                                    fontWeight = FontWeight.Bold,
                                    color = Color(0xFFCC1A8A)
                                )
                            }
                        }
                    }
                }
            }

            if (customer.loyaltyPoints > 0) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Text("⭐", fontSize = 14.sp)
                    Text(
                        "${customer.loyaltyPoints}",
                        fontSize = 11.sp,
                        fontWeight = FontWeight.Bold,
                        color = Color(0xFFCA8A04)
                    )
                }
            }
        }
    }
}
