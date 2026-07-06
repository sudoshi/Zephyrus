import SceneKit
import simd
import SwiftUI

/// How the native 3D twin tints its segments. Service line = "what care happens here"
/// (the server-driven categorical palette); bed status = the live occupied/dirty/blocked
/// heat. Both are paired with a labelled legend so status is never color-alone.
enum FlowColorMode: String, CaseIterable, Identifiable {
    case serviceLine
    case bedStatus

    var id: String { rawValue }

    var label: String {
        switch self {
        case .serviceLine: return "Service line"
        case .bedStatus: return "Bed status"
        }
    }
}

/// The native 3D twin (SceneKit) — the foundation of the NATIVE-4D-VIEWER
/// (docs/hummingbird/NATIVE-4D-VIEWER-PLAN.md, Phase B). Renders the hospital as
/// exploded floor plates of room/bed segments placed by their facility-space 3D
/// centroids (the `/flow/spaces3d` asset), with free orbit / pinch / pan camera.
///
/// Segments are colored by the selected `colorMode` — by service line (the server's
/// `service_lines` legend, so iOS/Android never drift) or by live bed status. Toggling
/// the mode recolors in place and keeps the user's camera orbit; only a dataset/floor
/// change reframes. Patient tokens, duty markers, and Chronobar-driven time layer on
/// in Phase C. This replaces the 2.5D FloorPlate/HouseStack renderers (deleted in
/// Phase D once every persona reaches 3D parity).
struct Flow3DView: UIViewRepresentable {
    let spaces: FlowSpaces3dDocument
    var bedStatuses: [FlowBedStatus] = []
    var colorMode: FlowColorMode = .serviceLine
    /// nil = whole-house exploded stack; set = descend to one floor.
    var selectedFloor: Int?

    func makeUIView(context: Context) -> SCNView {
        let view = SCNView()
        view.allowsCameraControl = true // free orbit / pinch / pan
        view.autoenablesDefaultLighting = false
        view.antialiasingMode = .multisampling4X
        view.backgroundColor = UIColor(red: 0.07, green: 0.078, blue: 0.078, alpha: 1) // navigator bg
        view.rendersContinuously = false
        rebuild(view, context: context)
        return view
    }

    func updateUIView(_ view: SCNView, context: Context) {
        // Only rebuild geometry (and reframe the camera) when the dataset or descended floor
        // changes. A pure color-mode / bed-status change recolors in place so the user keeps
        // their orbit — rebuilding would snap the camera back to the framing pose.
        if context.coordinator.builtKey == geometryKey {
            recolor(view)
        } else {
            rebuild(view, context: context)
        }
    }

    func makeCoordinator() -> Coordinator { Coordinator() }

    /// Remembers which geometry (dataset version × floor) is currently in the scene, so
    /// updateUIView can tell a recolor from a rebuild.
    final class Coordinator { var builtKey: String? }

    private var geometryKey: String { "\(spaces.version)|\(selectedFloor.map(String.init) ?? "all")" }

    /// Build the scene AND point the view's camera at the framing camera we placed
    /// (with allowsCameraControl on, SceneKit needs an explicit pointOfView or it
    /// starts from a default that misses the geometry — the classic "black view").
    private func rebuild(_ view: SCNView, context: Context) {
        let scene = Self.buildScene(spaces: spaces, bedStatuses: bedStatuses,
                                    colorMode: colorMode, selectedFloor: selectedFloor)
        view.scene = scene
        view.pointOfView = scene.rootNode.childNode(withName: "flow-camera", recursively: true)
        context.coordinator.builtKey = geometryKey
    }

    /// Recolor existing segment nodes without touching geometry or the camera.
    private func recolor(_ view: SCNView) {
        guard let root = view.scene?.rootNode else { return }
        let legend = spaces.serviceLines
        let statusByBed = Self.statusByBed(bedStatuses)
        let bySpaceRef = Dictionary(spaces.spaces.map { ($0.spaceRef, $0) }, uniquingKeysWith: { a, _ in a })
        for node in root.childNodes {
            guard let name = node.name, let geometry = node.geometry, let space = bySpaceRef[name] else { continue }
            Self.paint(geometry, color: Self.color(for: space, mode: colorMode, legend: legend, statusByBed: statusByBed))
        }
    }

    // MARK: - Scene

    private static func buildScene(spaces: FlowSpaces3dDocument, bedStatuses: [FlowBedStatus],
                                   colorMode: FlowColorMode, selectedFloor: Int?) -> SCNScene {
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

        let legend = spaces.serviceLines
        let statusByBed = statusByBed(bedStatuses)

        var lo = SIMD3<Float>(repeating: .greatestFiniteMagnitude)
        var hi = SIMD3<Float>(repeating: -.greatestFiniteMagnitude)
        for space in visible {
            let p = SIMD3<Float>(Float(space.centroidM.x) - cx, Float(rank[space.floor] ?? 0) * gap, Float(space.centroidM.z) - cz)
            let color = color(for: space, mode: colorMode, legend: legend, statusByBed: statusByBed)
            let node = segmentNode(for: space, color: color)
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

    private static func segmentNode(for space: FlowSpace3d, color: UIColor) -> SCNNode {
        let (w, h, d): (CGFloat, CGFloat, CGFloat) = {
            switch space.category {
            case "bed", "bay": return (4, 1.4, 4)
            case "room", "procedure_room", "imaging": return (7, 1.0, 7)
            case "corridor", "vertical_transport": return (5, 0.5, 5)
            case "floor", "zone": return (3, 0.3, 3)
            default: return (5, 0.9, 5)
            }
        }()

        let box = SCNBox(width: w, height: h, length: d, chamferRadius: 0.4)
        let node = SCNNode(geometry: box)
        paint(box, color: color)
        node.name = space.spaceRef // for raycast picking (Phase C inspector)
        return node
    }

    private static func paint(_ geometry: SCNGeometry, color: UIColor) {
        let material = SCNMaterial()
        material.diffuse.contents = color
        material.emission.contents = color.withAlphaComponent(0.28)
        geometry.materials = [material]
    }

    private static func statusByBed(_ statuses: [FlowBedStatus]) -> [Int: String] {
        Dictionary(statuses.map { ($0.bedId, $0.status) }, uniquingKeysWith: { a, _ in a })
    }

    // MARK: - Color

    /// The resolved segment color for the active mode. Structural spaces (corridors, floor
    /// slabs) always stay neutral so the clinical segments read as the figure.
    private static func color(for space: FlowSpace3d, mode: FlowColorMode,
                              legend: [String: FlowServiceLineStyle], statusByBed: [Int: String]) -> UIColor {
        switch space.category {
        case "corridor", "vertical_transport":
            return UIColor(white: 0.34, alpha: 0.85)
        case "floor", "zone":
            return UIColor(white: 0.22, alpha: 0.5)
        default:
            break
        }

        switch mode {
        case .bedStatus:
            switch space.category {
            case "bed", "bay":
                return bedColor(space.bedId.flatMap { statusByBed[$0] })
            default:
                return UIColor(white: 0.62, alpha: 0.92) // rooms are context in bed-status mode
            }
        case .serviceLine:
            let code = space.serviceLine ?? "unassigned"
            let hex = legend[code]?.color ?? legend["unassigned"]?.color ?? "#556072"
            return UIColor(flowHex: hex)
        }
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
