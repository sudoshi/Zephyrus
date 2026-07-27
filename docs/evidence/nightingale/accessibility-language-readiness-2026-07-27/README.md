# Nightingale accessibility and language-readiness evidence — 2026-07-27

**Evidence class:** non-PHI local engineering evidence. This is not accessibility
conformance, translation approval, clinical review, signed distribution, or live-use
authorization.

The decision record, methods, test results, hashes, corrected failures, and explicit open
gates are in the
[foundation accessibility and language-readiness evidence](../../../nightingale/FOUNDATION-ACCESSIBILITY-LANGUAGE-READINESS-2026-07-27.md).

## Retained files

| File                                                                           | Purpose                                                                                           | SHA-256                                                            |
| ------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------ |
| [ios-debug-double-length.png](./screenshots/ios-debug-double-length.png)       | iOS Debug rendered double-length copy and reflow                                                  | `05023f1954ec978e478bd79c6a9d5c0a1c0dc7fa8cd2838de7994011d6dafbdd` |
| [ios-debug-rtl.png](./screenshots/ios-debug-rtl.png)                           | iOS Debug right-to-left layout with governed English fallback                                     | `e7c0c62341732910930f5030587a2a2f7f7e99158764c15f0e2edd5b91387ba7` |
| [android-debug-ar-xb-secure.png](./screenshots/android-debug-ar-xb-secure.png) | Android secure-capture result; black because `FLAG_SECURE` remains active                         | `c35bacdb98b522206335afa5b9baffd2e4e3352a40749bb747e469cd403af514` |
| [android-debug-en-xa.xml](./hierarchies/android-debug-en-xa.xml)               | Android Debug accessibility hierarchy with expanded `en-XA` copy                                  | `3bbf3178a99659de488df1d7184cfb3e552ca418159ff5d8cd0301a2e770794a` |
| [android-debug-ar-xb.xml](./hierarchies/android-debug-ar-xb.xml)               | Android Debug accessibility hierarchy with bidirectional `ar-XB` copy and mirrored content bounds | `720d2c8d9b31e3f850dbee46def8e1e139f797a30b242e396d58d8bc749f22fc` |

All states contain only the offline foundation's nonclinical source copy. The files
contain no patient, principal, encounter, care-team, credential, token, or production
source data.
