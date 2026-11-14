// swift-tools-version: 6.0
import PackageDescription

let package = Package(
    name: "YakStub",
    platforms: [.macOS("15.0")],
    products: [.executable(name: "yak-stub", targets: ["YakStub"])],
    targets: [
        .executableTarget(name: "YakStub"),
        .testTarget(name: "YakStubTests", dependencies: ["YakStub"]),
    ],
    swiftLanguageModes: [.v6]
)
