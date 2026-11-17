import BackgroundTasks
import Foundation
import os

/// One piece of the background refresh. Features add their own through `AppParts`; the pieces run one after
/// another and their budgets together stay inside the task's time.
struct BackgroundRefreshStep: Sendable {
    let name: String
    let budget: Duration
    let run: @MainActor @Sendable (Duration) async -> Void
}

enum BackgroundRefresh {
    /// The system gives a refresh task about 30 seconds; 3 are kept to complete it.
    static let totalBudget: Duration = .seconds(22)
    static let earliestBegin: TimeInterval = 60 * 60

    /// The task identifier permitted in the Info.plist: the bundle identifier and `.refresh`.
    static var identifier: String { (Bundle.main.bundleIdentifier ?? "") + ".refresh" }

    /// The identifiers this process registered. Submitting any other one makes `BGTaskScheduler` raise, and test
    /// mode registers none.
    @MainActor private static var registered: Set<String> = []

    /// Called once from `YakApp.init`, before the app finishes launching.
    @MainActor static func register(identifier: String, steps: @escaping @MainActor () -> [BackgroundRefreshStep]) {
        let isRegistered = BGTaskScheduler.shared.register(forTaskWithIdentifier: identifier, using: nil) { task in
            guard let refresh = task as? BGAppRefreshTask else {
                task.setTaskCompleted(success: false)
                return
            }
            let handle = TaskHandle(refresh)
            let work = Task { @MainActor in
                for step in steps() {
                    if Task.isCancelled { break }
                    await step.run(step.budget)
                }
                schedule(identifier: identifier)
                handle.complete(success: !Task.isCancelled)
            }
            refresh.expirationHandler = {
                work.cancel()
                handle.complete(success: false)
            }
        }
        if isRegistered { registered.insert(identifier) }
    }

    /// The next refresh, no earlier than one hour from now. Returns whether it went to the scheduler: an identifier this
    /// process did not register is skipped.
    @MainActor @discardableResult static func schedule(identifier: String) -> Bool {
        guard registered.contains(identifier) else { return false }
        let request = BGAppRefreshTaskRequest(identifier: identifier)
        request.earliestBeginDate = Date(timeIntervalSinceNow: earliestBegin)
        BGTaskScheduler.shared.submitTaskRequest(request) { error in
            guard let error else { return }
            #if canImport(os)
                Logger(subsystem: "Yak", category: "BackgroundRefresh")
                    .error("not scheduled: \(String(describing: type(of: error)), privacy: .public)")
            #endif
        }
        return true
    }

    /// Hands the non-Sendable task to the work that completes it, and completes it only once: the expiration
    /// handler and the finished work can both ask.
    private final class TaskHandle: @unchecked Sendable {
        private let task: BGAppRefreshTask
        private let isCompleted = OSAllocatedUnfairLock(initialState: false)
        init(_ task: BGAppRefreshTask) { self.task = task }
        func complete(success: Bool) {
            let isFirst = isCompleted.withLock { isCompleted -> Bool in
                if isCompleted { return false }
                isCompleted = true
                return true
            }
            if isFirst { task.setTaskCompleted(success: success) }
        }
    }
}
