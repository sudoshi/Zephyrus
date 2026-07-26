import groovy.json.JsonOutput
import org.gradle.api.artifacts.component.ModuleComponentIdentifier
import org.gradle.api.artifacts.result.ResolvedDependencyResult

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
    implementation("androidx.compose.animation:animation-core")
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
        check(manifest.contains("android:allowBackup=\"false\"")) {
            "Nightingale application backup must remain disabled."
        }
        check(manifest.contains("android:dataExtractionRules=\"@xml/data_extraction_rules\"")) {
            "Nightingale device-transfer and cloud-backup exclusions are required."
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

tasks.register("writeNightingaleReleaseDependencyResolution") {
    group = "reporting"
    description =
        "Writes the resolved Nightingale Release runtime dependency graph for the governed foundation inventory."

    val outputFile =
        layout.buildDirectory.file(
            "reports/nightingale/release-runtime-dependency-resolution.json",
        )
    outputs.file(outputFile)
    outputs.upToDateWhen { false }

    doLast {
        val configuration = configurations.getByName("releaseRuntimeClasspath")
        val resolution = configuration.incoming.resolutionResult

        fun componentCoordinate(identifier: Any): String =
            when (identifier) {
                is ModuleComponentIdentifier ->
                    "${identifier.group}:${identifier.module}:${identifier.version}"

                else -> identifier.toString()
            }

        val declaredDependencies =
            configuration.allDependencies
                .map { dependency ->
                    linkedMapOf(
                        "group" to dependency.group,
                        "module" to dependency.name,
                        "requested_version" to dependency.version,
                    )
                }.sortedWith(
                    compareBy(
                        { it["group"]?.toString().orEmpty() },
                        { it["module"]?.toString().orEmpty() },
                        { it["requested_version"]?.toString().orEmpty() },
                    ),
                )

        val resolvedComponents =
            resolution.allComponents
                .mapNotNull { component ->
                    val identifier = component.id as? ModuleComponentIdentifier
                        ?: return@mapNotNull null
                    linkedMapOf(
                        "group" to identifier.group,
                        "module" to identifier.module,
                        "version" to identifier.version,
                    )
                }.sortedWith(
                    compareBy(
                        { it["group"].toString() },
                        { it["module"].toString() },
                        { it["version"].toString() },
                    ),
                )

        val dependencyEdges =
            resolution.allDependencies
                .mapNotNull { dependency ->
                    val resolved = dependency as? ResolvedDependencyResult
                        ?: return@mapNotNull null
                    linkedMapOf(
                        "from" to componentCoordinate(resolved.from.id),
                        "requested" to resolved.requested.displayName,
                        "selected" to componentCoordinate(resolved.selected.id),
                    )
                }.distinct()
                .sortedWith(
                    compareBy(
                        { it["from"].toString() },
                        { it["requested"].toString() },
                        { it["selected"].toString() },
                    ),
                )

        val report =
            linkedMapOf(
                "configuration" to configuration.name,
                "declared_dependencies" to declaredDependencies,
                "resolved_components" to resolvedComponents,
                "dependency_edges" to dependencyEdges,
            )

        val destination = outputFile.get().asFile
        destination.parentFile.mkdirs()
        destination.writeText(JsonOutput.prettyPrint(JsonOutput.toJson(report)) + "\n")
        logger.lifecycle(
            "Wrote ${resolvedComponents.size} resolved Nightingale Release runtime components to ${destination.absolutePath}",
        )
    }
}
