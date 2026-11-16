import ActivityKit
import Foundation
import YakKit

/// The part of ActivityKit the controller uses, so its rules are tested without the system.
protocol ActivityHost: Sendable {
    func running() async -> [RunningActivity]
    /// Starts an activity that updates through `channel`; returns its id.
    func start(_ values: ThreadActivityStartValues, channel: String) async throws -> String
    func end(_ activityID: String) async
    /// Yields when an activity started (also by a push), ended or was dismissed.
    func changes() -> AsyncStream<Void>
    func pushToStartTokens() -> AsyncStream<Data>
    func areActivitiesEnabled() -> Bool
}

/// `Activity<ThreadActivityAttributes>`.
final class SystemActivityHost: ActivityHost, @unchecked Sendable {
    private let lock = NSLock()
    private var firstSeen: [String: Date] = [:]

    func running() async -> [RunningActivity] {
        Activity<ThreadActivityAttributes>.activities.compactMap { activity in
            guard activity.activityState == .active || activity.activityState == .stale,
                let instance = URL(string: activity.attributes.instance)
            else { return nil }
            return RunningActivity(
                activityID: activity.id, instance: instance, threadID: activity.attributes.threadId,
                startedAt: startedAt(of: activity))
        }
    }

    func start(_ values: ThreadActivityStartValues, channel: String) async throws -> String {
        let attributes = ThreadActivityAttributes(
            instance: values.instance, threadId: values.threadID, title: values.title)
        let activity = try Activity.request(
            attributes: attributes, content: ActivityContent(state: values.content, staleDate: values.staleDate),
            pushType: .channel(channel))
        return activity.id
    }

    func end(_ activityID: String) async {
        guard let activity = Activity<ThreadActivityAttributes>.activities.first(where: { $0.id == activityID })
        else { return }
        await activity.end(activity.content, dismissalPolicy: .immediate)
    }

    func changes() -> AsyncStream<Void> {
        AsyncStream { continuation in
            let watcher = Watcher(continuation)
            for activity in Activity<ThreadActivityAttributes>.activities { watcher.watch(activity) }
            let updates = Task {
                for await activity in Activity<ThreadActivityAttributes>.activityUpdates {
                    watcher.watch(activity)
                    continuation.yield()
                }
            }
            continuation.onTermination = { _ in updates.cancel() }
        }
    }

    func pushToStartTokens() -> AsyncStream<Data> {
        AsyncStream { continuation in
            let updates = Task {
                for await token in Activity<ThreadActivityAttributes>.pushToStartTokenUpdates {
                    continuation.yield(token)
                }
            }
            continuation.onTermination = { _ in updates.cancel() }
        }
    }

    func areActivitiesEnabled() -> Bool { ActivityAuthorizationInfo().areActivitiesEnabled }

    /// The turn's start from the content, or the time this host first saw the activity.
    private func startedAt(of activity: Activity<ThreadActivityAttributes>) -> Date {
        let text = activity.content.state.turnStartedAt
        for options: ISO8601DateFormatter.Options in [
            [.withInternetDateTime, .withFractionalSeconds], [.withInternetDateTime],
        ] {
            let formatter = ISO8601DateFormatter()
            formatter.formatOptions = options
            if let date = formatter.date(from: text) { return date }
        }
        return lock.withLock { firstSeen[activity.id, default: Date()] }
    }

    /// Reports each activity's state changes once, however many times it is seen.
    private final class Watcher: @unchecked Sendable {
        private let continuation: AsyncStream<Void>.Continuation
        private let lock = NSLock()
        private var ids: Set<String> = []

        init(_ continuation: AsyncStream<Void>.Continuation) { self.continuation = continuation }

        func watch(_ activity: Activity<ThreadActivityAttributes>) {
            guard lock.withLock({ ids.insert(activity.id).inserted }) else { return }
            nonisolated(unsafe) let watched = activity
            Task { [continuation] in
                for await _ in watched.activityStateUpdates { continuation.yield() }
                continuation.yield()
            }
        }
    }
}
