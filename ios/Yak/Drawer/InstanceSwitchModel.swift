import Foundation
import OSLog
import Observation

/// The state of one instance switch: one at a time, a spinner on the row under way, and the name of the
/// instance the last try failed for.
@MainActor @Observable final class InstanceSwitchModel {
    private(set) var switchingID: UUID?
    private(set) var failedName: String?
    var isSwitching: Bool { switchingID != nil }

    private let activate: (UUID) async throws -> Void
    private let finish: () async -> Void

    init(activate: @escaping (UUID) async throws -> Void, finish: @escaping () async -> Void) {
        self.activate = activate
        self.finish = finish
    }

    /// Returns true when the switch went through. A second call while one is under way does nothing.
    func switchTo(id: UUID, name: String) async -> Bool {
        guard !isSwitching else { return false }
        switchingID = id
        failedName = nil
        do {
            try await activate(id)
        } catch {
            Logger(subsystem: "Yak", category: "InstanceSwitcher")
                .error("Switching instance failed: \(String(describing: type(of: error)), privacy: .public)")
            failedName = name
            switchingID = nil
            return false
        }
        await finish()
        return true
    }
}
