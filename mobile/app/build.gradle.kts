plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.ksp)
    alias(libs.plugins.google.services)
}

android {
    namespace   = "com.braidedbyagb.admin"
    compileSdk  = 35

    defaultConfig {
        applicationId = "com.braidedbyagb.admin"
        minSdk        = 26
        targetSdk     = 35
        versionCode   = 1
        versionName   = "1.0.0"

        // Base URL can be overridden per build variant
        buildConfigField("String", "API_BASE_URL", "\"https://braidedbyagb.co.uk/\"")
    }

    buildFeatures {
        compose     = true
        buildConfig = true
    }

    buildTypes {
        debug {
            isDebuggable = true
        }
        release {
            isMinifyEnabled   = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.lifecycle.runtime)
    implementation(libs.androidx.activity.compose)
    implementation(platform(libs.compose.bom))
    implementation(libs.compose.ui)
    implementation(libs.compose.ui.graphics)
    implementation(libs.compose.ui.tooling.preview)
    implementation(libs.compose.material3)
    implementation(libs.compose.material.icons.extended)
    implementation(libs.navigation.compose)
    implementation(libs.retrofit)
    implementation(libs.retrofit.gson)
    implementation(libs.okhttp.logging)
    implementation(libs.gson)
    implementation(libs.datastore.preferences)
    implementation(libs.security.crypto)
    implementation(libs.kotlinx.coroutines)
    // Stripe Terminal SDK v5 — Tap to Pay on Android (Maven Central)
    implementation("com.stripe:stripeterminal:5.4.1")
    // Room — local SQLite cache
    implementation(libs.room.runtime)
    implementation(libs.room.ktx)
    ksp(libs.room.compiler)
    // WorkManager — background 15-min sync
    implementation(libs.work.runtime.ktx)
    // Firebase — push notifications (FCM)
    implementation(platform(libs.firebase.bom))
    implementation(libs.firebase.messaging.ktx)
    debugImplementation(libs.compose.ui.tooling)
}
