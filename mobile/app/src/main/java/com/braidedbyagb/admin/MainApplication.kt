package com.braidedbyagb.admin

import android.app.Application
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.sync.SyncWorker
import com.stripe.stripeterminal.TerminalApplicationDelegate

class MainApplication : Application() {
    override fun onCreate() {
        super.onCreate()
        // Required by Stripe Terminal SDK v5 — must be called before Terminal.init()
        TerminalApplicationDelegate.onCreate(this)
        // Initialise ApiClient so the persisted token is restored from DataStore
        ApiClient.init(this)
        // Enqueue the 15-minute background sync (only runs when network is connected)
        SyncWorker.enqueue(this)
    }
}
