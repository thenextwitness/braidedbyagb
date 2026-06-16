package com.braidedbyagb.admin.sync

import android.content.Context
import androidx.work.*
import com.braidedbyagb.admin.data.db.AppDatabase
import com.braidedbyagb.admin.data.db.CacheEntry
import com.braidedbyagb.admin.data.remote.ApiClient
import com.google.gson.Gson
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.util.concurrent.TimeUnit

class SyncWorker(
    appContext: Context,
    workerParams: WorkerParameters
) : CoroutineWorker(appContext, workerParams) {

    companion object {
        const val WORK_NAME   = "bg_sync"

        // ── Cache keys ────────────────────────────────────────
        const val KEY_DASH       = "dashboard"
        const val KEY_PENDING    = "bookings_pending"
        const val KEY_ALL        = "bookings_confirmed"
        const val KEY_CUSTOMERS  = "customers"
        const val KEY_ORDERS     = "orders"
        const val KEY_ACCOUNTING = "accounting"
        const val KEY_SERVICES   = "services_admin"
        const val KEY_SETTINGS   = "settings"

        /** Enqueue (or keep) the 15-minute periodic sync. */
        fun enqueue(context: Context) {
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()

            val request = PeriodicWorkRequestBuilder<SyncWorker>(15, TimeUnit.MINUTES)
                .setConstraints(constraints)
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 1, TimeUnit.MINUTES)
                .build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                WORK_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                request
            )
        }
    }

    private val gson = Gson()
    private val dao  by lazy { AppDatabase.getInstance(applicationContext).cacheDao() }

    override suspend fun doWork(): Result = withContext(Dispatchers.IO) {
        if (!ApiClient.hasToken()) return@withContext Result.failure()

        var atLeastOneSuccess = false

        // 1. Dashboard stats
        runCatching {
            val res = ApiClient.api.getDashboard()
            if (res.isSuccessful && res.body() != null) {
                dao.put(CacheEntry(KEY_DASH, gson.toJson(res.body())))
                atLeastOneSuccess = true
            }
        }

        // 2. Pending bookings
        runCatching {
            val res = ApiClient.api.getBookings(status = "pending")
            if (res.isSuccessful && res.body() != null) {
                dao.put(CacheEntry(KEY_PENDING, gson.toJson(res.body())))
                atLeastOneSuccess = true
            }
        }

        // 3. Confirmed bookings
        runCatching {
            val res = ApiClient.api.getBookings(status = "confirmed")
            if (res.isSuccessful && res.body() != null) {
                dao.put(CacheEntry(KEY_ALL, gson.toJson(res.body())))
                atLeastOneSuccess = true
            }
        }

        // 4. Customers list
        runCatching {
            val res = ApiClient.api.getCustomers()
            if (res.isSuccessful && res.body() != null) {
                dao.put(CacheEntry(KEY_CUSTOMERS, gson.toJson(res.body())))
                atLeastOneSuccess = true
            }
        }

        // 5. Orders list
        runCatching {
            val res = ApiClient.api.getOrders()
            if (res.isSuccessful && res.body() != null) {
                dao.put(CacheEntry(KEY_ORDERS, gson.toJson(res.body())))
                atLeastOneSuccess = true
            }
        }

        // 6. Accounting stats
        runCatching {
            val res = ApiClient.api.getAccounting()
            if (res.isSuccessful && res.body() != null) {
                dao.put(CacheEntry(KEY_ACCOUNTING, gson.toJson(res.body())))
                atLeastOneSuccess = true
            }
        }

        // 7. Services (full admin view)
        runCatching {
            val res = ApiClient.api.getServicesAdmin()
            if (res.isSuccessful && res.body() != null) {
                dao.put(CacheEntry(KEY_SERVICES, gson.toJson(res.body())))
                atLeastOneSuccess = true
            }
        }

        // 8. Settings
        runCatching {
            val res = ApiClient.api.getSettings()
            if (res.isSuccessful && res.body() != null) {
                dao.put(CacheEntry(KEY_SETTINGS, gson.toJson(res.body())))
                atLeastOneSuccess = true
            }
        }

        if (atLeastOneSuccess) Result.success() else Result.retry()
    }
}
