import Foundation
import YakKit

#if DEBUG
    extension TestMode {
        /// The instance id of a run that keeps its files, so the cache of one launch is found by the next.
        static let keptInstanceID = UUID(uuidString: "00000000-0000-4000-8000-000000000001")!

        /// `-yakTestKeepFiles` keeps the app's files in a folder that survives a relaunch (see `keptStores`).
        static func keepsFiles(in defaults: UserDefaults = .standard) -> Bool {
            defaults.string(forKey: "yakTestKeepFiles") != nil
        }

        /// `-yakTestShortWaits YES` gives the files a 6-second wake cap and a 12-second restore cap.
        static func fileTimings() -> FileTimings { flag("yakTestShortWaits") ? .shortWaits : .standard }
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
