package com.braidedbyagb.admin.data.remote

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import com.braidedbyagb.admin.BuildConfig
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.runBlocking
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

val Context.tokenDataStore: DataStore<Preferences> by preferencesDataStore(name = "auth_prefs")
val TOKEN_KEY = stringPreferencesKey("jwt_token")
val EMAIL_KEY = stringPreferencesKey("admin_email")

object ApiClient {

    private var _token: String? = null
    private var _email: String? = null
    private var appContext: Context? = null

    fun init(context: Context) {
        appContext = context.applicationContext
        // Restore token + email from DataStore synchronously on init
        runBlocking {
            val prefs = context.tokenDataStore.data.first()
            _token = prefs[TOKEN_KEY]
            _email = prefs[EMAIL_KEY]
        }
    }

    fun setToken(token: String?) {
        _token = token
        appContext?.let { ctx ->
            runBlocking {
                ctx.tokenDataStore.edit { prefs ->
                    if (token != null) prefs[TOKEN_KEY] = token
                    else prefs.remove(TOKEN_KEY)
                }
            }
        }
    }

    fun setEmail(email: String?) {
        _email = email
        appContext?.let { ctx ->
            runBlocking {
                ctx.tokenDataStore.edit { prefs ->
                    if (email != null) prefs[EMAIL_KEY] = email
                    else prefs.remove(EMAIL_KEY)
                }
            }
        }
    }

    fun getAdminEmail(): String? = _email

    fun clearToken() {
        setToken(null)
        setEmail(null)
    }

    fun hasToken(): Boolean = !_token.isNullOrBlank()

    private val authInterceptor = Interceptor { chain ->
        val req = chain.request().newBuilder().apply {
            _token?.let { addHeader("Authorization", "Bearer $it") }
        }.build()
        chain.proceed(req)
    }

    private val loggingInterceptor = HttpLoggingInterceptor().apply {
        level = if (BuildConfig.DEBUG) HttpLoggingInterceptor.Level.BODY
                else HttpLoggingInterceptor.Level.NONE
    }

    private val okHttp = OkHttpClient.Builder()
        .addInterceptor(authInterceptor)
        .addInterceptor(loggingInterceptor)
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .build()

    val api: AdminApiService by lazy {
        Retrofit.Builder()
            .baseUrl(BuildConfig.API_BASE_URL)
            .client(okHttp)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(AdminApiService::class.java)
    }
}
