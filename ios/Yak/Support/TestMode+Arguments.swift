#if DEBUG
    import Foundation

    /// The launch arguments of test mode, read the same way by every feature. A feature's own arguments live in
    /// `TestMode+<Feature>.swift` and use these two readers, naming the argument without its dash
    /// (`flag("yakTestPosture")` reads `-yakTestPosture YES`).
    extension TestMode {
        /// The longest argument value that is read.
        static let maximumValueLength = 200

        /// True when `-<name> YES` was passed.
        static func flag(_ name: String, in defaults: UserDefaults = .standard) -> Bool {
            defaults.string(forKey: name) == "YES"
        }

        /// The value of `-<name> <value>`, or nil when it is missing, empty or longer than 200 characters.
        static func value(_ name: String, in defaults: UserDefaults = .standard) -> String? {
            guard let text = defaults.string(forKey: name), !text.isEmpty, text.count <= maximumValueLength else {
                return nil
            }
            return text
        }
    }
#endif
