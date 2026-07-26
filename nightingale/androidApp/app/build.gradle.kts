plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
}

android {
    namespace = "net.acumenus.nightingale"
    compileSdk = 35

    defaultConfig {
        applicationId = "net.acumenus.nightingale"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "0.1.0-foundation"
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
        compose = true
    }
}

dependencies {
    implementation(platform("androidx.compose:compose-bom:2025.02.00"))
    implementation("androidx.activity:activity-compose:1.10.0")
    implementation("androidx.compose.foundation:foundation")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")

    testImplementation("junit:junit:4.13.2")

    androidTestImplementation(platform("androidx.compose:compose-bom:2025.02.00"))
    androidTestImplementation("androidx.compose.ui:ui-test-junit4")
    androidTestImplementation("androidx.test.ext:junit:1.2.1")
    androidTestImplementation("androidx.test:runner:1.6.2")

    debugImplementation("androidx.compose.ui:ui-test-manifest")
    debugImplementation("androidx.compose.ui:ui-tooling")
}

tasks.register("verifyNightingaleProductBoundary") {
    group = "verification"
    description = "Verifies that the foundation has no live patient or Hummingbird staff dependency."

    doLast {
        val manifest = layout.projectDirectory.file("src/main/AndroidManifest.xml").asFile.readText()
        check(!manifest.contains("android.permission.INTERNET")) {
            "The Nightingale foundation must not request network access."
        }

        val sourceRoot = layout.projectDirectory.dir("src").asFile
        val forbiddenTerms = listOf("Hummingbird", "api/mobile", "api/auth")
        val violations = sourceRoot.walkTopDown()
            .filter { it.isFile && it.extension in setOf("kt", "xml") }
            .flatMap { file ->
                val content = file.readText()
                forbiddenTerms.asSequence()
                    .filter(content::contains)
                    .map { term -> "${file.relativeTo(projectDir)} contains $term" }
            }
            .toList()
        check(violations.isEmpty()) {
            "Nightingale product boundary violations:\n${violations.joinToString("\n")}"
        }
    }
}

tasks.named("check").configure {
    dependsOn("verifyNightingaleProductBoundary")
}
