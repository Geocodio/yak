import Foundation
import YakKit

#if DEBUG
    extension TestMode {
        /// The instance id of a run that keeps its files, so the cache of one launch is found by the next.
        static let keptInstanceID = UUID(uuidString: "00000000-0000-4000-8000-000000000001")!

        /// `-yakTestKeepFiles fresh|again` keeps the app's files in a folder that survives a relaunch: `fresh`
        /// empties it first, `again` reads what the last launch left. Without it the files live in memory.
        static func keepsFiles(in defaults: UserDefaults = .standard) -> Bool {
            defaults.string(forKey: "yakTestKeepFiles") != nil
        }

        static func testFiles(in defaults: UserDefaults = .standard) -> any FileStore {
            guard let mode = defaults.string(forKey: "yakTestKeepFiles") else { return InMemoryFileStore() }
            let root = FileManager.default.temporaryDirectory.appending(path: "yak-test-files")
            if mode == "fresh" { emptyOnce(root) }
            return DiskFileStore(root: root)
        }

        /// The folder is emptied the first time a launch asks, not on every call of the same launch.
        private static let emptiedFolders = EmptiedFolders()

        private static func emptyOnce(_ root: URL) { emptiedFolders.empty(root) }

        /// `-yakTestShortWaits YES` gives the files a 6-second wake cap and a 12-second restore cap.
        static func fileTimings() -> FileTimings { flag("yakTestShortWaits") ? .shortWaits : .standard }
    }

    private final class EmptiedFolders: @unchecked Sendable {
        private let lock = NSLock()
        private var done = false

        func empty(_ root: URL) {
            lock.withLock {
                guard !done else { return }
                done = true
                try? FileManager.default.removeItem(at: root)
            }
        }
    }
#endif

extension FileTimings {
    /// The timings this run of the app uses: the standard ones, or the short ones in a test run that asks for them.
    static var forThisRun: FileTimings {
        #if DEBUG
            TestMode.fileTimings()
        #else
            .standard
        #endif
    }
}
