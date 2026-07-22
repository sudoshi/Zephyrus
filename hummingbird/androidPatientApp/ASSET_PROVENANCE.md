# Hummingbird Patient Android background asset provenance

Last verified: 2026-07-21

These four JPEGs are bundled in `app/src/main/res/drawable-nodpi/`; the app does
not download, rotate, or remotely select patient backgrounds. Each sRGB app
derivative is downsampled to a 2,000-pixel maximum dimension and encoded at
JPEG quality 82. Together they are 1,485,385 bytes, 32.6% smaller than the
2,203,267-byte sources. Android renders
the image with a deterministic centered `ContentScale.Crop`, 46% image alpha,
and a surface-color vertical scrim of 68%, 84%, and 96% opacity from top to
bottom. Images are decorative (`contentDescription = null`); content and
safety state remain complete if an image cannot be perceived.

| App asset                                 | Repository source                                    | Use                                                                 | Source -> app pixels       | Source SHA-256                                                     | App SHA-256                                                        |
| ----------------------------------------- | ---------------------------------------------------- | ------------------------------------------------------------------- | -------------------------- | ------------------------------------------------------------------ | ------------------------------------------------------------------ |
| `patient_hummingbird_calm_green.jpg`      | `public/images/auth/hummingbirds/hummingbird-01.jpg` | Loading and empty                                                   | 1689 x 2400 -> 1407 x 2000 | `9230a368fd2c0cab308280425b35b645b2a505871277a8136ec1c199cd53d6dc` | `d5dc322481721ac5a29a6fe34c777bf727385dcd9d3d5aad1674182b5ccbec2d` |
| `patient_hummingbird_airy_flight.jpg`     | `public/images/auth/hummingbirds/hummingbird-06.jpg` | Welcome, enrollment, sign-in, Today, unavailable, recoverable error | 1600 x 2400 -> 1333 x 2000 | `65b640f035d8527d879f5b354e36eabb78ec3c9ca5c915356e05912a4aadc008` | `a400063289b0ec8b62a7059c3e1e10618da545fe50871b2f3ddb35935e958117` |
| `patient_hummingbird_care_connection.jpg` | `public/images/auth/hummingbirds/hummingbird-07.jpg` | Care Team and Messages                                              | 1600 x 2400 -> 1333 x 2000 | `b545c010ed87c9ee4150c616b6031f2e66b329bc0e339fbe4ac735d6f4236988` | `b70ba3d0626f27bd29311a3e70a8e306768fd3c316cfae2554765b89603dcfbd` |
| `patient_hummingbird_warm_motion.jpg`     | `public/images/auth/hummingbirds/hummingbird-12.jpg` | My Path                                                             | 1800 x 2400 -> 1500 x 2000 | `38e37231c4a14e3223823bbee531590aadd982bc7994c6538b02d291670b729d` | `b3f127a8fbd2754d69adc12432fc01f22a9096ee8850b8fdab10e769e5105fb8` |

## Crop and fallback policy

- The full source aspect ratio is retained in each derivative; there is no
  destructive pre-crop.
- `ContentScale.Crop` preserves aspect ratio and center alignment. Extra width
  is cropped symmetrically on tall phones; no screen applies pan, parallax, or
  animation.
- The scrim uses the active Material surface color, so the same deterministic
  treatment works in light and dark appearance. Cards use opaque Material
  semantic containers above the scrim.
- The Material surface and all clinical/safety content remain present without
  relying on image pixels, color alone, or image semantics.

## Scenic-layer contrast verification

A pixel-by-pixel pass over every optimized JPEG composited the 46% image alpha
over the active surface and then applied the exact 68% -> 84% -> 96% vertical
surface scrim. Because a centered runtime crop can only remove analyzed pixels,
the full-frame minimum is conservative for every phone crop.

| Foreground semantic color | Light minimum | Dark minimum | WCAG AA normal-text gate |
| ------------------------- | ------------- | ------------ | ------------------------ |
| `onSurface`               | 11.596:1      | 9.683:1      | Pass (>= 4.5:1)          |
| `onSurfaceVariant`        | 6.637:1       | 7.359:1      | Pass (>= 4.5:1)          |

Opaque cards and controls use paired Material semantic container/on-container
colors rather than the photographic layer. Status meaning is repeated in text
and is not encoded by color or imagery alone.

## Release gate

Git history identifies commit `cd6d1b048ad44763f88e7f1a3474657645a8559b`
(`Beautify login with hummingbird slideshow`) as the source introduction, but
the repository contains no photographer, source URL, license, or attribution
record for these files. Product/legal must establish usage rights and any
required attribution before a patient-app release. Release ownership is not
yet recorded; the mobile product owner must assign and approve it. Until both
facts are recorded, asset licensing and release ownership remain open gates.
