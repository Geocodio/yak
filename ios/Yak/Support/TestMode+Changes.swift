#if DEBUG
    import Foundation

    extension TestMode {
        /// `-yakTestOpenTask <id>` opens that task's screen as a task link does.
        static func taskToOpen(in defaults: UserDefaults = .standard) -> String? {
            value("yakTestOpenTask", in: defaults)
        }
    }
#endif
