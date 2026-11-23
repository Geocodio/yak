import Foundation

struct LicenseEntry: Identifiable, Equatable {
    let id: String
    let name: String
    let licenseName: String
    /// The text file in `Yak/Settings/Licenses`, without its extension.
    let resource: String
}

/// One line per package the app links. `scripts/rules.d/settings-privacy.sh` checks the list against `project.yml`.
enum LicenseList {
    static let entries: [LicenseEntry] = [
        LicenseEntry(id: "SwiftTerm", name: "SwiftTerm", licenseName: "MIT License", resource: "SwiftTerm"),
        LicenseEntry(
            id: "swift-markdown", name: "swift-markdown", licenseName: "Apache License 2.0",
            resource: "swift-markdown"),
        LicenseEntry(
            id: "swift-cmark", name: "swift-cmark", licenseName: "BSD 2-Clause License", resource: "swift-cmark"),
        LicenseEntry(
            id: "IBMPlex", name: "IBM Plex Sans and IBM Plex Mono", licenseName: "SIL Open Font License 1.1",
            resource: "IBMPlex"),
    ]

    /// The package's own license text, or nil when this build does not hold it.
    static func text(for entry: LicenseEntry, bundle: Bundle) -> String? {
        guard let url = bundle.url(forResource: entry.resource, withExtension: "txt") else { return nil }
        do {
            return try String(contentsOf: url, encoding: .utf8)
        } catch {
            return nil
        }
    }
}
