package com.braidedbyagb.admin.service

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Intent
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import com.braidedbyagb.admin.MainActivity
import com.braidedbyagb.admin.R
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.data.model.RegisterTokenRequest
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

class FcmService : FirebaseMessagingService() {

    companion object {
        const val CHANNEL_ID   = "live_chat"
        const val CHANNEL_NAME = "Live Chat"
        const val EXTRA_SESSION_ID = "chat_session_id"

        /** Call this from anywhere to register/refresh the current FCM token. */
        fun refreshAndRegisterToken() {
            com.google.firebase.messaging.FirebaseMessaging.getInstance().token
                .addOnCompleteListener { task ->
                    if (!task.isSuccessful) return@addOnCompleteListener
                    val token = task.result ?: return@addOnCompleteListener
                    registerToken(token)
                }
        }

        private fun registerToken(token: String) {
            CoroutineScope(Dispatchers.IO).launch {
                try {
                    ApiClient.api.registerFcmToken(RegisterTokenRequest(token))
                } catch (_: Exception) { /* best-effort */ }
            }
        }
    }

    // ── Called when FCM issues a new token ────────────────────
    override fun onNewToken(token: String) {
        registerToken(token)
    }

    // ── Called when a push message arrives ───────────────────
    override fun onMessageReceived(message: RemoteMessage) {
        val title     = message.notification?.title ?: "💬 New message"
        val body      = message.notification?.body  ?: ""
        val sessionId = message.data["session_id"]?.toIntOrNull()

        createChannel()
        showNotification(title, body, sessionId)
    }

    // ── Notification channel ──────────────────────────────────
    private fun createChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                CHANNEL_ID,
                CHANNEL_NAME,
                NotificationManager.IMPORTANCE_HIGH
            ).apply {
                description = "Live chat messages from website visitors"
                enableVibration(true)
            }
            getSystemService(NotificationManager::class.java)
                ?.createNotificationChannel(channel)
        }
    }

    // ── Show the notification ─────────────────────────────────
    private fun showNotification(title: String, body: String, sessionId: Int?) {
        val intent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            sessionId?.let { putExtra(EXTRA_SESSION_ID, it) }
        }
        val pendingIntent = PendingIntent.getActivity(
            this,
            sessionId ?: 0,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val notification = NotificationCompat.Builder(this, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_chat_notification)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setContentIntent(pendingIntent)
            .build()

        try {
            NotificationManagerCompat.from(this)
                .notify(sessionId ?: System.currentTimeMillis().toInt(), notification)
        } catch (_: SecurityException) {
            // POST_NOTIFICATIONS not granted — notification silently dropped
        }
    }
}
