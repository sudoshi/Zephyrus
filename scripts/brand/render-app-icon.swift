#!/usr/bin/swift

import AppKit
import Foundation

enum IconRenderError: Error, LocalizedError {
    case usage
    case invalidSize
    case invalidInset
    case invalidBackground
    case unreadableSource
    case pngEncodingFailed

    var errorDescription: String? {
        switch self {
        case .usage:
            return "Usage: render-app-icon.swift <source.png> <output.png> <pixels> <opaque|transparent> [insetFraction] [backgroundHex]"
        case .invalidSize:
            return "Icon pixel size must be a positive integer."
        case .invalidInset:
            return "Inset fraction must be between 0 and 0.45."
        case .invalidBackground:
            return "Background must be an RGB hex color such as #050B12."
        case .unreadableSource:
            return "Could not read the supplied icon source."
        case .pngEncodingFailed:
            return "Could not encode the icon as PNG."
        }
    }
}

func color(hex: String) throws -> NSColor {
    let normalized = hex.hasPrefix("#") ? String(hex.dropFirst()) : hex
    guard normalized.count == 6, let value = UInt32(normalized, radix: 16) else {
        throw IconRenderError.invalidBackground
    }

    return NSColor(
        calibratedRed: CGFloat((value >> 16) & 0xff) / 255,
        green: CGFloat((value >> 8) & 0xff) / 255,
        blue: CGFloat(value & 0xff) / 255,
        alpha: 1
    )
}

func render() throws {
    let arguments = Array(CommandLine.arguments.dropFirst())
    guard (4 ... 6).contains(arguments.count) else { throw IconRenderError.usage }

    let sourceURL = URL(fileURLWithPath: arguments[0])
    let outputURL = URL(fileURLWithPath: arguments[1])
    guard let pixelSize = Int(arguments[2]), pixelSize > 0 else {
        throw IconRenderError.invalidSize
    }
    guard let style = ["opaque", "transparent"].first(where: { $0 == arguments[3] }) else {
        throw IconRenderError.usage
    }
    let inset = CGFloat(Double(arguments.count > 4 ? arguments[4] : "0") ?? -1)
    guard (0 ... 0.45).contains(inset) else { throw IconRenderError.invalidInset }
    let background = try color(hex: arguments.count > 5 ? arguments[5] : "#050B12")
    guard let source = NSImage(contentsOf: sourceURL) else { throw IconRenderError.unreadableSource }

    let canvasSize = NSSize(width: pixelSize, height: pixelSize)
    guard let bitmap = NSBitmapImageRep(
        bitmapDataPlanes: nil,
        pixelsWide: pixelSize,
        pixelsHigh: pixelSize,
        bitsPerSample: 8,
        samplesPerPixel: 4,
        hasAlpha: true,
        isPlanar: false,
        colorSpaceName: .deviceRGB,
        bitmapFormat: [],
        bytesPerRow: 0,
        bitsPerPixel: 0
    ), let graphicsContext = NSGraphicsContext(bitmapImageRep: bitmap) else {
        throw IconRenderError.pngEncodingFailed
    }

    NSGraphicsContext.saveGraphicsState()
    NSGraphicsContext.current = graphicsContext
    defer { NSGraphicsContext.restoreGraphicsState() }

    if style == "opaque" {
        background.setFill()
    } else {
        NSColor.clear.setFill()
    }
    NSBezierPath(rect: NSRect(origin: .zero, size: canvasSize)).fill()

    let insetPixels = CGFloat(pixelSize) * inset
    let destination = NSRect(
        x: insetPixels,
        y: insetPixels,
        width: CGFloat(pixelSize) - (2 * insetPixels),
        height: CGFloat(pixelSize) - (2 * insetPixels)
    )
    source.draw(
        in: destination,
        from: NSRect(origin: .zero, size: source.size),
        operation: .sourceOver,
        fraction: 1,
        respectFlipped: false,
        hints: [.interpolation: NSImageInterpolation.high]
    )

    guard let png = bitmap.representation(using: NSBitmapImageRep.FileType.png, properties: [:])
    else {
        throw IconRenderError.pngEncodingFailed
    }

    try png.write(to: outputURL, options: Data.WritingOptions.atomic)
}

do {
    try render()
} catch {
    FileHandle.standardError.write(Data("error: \(error.localizedDescription)\n".utf8))
    exit(64)
}
