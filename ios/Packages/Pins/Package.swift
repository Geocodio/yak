// swift-tools-version: 6.0
import PackageDescription

// Pins the app's Swift packages in one place Dependabot and `swift package resolve` can read. It is
// resolved and never built or added to project.yml. Every `exact:` version here equals the
// `exactVersion` of the same package in project.yml or a project-*.yml file; scripts/check-rules.sh
// fails when they differ. Add each package the app uses to `dependencies` and to the Pins target.
let package = Package(
    name: "Pins",
    platforms: [.iOS("27.0")],
    products: [.library(name: "Pins", targets: ["Pins"])],
    dependencies: [
        .package(url: "https://github.com/swiftlang/swift-markdown", exact: "0.9.0"),
        .package(url: "https://github.com/migueldeicaza/SwiftTerm", exact: "1.99.2"),
    ],
    targets: [
        .target(
            name: "Pins",
            dependencies: [
                .product(name: "Markdown", package: "swift-markdown"),
                .product(name: "SwiftTerm", package: "SwiftTerm"),
            ]
        )
    ],
    swiftLanguageModes: [.v6]
)
