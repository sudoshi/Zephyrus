plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
}

android {
    namespace = "net.acumenus.hummingbird"
    compileSdk = 35

    defaultConfig {
        applicationId = "net.acumenus.hummingbird"
        minSdk = 26
        targetSdk = 35
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        versionCode = 1
        versionName = "0.1.0"
        buildConfigField("String", "ZEPHYRUS_BASE_URL", "\"https://zephyrus.acumenus.net\"")
        buildConfigField("String", "ZEPHYRUS_REVERB_SCHEME", "\"wss\"")
        buildConfigField("String", "ZEPHYRUS_REVERB_HOST", "\"zephyrus.acumenus.net\"")
        buildConfigField("int", "ZEPHYRUS_REVERB_PORT", "443")
        buildConfigField("String", "ZEPHYRUS_REVERB_KEY", "\"zephyrus-key\"")
    }

    buildTypes {
        debug {
            buildConfigField("String", "ZEPHYRUS_BASE_URL", "\"http://10.0.2.2:8001\"")
            buildConfigField("String", "ZEPHYRUS_REVERB_SCHEME", "\"ws\"")
            buildConfigField("String", "ZEPHYRUS_REVERB_HOST", "\"10.0.2.2\"")
            buildConfigField("int", "ZEPHYRUS_REVERB_PORT", "8080")
            buildConfigField("String", "ZEPHYRUS_REVERB_KEY", "\"zephyrus-key\"")
        }
        release {
            isMinifyEnabled = false
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
    buildFeatures {
        compose = true
        buildConfig = true
    }
}

dependencies {
    implementation(platform("androidx.compose:compose-bom:2025.02.00"))
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-graphics")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.foundation:foundation")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-extended")
    implementation("androidx.activity:activity-compose:1.10.0")
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.core:core-splashscreen:1.0.1")
    implementation("androidx.appcompat:appcompat:1.7.0") // biometric prompt fragment theming
    implementation("androidx.security:security-crypto:1.1.0-alpha06")
    implementation("androidx.biometric:biometric:1.1.0")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.8.7")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.8.7")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.10.1")
    implementation("com.squareup.okhttp3:okhttp:4.12.0") // Reverb (Pusher-protocol) websocket
    // Phase-5 house-glance widget. Glance pulls glance-core + datastore + work-runtime
    // transitively; the widget is app-driven only (updateAll after a foreground load) —
    // no background WorkManager networking is scheduled.
    implementation("androidx.glance:glance-appwidget:1.1.1")
    testImplementation("junit:junit:4.13.2")
    testImplementation("org.json:json:20240303")
    androidTestImplementation(platform("androidx.compose:compose-bom:2025.02.00"))
    androidTestImplementation("androidx.compose.ui:ui-test-junit4")
    androidTestImplementation("androidx.test.ext:junit:1.2.1")
    debugImplementation("androidx.compose.ui:ui-tooling")
    debugImplementation("androidx.compose.ui:ui-test-manifest")
}
