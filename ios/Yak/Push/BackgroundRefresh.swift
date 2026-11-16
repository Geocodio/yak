import BackgroundTasks
import Foundation

#if canImport(os)
    import os
#endif

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

    /// Called once from `YakApp.init`, before the app finishes launching.
    @MainActor static func register(identifier: String, steps: @escaping @MainActor () -> [BackgroundRefreshStep]) {
        BGTaskScheduler.shared.register(forTaskWithIdentifier: identifier, using: nil) { task in
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
            }
        }
    }

    /// The next refresh, no earlier than one hour from now.
    static func schedule(identifier: String) {
        let request = BGAppRefreshTaskRequest(identifier: identifier)
        request.earliestBeginDate = Date(timeIntervalSinceNow: earliestBegin)
        BGTaskScheduler.shared.submitTaskRequest(request) { error in
            guard let error else { return }
            #if canImport(os)
                Logger(subsystem: "Yak", category: "BackgroundRefresh")
                    .error("not scheduled: \(String(describing: type(of: error)), privacy: .public)")
            #endif
        }
    }

    /// Hands the non-Sendable task to the work that completes it.
    private final class TaskHandle: @unchecked Sendable {
        private let task: BGAppRefreshTask
        init(_ task: BGAppRefreshTask) { self.task = task }
        func complete(success: Bool) { task.setTaskCompleted(success: success) }
    }
}
