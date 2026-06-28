# Summit Regional Medical Center — Level I Trauma Academic Medical Center CAD Model

Generated: 2026-06-28

This directory contains a concept-level CAD/BIM and navigable web model for
Summit Regional Medical Center, a Tier 1 / ACS Level I trauma academic medical
center planning model.

## Standards Strategy

- **IFC4** is used for BIM semantics and asset/spatial hierarchy. buildingSMART
  identifies IFC as the open, vendor-neutral ISO standard for digital
  descriptions of buildings and infrastructure.
- **DXF AC1027** is included as a conventional CAD mesh exchange file using
  layer-separated 3DFACE geometry.
- **glTF 2.0 / GLB** is used for the navigable browser model because Khronos
  positions glTF as an efficient runtime delivery format for 3D scenes.
- **OGC 3D Tiles 1.1** is included as a single-tile wrapper for scalable BIM/GIS
  streaming workflows.
- **Catalog JSON** keeps searchable object metadata alongside the geometry.

## Files

- `bim/hospital_model.ifc` - IFC4 semantic model.
- `cad/hospital_model.dxf` - CAD exchange mesh model.
- `model/hospital_model.glb` - binary glTF model used by the viewer.
- `3dtiles/tileset.json` - 3D Tiles wrapper for the GLB payload.
- `data/model_catalog.json` - searchable object and standards metadata.
- `viewer/index.html` - Three.js CAD navigator.
- `generate_hospital_cad_model.py` - deterministic generator.

## Model Counts

```json
{
  "objects": 1472,
  "beds": 500,
  "patient_rooms": 500,
  "corridors": 178,
  "elevators": 35,
  "ed_positions": 148,
  "procedure_rooms": 44
}
```

## Scope

This is a detailed concept model, not a stamped construction document. It is
intended for planning, simulation, service-line adjacency review, digital-twin
prototyping, and standards-driven discussion. A construction-ready model would
need licensed architecture/engineering authoring, equipment vendor families,
structural/MEP coordination, code review, clash detection, commissioning data,
and AHJ approval.

## Regenerate

```bash
python3 docs/research/hospital-cad-model/generate_hospital_cad_model.py
```

## Verify Viewer

Start a local server from this directory:

```bash
python3 -m http.server 8765 --bind 127.0.0.1
```

Then run:

```bash
PLAYWRIGHT_MODULE=file:///path/to/playwright/index.js node docs/research/hospital-cad-model/verify_viewer.mjs
```

The verifier writes `verification/results.json` plus desktop and mobile
screenshots, and checks that the WebGL canvas is nonblank.
