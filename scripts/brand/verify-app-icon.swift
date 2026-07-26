#!/usr/bin/swift

import AppKit
import Foundation

enum VerificationError: Error, LocalizedError {
    case usage
    case unreadableImage(String)
    case incorrectDimensions(String)
    case unexpectedAlphaChannel(String)
    case missingAlphaChannel(String)
    case missingOpaquePixels(String)
    case missingTransparentPixels(String)
    case nonMonochromePixel(String)

    var errorDescription: String? {
        switch self {
        case .usage:
            return "Usage: verify-app-icon.swift <image.png> <pixels> <opaque|transparent|monochrome>"
        case let .unreadableImage(path):
            return "\(path) is not a readable bitmap image."
        case let .incorrectDimensions(message),
             let .unexpectedAlphaChannel(message),
             let .missingAlphaChannel(message),
             let .missingOpaquePixels(message),
             let .missingTransparentPixels(message),
             let .nonMonochromePixel(message):
            return message
        }
    }
}

func verify() throws {
    let arguments = Array(CommandLine.arguments.dropFirst())
    guard arguments.count == 3,
          let expectedPixels = Int(arguments[1]),
          expectedPixels > 0,
          ["opaque", "transparent", "monochrome"].contains(arguments[2])
    else {
        throw VerificationError.usage
    }

    let path = arguments[0]
    let style = arguments[2]
    let imageURL = URL(fileURLWithPath: path)
    guard
        let imageData = try? Data(contentsOf: imageURL),
        let bitmap = NSBitmapImageRep(data: imageData)
    else {
        throw VerificationError.unreadableImage(path)
    }

    guard bitmap.pixelsWide == expectedPixels, bitmap.pixelsHigh == expectedPixels else {
        throw VerificationError.incorrectDimensions(
            "\(path) is \(bitmap.pixelsWide)x\(bitmap.pixelsHigh), expected \(expectedPixels)x\(expectedPixels)."
        )
    }

    if style == "opaque" {
        guard !bitmap.hasAlpha else {
            throw VerificationError.unexpectedAlphaChannel(
                "\(path) must be an RGB PNG without an alpha channel."
            )
        }
        return
    }

    guard bitmap.hasAlpha else {
        throw VerificationError.missingAlphaChannel(
            "\(path) must preserve an alpha channel."
        )
    }

    var hasOpaquePixel = false
    var hasTransparentPixel = false
    for row in 0 ..< bitmap.pixelsHigh {
        for column in 0 ..< bitmap.pixelsWide {
            guard let color = bitmap.colorAt(x: column, y: row)?.usingColorSpace(.deviceRGB)
            else {
                throw VerificationError.unreadableImage(path)
            }
            if color.alphaComponent <= 0.001 {
                hasTransparentPixel = true
                continue
            }
            hasOpaquePixel = true
            if style == "monochrome",
               color.redComponent < 0.999 ||
               color.greenComponent < 0.999 ||
               color.blueComponent < 0.999 {
                throw VerificationError.nonMonochromePixel(
                    "\(path) contains a non-white visible pixel at \(column),\(row)."
                )
            }
        }
    }

    guard hasOpaquePixel else {
        throw VerificationError.missingOpaquePixels(
            "\(path) contains no visible artwork."
        )
    }
    guard hasTransparentPixel else {
        throw VerificationError.missingTransparentPixels(
            "\(path) contains no transparent background."
        )
    }
}

do {
    try verify()
} catch {
    FileHandle.standardError.write(Data("error: \(error.localizedDescription)\n".utf8))
    exit(65)
}
