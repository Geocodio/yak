import YakKit

#if DEBUG
    extension TestMode {
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
