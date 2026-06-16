# Retrofit + OkHttp
-dontwarn okhttp3.**
-keep class retrofit2.** { *; }
-keep class okhttp3.** { *; }

# Gson / data models
-keep class com.braidedbyagb.admin.data.model.** { *; }
-keepattributes Signature, *Annotation*

# Kotlin coroutines
-keepnames class kotlinx.coroutines.internal.MainDispatcherFactory {}
