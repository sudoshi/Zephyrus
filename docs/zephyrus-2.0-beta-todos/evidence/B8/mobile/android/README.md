# Android Build Evidence

Date: 2026-07-09
Branch: `feat/hummingbird-4d-service-line-eddy`
Commit: `3093c355efdc6d2bbdf3310f30d3ba663105629f` plus uncommitted local changes
Environment: Linux local host, Java 17 override

## Commands

| Command | Result |
| --- | --- |
| `JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64 ./gradlew test` | Pass |
| `JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64 ./gradlew assembleDebug` | Pass |

## Security Posture Verified In Code

- `android:allowBackup="false"`.
- `android:fullBackupContent="false"`.
- `android:dataExtractionRules="@xml/data_extraction_rules"`.
- `android:usesCleartextTraffic="false"`.
- Backup and transfer rules exclude shared preferences, databases, files, and external files.

## Remaining Evidence

- Store build artifacts or CI logs if this becomes the release candidate.
- Capture Android persona screenshots and PHI review notes.
