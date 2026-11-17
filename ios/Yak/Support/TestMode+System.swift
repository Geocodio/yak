#if DEBUG
    import Foundation
    import YakKit

    /// What the fake authenticator answers for one ask.
    enum TestAuthentication: String, Equatable {
        case succeed, fail, cancel, slow
    }

    enum TestTips: Equatable { case hide, show }

    /// The launch arguments of the privacy features. Debug test mode only.
    struct TestSystemOptions: Equatable {
        /// `-yakTestBiometry faceID|touchID|opticID|none|noPasscode`
        var biometry: LockBiometry = .faceID
        var hasPasscode = true
        /// `-yakTestAuthentication succeed|fail|cancel|slow,...`: the fake answers in order, one per ask, and the
        /// last one repeats. At most the first 8 words count; an empty or unknown list is `succeed`.
        var authentication: [TestAuthentication] = [.succeed]
        /// `-yakTestCoverWhenActive YES`
        var coverWhenActive = false
        /// `-yakTestAppLock on`
        var appLockOn = false
        /// `-yakTestAppLockLimit <seconds>`
        var appLockLimit: TimeInterval?
        /// `-yakTestTips hide|show`
        var tips: TestTips = .hide
        /// `-yakTestBuildKind debug|testFlight|appStore`
        var buildKind: DebugBuildKind = .debug
        /// `-yakTestSpotlight failing`
        var spotlightFailing = false

        static func parseAuthentication(_ text: String?) -> [TestAuthentication] {
            let words = (text ?? "").split(separator: ",").prefix(8).compactMap {
                TestAuthentication(rawValue: String($0))
            }
            return words.isEmpty ? [.succeed] : words
        }

        static func current(_ defaults: UserDefaults = .standard) -> TestSystemOptions {
            var options = TestSystemOptions()
            switch TestMode.value("yakTestBiometry", in: defaults) {
            case "touchID": options.biometry = .touchID
            case "opticID": options.biometry = .opticID
            case "none": options.biometry = .none
            case "noPasscode":
                options.biometry = .none
                options.hasPasscode = false
            default: break
            }
            options.authentication = parseAuthentication(TestMode.value("yakTestAuthentication", in: defaults))
            options.coverWhenActive = TestMode.flag("yakTestCoverWhenActive", in: defaults)
            options.appLockOn = TestMode.value("yakTestAppLock", in: defaults) == "on"
            options.appLockLimit = TestMode.value("yakTestAppLockLimit", in: defaults).flatMap(TimeInterval.init)
            options.tips = TestMode.value("yakTestTips", in: defaults) == "show" ? .show : .hide
            switch TestMode.value("yakTestBuildKind", in: defaults) {
            case "testFlight": options.buildKind = .testFlight
            case "appStore": options.buildKind = .appStore
            default: break
            }
            options.spotlightFailing = TestMode.value("yakTestSpotlight", in: defaults) == "failing"
            return options
        }
    }
#endif
