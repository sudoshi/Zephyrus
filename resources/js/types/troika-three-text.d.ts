// Minimal ambient types for `troika-three-text` (ships none, no @types exists).
// Covers only the SDF-billboard surface the 4D Navigator's unit labels use
// (E2). Kept local so NavigatorScene.ts stays strict-typed without an `any`.
declare module 'troika-three-text' {
  import type { Mesh, Material, Color } from 'three';

  export class Text extends Mesh {
    text: string;
    font: string | undefined;
    fontSize: number;
    color: number | string | Color;
    anchorX: number | 'left' | 'center' | 'right' | string;
    anchorY: number | 'top' | 'top-baseline' | 'middle' | 'bottom-baseline' | 'bottom' | string;
    outlineWidth: number | string;
    outlineColor: number | string | Color;
    material: Material & { transparent: boolean; depthWrite: boolean; opacity: number };
    sync(callback?: () => void): void;
    dispose(): void;
  }
}
