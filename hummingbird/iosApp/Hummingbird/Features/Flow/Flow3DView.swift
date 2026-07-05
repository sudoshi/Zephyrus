import SceneKit
import simd
import SwiftUI

/// The native 3D twin (SceneKit) — the foundation of the NATIVE-4D-VIEWER
/// (docs/hummingbird/NATIVE-4D-VIEWER-PLAN.md, Phase B). Renders the hospital as
/// exploded floor plates of room/bed segments placed by their facility-space 3D
/// centroids (the `/flow/spaces3d` asset), with free orbit / pinch / pan camera.
///
/// Phase B: geometry + camera + bed-state coloring, to parity with the web
/// viewer's shell. Patient tokens, the duty markers, and Chronobar-driven time
/// are layered on in Phase C. This replaces the 2.5D FloorPlate/HouseStack
/// renderers (deleted in Phase D once every persona reaches 3D parity).
struct Flow3DView: UIViewRepresentable {
    let spaces: FlowSpaces3dDocument
    var bedStatuses: [FlowBedStatus] = []
    /// nil = whole-house exploded stack; set = descend to one floor.
    var selectedFloor: Int?

    func makeUIView(context: Context) -> SCNView {
        let view = SCNView()
        view.allowsCameraControl = true // free orbit / pinch / pan
        view.autoenablesDefaultLighting = false
        view.antialiasingMode = .multisampling4X
        view.backgroundColor = UIColor(red: 0.07, green: 0.078, blue: 0.078, alpha: 1) // navigator bg
        view.rendersContinuously = false
        apply(to: view)
        return view
    }

    func updateUIView(_ view: SCNView, context: Context) {
        // Rebuild only when the inputs that shape geometry change (bed state / floor / dataset).
        apply(to: view)
    }

    /// Build the scene AND point the view's camera at the framing camera we placed
    /// (with allowsCameraControl on, SceneKit needs an explicit pointOfView or it
    /// starts from a default that misses the geometry — the classic "black view").
    private func apply(to view: SCNView) {
        let scene = Self.buildScene(spaces: spaces, bedStatuses: bedStatuses, selectedFloor: selectedFloor)
        view.scene = scene
        view.pointOfView = scene.rootNode.childNode(withName: "flow-camera", recursively: true)
    }

    // MARK: - Scene

    private static func buildScene(spaces: FlowSpaces3dDocument, bedStatuses: [FlowBedStatus], selectedFloor: Int?) -> SCNScene {
        let scene = SCNScene()

        let ambient = SCNNode()
        ambient.light = SCNLight()
        ambient.light!.type = .ambient
        ambient.light!.intensity = 950
        scene.rootNode.addChildNode(ambient)

        let key = SCNNode()
        key.light = SCNLight()
        key.light!.type = .directional
        key.light!.intensity = 900
        key.eulerAngles = SCNVector3(-1.0, 0.4, 0)
        scene.rootNode.addChildNode(key)

        // Exploded-floor Y offset by floor rank (a true-3D take on the axonometric stack).
        let floors = Array(Set(spaces.spaces.map(\.floor))).sorted()
        var rank: [Int: Int] = [:]
        for (index, floor) in floors.enumerated() { rank[floor] = index }
        let gap: Float = 14

        let visible = selectedFloor.map { f in spaces.spaces.filter { $0.floor == f } } ?? spaces.spaces

        // Frame on the clinical core (beds/rooms) so campus/floor-slab outliers in the CAD
        // catalog can't blow up the camera distance and push everything sub-pixel.
        let coreCats: Set<String> = ["bed", "bay", "room", "procedure_room", "imaging"]
        let core = visible.filter { coreCats.contains($0.category) }
        let framing = core.isEmpty ? visible : core
        let cx = framing.map { Float($0.centroidM.x) }.reduce(0, +) / Float(max(framing.count, 1))
        let cz = framing.map { Float($0.centroidM.z) }.reduce(0, +) / Float(max(framing.count, 1))
        let framingRefs = Set(framing.map(\.spaceRef))

        let statusByBed = Dictionary(bedStatuses.map { ($0.bedId, $0.status) }, uniquingKeysWith: { a, _ in a })

        var lo = SIMD3<Float>(repeating: .greatestFiniteMagnitude)
        var hi = SIMD3<Float>(repeating: -.greatestFiniteMagnitude)
        for space in visible {
            let p = SIMD3<Float>(Float(space.centroidM.x) - cx, Float(rank[space.floor] ?? 0) * gap, Float(space.centroidM.z) - cz)
            let node = segmentNode(for: space, statusByBed: statusByBed)
            node.position = SCNVector3(p.x, p.y, p.z)
            scene.rootNode.addChildNode(node)
            if framingRefs.contains(space.spaceRef) {
                lo = simd_min(lo, p)
                hi = simd_max(hi, p)
            }
        }

        let center = (lo + hi) * 0.5
        let extent = min(max(hi.x - lo.x, hi.y - lo.y, hi.z - lo.z, 60), 900)
        scene.rootNode.addChildNode(cameraNode(center: center, extent: extent))
        return scene
    }

    private static func cameraNode(center: SIMD3<Float>, extent: Float) -> SCNNode {
        let camera = SCNCamera()
        camera.fieldOfView = 55
        camera.zNear = 1
        camera.zFar = Double(extent) * 12 + 2000
        let node = SCNNode()
        node.camera = camera
        node.position = SCNVector3(center.x + extent * 0.55, center.y + extent * 0.45, center.z + extent * 1.5)
        node.look(at: SCNVector3(center.x, center.y, center.z))
        node.name = "flow-camera"
        return node
    }

    private static func segmentNode(for space: FlowSpace3d, statusByBed: [Int: String]) -> SCNNode {
        let (w, h, d, color): (CGFloat, CGFloat, CGFloat, UIColor) = {
            switch space.category {
            case "bed", "bay":
                return (4, 1.4, 4, bedColor(space.bedId.flatMap { statusByBed[$0] }))
            case "room", "procedure_room", "imaging":
                return (7, 1.0, 7, UIColor(white: 0.62, alpha: 0.92))
            case "corridor", "vertical_transport":
                return (5, 0.5, 5, UIColor(white: 0.34, alpha: 0.85))
            case "floor", "zone":
                return (3, 0.3, 3, UIColor(white: 0.22, alpha: 0.55))
            default:
                return (5, 0.9, 5, UIColor(white: 0.5, alpha: 0.88))
            }
        }()

        let box = SCNBox(width: w, height: h, length: d, chamferRadius: 0.4)
        let material = SCNMaterial()
        material.diffuse.contents = color
        material.emission.contents = color.withAlphaComponent(0.28)
        box.materials = [material]

        let node = SCNNode(geometry: box)
        node.name = space.spaceRef // for raycast picking (Phase C inspector)
        return node
    }

    /// Bed status → the navigator's status palette (never color alone once the
    /// Phase C inspector labels it; here it is the at-a-glance heat).
    private static func bedColor(_ status: String?) -> UIColor {
        switch status {
        case "occupied": return UIColor(red: 0.47, green: 0.75, blue: 0.44, alpha: 1) // teal-green
        case "dirty": return UIColor(red: 0.88, green: 0.64, blue: 0.25, alpha: 1) // amber
        case "blocked": return UIColor(red: 0.94, green: 0.40, blue: 0.33, alpha: 1) // coral
        case "available": return UIColor(white: 0.86, alpha: 1)
        default: return UIColor(white: 0.7, alpha: 0.9)
        }
    }
}
