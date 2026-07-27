# Hummingbird → TestFlight

Moved. TestFlight for **both** iOS apps is now documented in one place:

> **[docs/hummingbird/TESTFLIGHT.md](../../docs/hummingbird/TESTFLIGHT.md)**

```bash
./testflight.sh doctor              # from the repo root
./testflight.sh ship hummingbird
```

`./archive-testflight.sh` still works — it delegates to the root script.
`./deploy-device.sh` is unchanged: debug build → install → launch on the paired iPhone.
