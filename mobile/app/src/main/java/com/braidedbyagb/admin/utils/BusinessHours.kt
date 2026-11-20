package com.braidedbyagb.admin.utils

import com.braidedbyagb.admin.data.remote.ApiClient

/**
 * Opening hours, from the website settings `day_start_time` / `day_end_time`
 * (the same ones the website and admin use), so every time picker in the app
 * matches the website. Falls back to 09:30–20:30 when offline or unset.
 */
object BusinessHours {
    const val DEFAULT_START = "09:30"
    const val DEFAULT_END   = "20:30"

    private fun minutes(hhmm: String?): Int? {
        val m = Regex("^([01]\\d|2[0-3]):([0-5]\\d)$").find(hhmm?.trim() ?: return null) ?: return null
        return m.groupValues[1].toInt() * 60 + m.groupValues[2].toInt()
    }

    /** Start times (HH:MM) from opening to the last 30-minute start before closing. */
    fun grid(settings: Map<String, String>?): List<String> {
        var start = minutes(settings?.get("day_start_time")) ?: minutes(DEFAULT_START)!!
        var end   = minutes(settings?.get("day_end_time"))   ?: minutes(DEFAULT_END)!!
        if (end <= start) { start = minutes(DEFAULT_START)!!; end = minutes(DEFAULT_END)!! }
        return (start until end step 30).map { "%02d:%02d".format(it / 60, it % 60) }
    }

    suspend fun loadGrid(): List<String> = try {
        val res = ApiClient.api.getSettings()
        grid(if (res.isSuccessful) res.body() else null)
    } catch (_: Exception) {
        grid(null)
    }
}
