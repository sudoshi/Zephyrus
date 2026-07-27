# Hummingbird staff TestFlight

Hummingbird is the staff mobile product. Its iOS distribution source is
`hummingbird/iosApp`, scheme `Hummingbird`, with bundle identifier
`net.acumenus.hummingbird`.

The repository-level `./testflight.sh` helper can archive, export, upload, inspect, wait
for, and distribute an authorized Hummingbird build. Its non-secret registry template is
`.appledeploy.example`; real App Store Connect credentials remain outside the repository
in `.appledeploy` and the configured private-key path.

```bash
./testflight.sh doctor
./testflight.sh ship hummingbird --no-upload
./testflight.sh ship hummingbird --wait --to "Internal"
./testflight.sh builds hummingbird
./testflight.sh status hummingbird
```

Running the helper is not itself release authorization. Hummingbird release owners must
still verify the exact source SHA, CI result, signing identity, version/build monotonicity,
exported artifact identity, privacy declarations, tester cohort, and go/no-go record.

Nightingale is a separate patient product with a different source root, release boundary,
and safety gates. Its distribution record is
[Nightingale TestFlight and iOS distribution](../nightingale/TESTFLIGHT.md). The historical
`hummingbird/iosPatientApp` target remains migration evidence and is not a Nightingale or
TestFlight source.
