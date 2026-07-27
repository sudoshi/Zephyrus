plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
}

val patientApiEnabled = providers.gradleProperty("hummingbird.patient.api.enabled")
    .orElse("false")
val patientApiBaseUrl = providers.gradleProperty("hummingbird.patient.api.baseUrl")
    .orElse("https://zephyrus.acumenus.net")

android {
    namespace = "net.acumenus.hummingbird.patient"
    compileSdk = 35

    defaultConfig {
        applicationId = "net.acumenus.hummingbird.patient"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "0.1.0-pilot"

        buildConfigField("boolean", "PATIENT_API_ENABLED", patientApiEnabled.get())
        buildConfigField(
            "String",
            "PATIENT_API_BASE_URL",
            "\"${patientApiBaseUrl.get().replace("\\", "\\\\").replace("\"", "\\\"")}\"",
        )
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }

    buildTypes {
        debug {}
        release {
            isMinifyEnabled = false
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
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
        buildConfig = true
        compose = true
    }

    testOptions {
        unitTests.isIncludeAndroidResources = true
    }
}

dependencies {
    implementation(platform("androidx.compose:compose-bom:2025.02.00"))
    implementation("androidx.activity:activity-compose:1.10.0")
    implementation("androidx.compose.foundation:foundation")
    implementation("androidx.compose.material:material-icons-extended")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-graphics")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.8.7")
    implementation("androidx.security:security-crypto:1.1.0")
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.10.1")

    testImplementation("junit:junit:4.13.2")
    testImplementation("org.json:json:20240303")
    testImplementation("org.jetbrains.kotlinx:kotlinx-coroutines-test:1.10.1")

    androidTestImplementation(platform("androidx.compose:compose-bom:2025.02.00"))
    androidTestImplementation("androidx.compose.ui:ui-test-junit4")
    androidTestImplementation("androidx.test.ext:junit:1.2.1")
    androidTestImplementation("androidx.test:runner:1.6.2")

    debugImplementation("androidx.compose.ui:ui-tooling")
    debugImplementation("androidx.compose.ui:ui-test-manifest")
}

tasks.register("verifyPatientProductBoundary") {
    group = "verification"
    description = "Fails when patient sources reference a staff endpoint or Gradle module."

    doLast {
        val sourceRoot = layout.projectDirectory.dir("src").asFile
        val apiRoot = "/" + "api"
        val forbiddenPaths = listOf("mobile", "auth").map { path ->
            "$apiRoot/$path"
        }
        val violations = sourceRoot.walkTopDown()
            .filter { it.isFile }
            .flatMap { file ->
                val text = file.readText()
                forbiddenPaths.asSequence()
                    .filter(text::contains)
                    .map { forbidden -> "${file.relativeTo(projectDir)} contains $forbidden" }
            }
            .toList()

        check(violations.isEmpty()) {
            "Patient product boundary violations:\n${violations.joinToString("\n")}"
        }
        check(rootProject.subprojects.map { it.path } == listOf(":app")) {
            "The patient Gradle root must contain only its own app module."
        }

        val volatileInputFiles = listOf(
            "src/main/java/net/acumenus/hummingbird/patient/ui/PatientAuthenticationScreen.kt",
            "src/main/java/net/acumenus/hummingbird/patient/ui/PatientMessagingPanel.kt",
            "src/main/java/net/acumenus/hummingbird/patient/ui/PatientSessionManagementScreen.kt",
        ).map(projectDir::resolve)
        val forbiddenSavedStateSymbols = listOf(
            "remember" + "Saveable",
            "SavedState" + "Handle",
        )
        val savedStateViolations = volatileInputFiles.flatMap { file ->
            check(file.isFile) { "Missing protected-input surface: ${file.relativeTo(projectDir)}" }
            val text = file.readText()
            forbiddenSavedStateSymbols
                .filter(text::contains)
                .map { symbol -> "${file.relativeTo(projectDir)} contains $symbol" }
        }
        check(savedStateViolations.isEmpty()) {
            "Patient secrets or message drafts must stay out of saved state:\n" +
                savedStateViolations.joinToString("\n")
        }
    }
}

tasks.named("check").configure {
    dependsOn("verifyPatientProductBoundary")
}
