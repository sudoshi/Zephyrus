# Android Build Evidence

Date: 2026-07-09
Branch: `feat/hummingbird-4d-service-line-eddy`
Commit: post-review hardening working tree; final commit recorded by git history
Environment: Linux local host, Java 17 override

## Commands

| Command | Result |
| --- | --- |
| `JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64 ./gradlew testDebugUnitTest` | Pass |
| `JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64 ./gradlew assembleRelease` | Pass |

## Security Posture Verified In Code

- `android:allowBackup="false"`.
- `android:fullBackupContent="false"`.
- `android:dataExtractionRules="@xml/data_extraction_rules"`.
- Release/main `android:usesCleartextTraffic="false"`.
- Debug manifest enables cleartext only for emulator/local development.
- Release `BuildConfig` defaults to `https://zephyrus.acumenus.net` plus `wss://zephyrus.acumenus.net:443`; debug defaults to emulator localhost equivalents.
- Backup and transfer rules exclude shared preferences, databases, files, and external files.

## Local Toolchain Note

The host default OpenJDK 25.0.3 failed Gradle/Kotlin version parsing. Java 17 was used for the passing validation commands above.

## Remaining Evidence

- Store build artifacts or CI logs if this becomes the release candidate.
- Capture Android persona screenshots and PHI review notes.
