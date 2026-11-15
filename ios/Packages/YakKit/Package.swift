// swift-tools-version: 6.0
import PackageDescription

let package = Package(
    name: "YakKit",
    platforms: [.iOS("27.0"), .macOS("15.0")],
    products: [.library(name: "YakKit", targets: ["YakKit"])],
    targets: [
        .target(name: "YakKit"),
        .testTarget(name: "YakKitTests", dependencies: ["YakKit"], exclude: ["Scenarios"]),
    ],
    swiftLanguageModes: [.v6]
)
