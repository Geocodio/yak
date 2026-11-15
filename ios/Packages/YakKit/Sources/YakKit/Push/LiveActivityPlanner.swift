import Foundation

/// A Live Activity that is running on this phone.
public struct RunningActivity: Equatable, Sendable {
    public let activityID: String
    public let instance: URL
    public let threadID: String
    public let startedAt: Date

    public init(activityID: String, instance: URL, threadID: String, startedAt: Date) {
        self.activityID = activityID
        self.instance = instance
        self.threadID = threadID
        self.startedAt = startedAt
    }
}

/// What to do about the running set: whether to start, which activities to end, and the set to report per instance.
public struct LiveActivityPlan: Equatable, Sendable {
    public let starts: Bool
    /// Oldest first.
    public let endActivityIDs: [String]
    /// Per instance, the thread ids after the plan, sorted.
    public let reports: [URL: [String]]

    public init(starts: Bool, endActivityIDs: [String], reports: [URL: [String]]) {
        self.starts = starts
        self.endActivityIDs = endActivityIDs
        self.reports = reports
    }
}

/// The attributes and first content state of an activity the app starts itself.
public struct ThreadActivityStartValues: Equatable, Sendable {
    public let instance: String
    public let threadID: String
    public let title: String
    public let content: ThreadActivityContentState
    public let staleDate: Date

    public init(instance: String, threadID: String, title: String, content: ThreadActivityContentState, staleDate: Date)
    {
        self.instance = instance
        self.threadID = threadID
        self.title = title
        self.content = content
        self.staleDate = staleDate
    }
}

/// Pure rules for which Live Activities run: at most three, one per thread and instance, none for unpaired instances.
public enum LiveActivityPlanner {
    public static let maximum = 3
    public static let staleAfter: Duration = .seconds(1800)

    /// The word the server uses for a private thread's title.
    static let privateTitle = "Private thread"

    public static func shouldStart(
        show: ThreadShow?, turnIsActive: Bool, availability: PushAvailability?, activitiesEnabled: Bool
    ) -> Bool {
        guard let show, turnIsActive, activitiesEnabled, availability == .available else { return false }
        return show.canDrive && show.thread.status == .open
    }

    public static func plan(
        starting threadID: String, on instance: URL, running: [RunningActivity], instances: [URL]
    ) -> LiveActivityPlan {
        if running.contains(where: { $0.instance == instance && $0.threadID == threadID }) {
            return LiveActivityPlan(
                starts: false, endActivityIDs: [], reports: reports(running: running, instances: instances))
        }
        var ended = oldestFirst(running.filter { !instances.contains($0.instance) })
        var kept = running.filter { instances.contains($0.instance) }
        while kept.count + 1 > maximum, let oldest = oldestFirst(kept).first {
            ended.append(oldest)
            kept.removeAll { $0.activityID == oldest.activityID }
        }
        let fresh = RunningActivity(activityID: "", instance: instance, threadID: threadID, startedAt: .distantFuture)
        return LiveActivityPlan(
            starts: true, endActivityIDs: ended.map(\.activityID),
            reports: reports(running: kept + [fresh], instances: instances))
    }

    /// After any change to the running set (a push-started activity included): duplicates and the excess end, the rest is reported.
    public static func reconcile(running: [RunningActivity], instances: [URL]) -> LiveActivityPlan {
        var ended = oldestFirst(running.filter { !instances.contains($0.instance) })
        var kept = running.filter { instances.contains($0.instance) }

        var duplicates: [RunningActivity] = []
        let groups = Dictionary(grouping: kept) { "\($0.instance.absoluteString)\n\($0.threadID)" }
        for group in groups.values { duplicates += oldestFirst(group).dropFirst() }
        ended += oldestFirst(duplicates)
        kept.removeAll { activity in duplicates.contains { $0.activityID == activity.activityID } }

        while kept.count > maximum, let oldest = oldestFirst(kept).first {
            ended.append(oldest)
            kept.removeAll { $0.activityID == oldest.activityID }
        }
        return LiveActivityPlan(
            starts: false, endActivityIDs: ended.map(\.activityID),
            reports: reports(running: kept, instances: instances))
    }

    /// The thread ids running per instance, sorted. Every paired instance is present, an empty one with an empty list.
    public static func reports(running: [RunningActivity], instances: [URL]) -> [URL: [String]] {
        var result: [URL: [String]] = [:]
        for instance in instances { result[instance] = [] }
        for activity in running where result[activity.instance] != nil {
            result[activity.instance, default: []].append(activity.threadID)
        }
        return result.mapValues { Array(Set($0)).sorted() }
    }

    public static func startValues(show: ThreadShow, instance: URL, now: Date) -> ThreadActivityStartValues {
        let isPrivate = show.thread.isPrivate
        let seconds = Double(staleAfter.components.seconds)
        return ThreadActivityStartValues(
            instance: instance.absoluteString,
            threadID: show.thread.id,
            title: isPrivate ? privateTitle : show.thread.title,
            content: ThreadActivityContentState(
                state: .working, step: "", turnStartedAt: timestamp(now), isPrivate: isPrivate),
            staleDate: now.addingTimeInterval(seconds))
    }

    private static func oldestFirst(_ activities: [RunningActivity]) -> [RunningActivity] {
        activities.sorted { ($0.startedAt, $0.activityID) < ($1.startedAt, $1.activityID) }
    }

    /// `2026-11-16T12:00:00+00:00`: UTC with an explicit offset, as the server writes it.
    private static func timestamp(_ date: Date) -> String {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime]
        formatter.timeZone = TimeZone(secondsFromGMT: 0)
        let text = formatter.string(from: date)
        return text.hasSuffix("Z") ? String(text.dropLast()) + "+00:00" : text
    }
}
