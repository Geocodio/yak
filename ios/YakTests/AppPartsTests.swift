import Foundation
import Testing
import YakKit

@testable import Yak

@MainActor @Suite struct AppPartsTests {
    private func context() -> AppPartsContext {
        let tokens = InMemoryTokenStore()
        let instances = InstanceStore(defaults: PushMemoryDefaults(), tokens: tokens, now: { Date() })
        return AppPartsContext(
            instances: instances, tokens: tokens,
            makeEnvironment: {
                .app(version: "0.0.0", system: "Unit test", files: InMemoryFileStore(), allowsInsecureRealtime: false)
            })
    }

    @Test func pushIsTheOnlyObserverOfAFreshApp() {
        let parts = AppParts.make(storage: nil, isTestMode: false, context: context())
        #expect(parts.observers.count == 1)
        #expect(parts.observers.first is PushController)
        #expect(parts.storage == nil)
    }

    @Test func testModeAndReleaseBuildTheSameParts() {
        let release = AppParts.make(storage: nil, isTestMode: false, context: context())
        let test = AppParts.make(storage: nil, isTestMode: true, context: context())
        #expect(release.partTypeNames == test.partTypeNames)
    }
}

#if DEBUG
    @Suite struct TestModeArgumentTests {
        private func defaults() -> UserDefaults {
            let suite = "tests.arguments.\(UUID().uuidString)"
            let defaults = UserDefaults(suiteName: suite)!
            defaults.removePersistentDomain(forName: suite)
            return defaults
        }

        @Test func argumentsAreBounded() {
            let defaults = defaults()
            defaults.set(String(repeating: "a", count: 10_000), forKey: "yakTestLong")
            defaults.set(String(repeating: "a", count: 200), forKey: "yakTestEdge")
            defaults.set("posture", forKey: "yakTestShort")
            defaults.set("", forKey: "yakTestEmpty")
            #expect(TestMode.value("yakTestLong", in: defaults) == nil)
            #expect(TestMode.value("yakTestEdge", in: defaults)?.count == 200)
            #expect(TestMode.value("yakTestShort", in: defaults) == "posture")
            #expect(TestMode.value("yakTestEmpty", in: defaults) == nil)
            #expect(TestMode.value("yakTestMissing", in: defaults) == nil)
        }

        @Test func aFlagNeedsYes() {
            let defaults = defaults()
            defaults.set("YES", forKey: "yakTestOn")
            defaults.set("NO", forKey: "yakTestOff")
            defaults.set("true", forKey: "yakTestTrue")
            #expect(TestMode.flag("yakTestOn", in: defaults))
            #expect(!TestMode.flag("yakTestOff", in: defaults))
            #expect(!TestMode.flag("yakTestTrue", in: defaults))
            #expect(!TestMode.flag("yakTestMissing", in: defaults))
        }
    }
#endif
