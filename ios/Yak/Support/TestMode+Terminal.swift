import YakKit

#if DEBUG
    extension TestMode {
        /// `-yakTestShortWaits YES` gives the terminal a 6-second wait cap and a 12-second restore cap.
        static func terminalTimings() -> TerminalTimings { flag("yakTestShortWaits") ? .shortWaits : .standard }
    }
#endif

extension TerminalTimings {
    /// The timings this run of the app uses: the standard ones, or the short ones in a test run that asks for them.
    static var forThisRun: TerminalTimings {
        #if DEBUG
            TestMode.terminalTimings()
        #else
            .standard
        #endif
    }
}
