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
        /// `-yakTestBiometry faceID|touchID|opticID|none|other|noPasscode`
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
        /// `-yakTestLog empty|failed`: the log source has nothing, or cannot be read; otherwise the real log.
        var log: String?

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
            case "other": options.biometry = .other
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
            options.log = TestMode.value("yakTestLog", in: defaults)
            return options
        }
    }

    /// Answers `-yakTestAuthentication` in order, one per ask; the last answer repeats.
    final class TestDeviceOwnerAuthenticator: DeviceOwnerAuthenticator, @unchecked Sendable {
        private let options: TestSystemOptions
        private let lock = NSLock()
        private var asked = 0
        private var cancelled = false

        init(options: TestSystemOptions) { self.options = options }

        func capability() async -> CapabilityReading {
            .known(LockCapability(biometry: options.biometry, hasPasscode: options.hasPasscode))
        }

        func authenticate(reason: String) async -> DeviceOwnerAuthenticationResult {
            let answer = lock.withLock { () -> TestAuthentication in
                cancelled = false
                defer { asked += 1 }
                return options.authentication[min(asked, options.authentication.count - 1)]
            }
            guard options.hasPasscode else { return .passcodeNotSet }
            switch answer {
            case .succeed: return .success
            case .fail: return .failed
            case .cancel: return .cancelled
            case .slow:
                // Holds until the app leaves the foreground or 30 seconds pass.
                for _ in 0..<300 {
                    if lock.withLock({ cancelled }) { return .cancelled }
                    try? await Task.sleep(for: .milliseconds(100))
                }
                return .cancelled
            }
        }

        func cancel() { lock.withLock { cancelled = true } }
    }

    struct TestBuildKind: BuildKindSource {
        let value: DebugBuildKind
        func kind() async -> DebugBuildKind { value }
    }

    /// `-yakTestSpotlight failing`: every change fails.
    struct FailingSpotlightWriter: SpotlightWriter {
        struct Refused: Error {}
        func apply(_ change: SpotlightChange) async throws { throw Refused() }
    }

    struct TestLogSource: LogSource {
        let mode: String
        struct Unreadable: Error {}
        func read(since: Date, limit: Int, into collector: LogCollector) async throws {
            if mode == "failed" { throw Unreadable() }
        }
    }
#endif
