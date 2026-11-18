#if DEBUG
    import Foundation
    import SwiftUI
    import YakKit

    /// The files of test mode: background uploads need real files, so they live in a folder under the temporary
    /// directory. `-yakTestKeepFiles <run id>` keeps the folder, the instance list and the upload records across a
    /// relaunch; the UI test passes the same run id on both launches. `fresh` empties the shared `workspace` run
    /// first and `again` reads what the last launch left. Without the flag every launch starts empty.
    extension TestMode {
        struct KeptStores {
            let files: DiskFileStore
            let defaults: any KeyValueStore
            let runID: String
        }

        static func keptStores() -> KeptStores {
            let requested = value("yakTestKeepFiles").map {
                String($0.filter { $0.isLetter || $0.isNumber || $0 == "-" })
            }
            let startsFresh = requested == "fresh"
            let keep = requested.map { ["fresh", "again"].contains($0) ? "workspace" : $0 }
            let runID = keep.flatMap { $0.isEmpty ? nil : $0 } ?? UUID().uuidString
            let folder = URL(fileURLWithPath: NSTemporaryDirectory()).appendingPathComponent("yak-test/\(runID)")
            let marker = folder.appendingPathComponent("run-started")
            let manager = FileManager.default
            if startsFresh { try? manager.removeItem(at: folder) }
            if !manager.fileExists(atPath: marker.path) {
                try? manager.removeItem(at: folder)
                try? manager.createDirectory(at: folder, withIntermediateDirectories: true)
                manager.createFile(atPath: marker.path, contents: Data())
                UserDefaults(suiteName: "yak-test-\(runID)")?.removePersistentDomain(forName: "yak-test-\(runID)")
            }
            var defaults: any KeyValueStore = MemoryDefaults()
            if keep != nil, let suite = UserDefaults(suiteName: "yak-test-\(runID)") { defaults = suite }
            return KeptStores(files: DiskFileStore(root: folder), defaults: defaults, runID: runID)
        }
    }

    /// An invisible element whose value is the paired instance's id, so a test can check that a relaunch kept it.
    struct TestInstanceID: View {
        let model: AppModel
        @State private var identifier = ""

        var body: some View {
            Color.clear.frame(width: 2, height: 2)
                .accessibilityElement().accessibilityLabel(Text(verbatim: "Instance")).accessibilityValue(identifier)
                .accessibilityIdentifier("testMode.instanceID")
                .task(id: model.route) { identifier = await model.instances.all().first?.id.uuidString ?? "" }
        }
    }
#endif
