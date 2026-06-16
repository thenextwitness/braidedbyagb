package com.braidedbyagb.admin.utils

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/** Converts an integer number of minutes into a human-readable "Xh Ym" string.
 *  Examples: 90 → "1h 30m", 60 → "1h", 45 → "45m" */
fun formatDuration(minutes: Int): String {
    if (minutes <= 0) return "0m"
    val h = minutes / 60
    val m = minutes % 60
    return when {
        h == 0 -> "${m}m"
        m == 0 -> "${h}h"
        else   -> "${h}h ${m}m"
    }
}

object NetworkUtils {
    /** Returns true if the device has an active internet connection. */
    fun isOnline(context: Context): Boolean {
        val cm = context.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
        val network = cm.activeNetwork ?: return false
        val caps = cm.getNetworkCapabilities(network) ?: return false
        return caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }
}

/** Red banner shown at the top of screens when the device is offline. */
@Composable
fun OfflineBanner() {
    Surface(
        modifier = Modifier.fillMaxWidth(),
        color    = Color(0xFFDC2626)
    ) {
        Text(
            text       = "📵  Offline — showing last sync",
            modifier   = Modifier.padding(horizontal = 16.dp, vertical = 6.dp),
            fontSize   = 12.sp,
            fontWeight = FontWeight.Medium,
            color      = Color.White,
            textAlign  = TextAlign.Center
        )
    }
}
