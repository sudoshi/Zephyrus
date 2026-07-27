# Nightingale background asset governance

This directory contains the governed, app-ready derivatives of the seven images
supplied for Nightingale backgrounds. The images are decorative visual atmosphere,
not clinical content. Neither platform may infer, display, or announce a bird
species, patient state, care-pathway state, risk level, or recommended action from
the selected image.

## Runtime contract

- Both native apps consume the exact same seven JPEGs from
  `optimized/drawable-nodpi/`.
- The selected image remains stable for the patient’s local Gregorian calendar
  day. Both platforms select `floorMod(epoch day, 7)`, so the same date maps to
  the same catalog entry on iOS and Android. There is no carousel, parallax,
  cross-fade cycle, or time-based motion.
- The background is hidden from the accessibility tree and cannot receive input.
- “Hide decorative imagery” removes every background photo and product-mark
  decoration without removing text or controls.
- iOS also removes the imagery for Reduce Transparency or Increased Contrast.
  Android removes it for the available high-contrast signal.
- All patient-readable content is placed on a governed surface or behind a strong
  system-surface scrim. Raw photography is never a text background by itself.

## Source and derivative lineage

[`backgrounds.v1.json`](backgrounds.v1.json) records each user-supplied source
filename, source SHA-256, original dimensions and byte count, plus the exact
derivative path, SHA-256, dimensions, and byte count.

The original images total approximately 33 MB and are not duplicated in Git.
Their immutable lineage is retained in the manifest. The committed derivatives
total 4,439,974 bytes.

Derivation:

1. JPEG quality 82 through macOS `sips`.
2. Preserve original dimensions when the long edge is at most 2400 pixels;
   otherwise constrain the long edge to 2400 pixels without upscaling.
3. Run `jpegtran -copy none -optimize -progressive` to remove EXIF, XMP,
   Photoshop/IPTC, comments, GPS-capable metadata, and other application markers
   while losslessly optimizing the already-encoded pixels.

The repository verifier parses the JPEG structure without a third-party package
and fails closed on any ungoverned file, hash/size/dimension drift, upscaling,
non-progressive derivative, or metadata-bearing marker:

```bash
node scripts/ci/verify-nightingale-background-assets.mjs . --self-test
```

## Distribution gate

The project owner supplied these images for product use, but a durable
license/attribution record is not present in this repository. These assets are
therefore admitted to the non-live foundation only. Before any external,
production, App Store, Play Store, pilot, or marketing distribution, the release
owner must record the source archive location and applicable license or
attribution evidence, then deliberately revise the manifest and its verifier.
The current status must not be described as production-distribution approval.
