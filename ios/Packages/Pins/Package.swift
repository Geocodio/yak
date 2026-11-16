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
    dependencies: [],
    targets: [
        .target(name: "Pins")
    ],
    swiftLanguageModes: [.v6]
)
