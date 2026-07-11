# Clean-room provenance (Arena OCPM)

The Arena's OCPM capabilities (filter engine, OC Petri-net discovery, QEL capacity)
are **clean-room reimplementations** inspired by ocelescope
(https://github.com/promi4s/ocelescope), which is AGPL-3.0.

DO NOT:
- add `ocelescope` to `requirements.txt` or any dependency manifest
- copy ocelescope source into this repository

Reimplement patterns from the public API shape and the OCPM literature
(Berti & van der Aalst, object-centric process mining). All code here is
Apache-2.0 under the repository license.
