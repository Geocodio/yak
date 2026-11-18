import Foundation
import Testing

@testable import Yak

@MainActor @Suite struct BackgroundRefreshTests {
    @Test func stepsFitTheTaskBudget() async throws {
        let rig = try await makePushRig()
        let parts = AppParts.make(
            storage: nil, isTestMode: true,
            context: AppPartsContext(
                instances: rig.store, tokens: rig.tokens, makeEnvironment: { rig.environment },
                defaults: PushMemoryDefaults()))
        let total = parts.backgroundRefreshSteps.reduce(Duration.zero) { $0 + $1.budget }
        #expect(total <= BackgroundRefresh.totalBudget)
        #expect(!parts.backgroundRefreshSteps.isEmpty)
    }

    /// Test mode registers no task, and `BGTaskScheduler` raises on a submission without one, so going to the
    /// background must not submit.
    @Test func anUnregisteredRefreshIsNotSubmitted() {
        #expect(!BackgroundRefresh.schedule(identifier: "test.unregistered.refresh"))
    }
}
