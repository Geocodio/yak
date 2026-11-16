import Foundation

/// The numbers behind the spoken "What needs me?" answer: counts and instance names only, never a title.
public struct NeedsYouSummary: Equatable, Sendable {
    public struct Part: Equatable, Sendable {
        public let instanceName: String
        public let count: Int
        public let isFresh: Bool
    }

    public let parts: [Part]
    public let total: Int

    /// Values for the spoken sentence: counts and instance names only, never a title.
    /// An instance without a snapshot counts 0 and is not fresh. Counts are clamped to 9999.
    public static func make(_ refreshes: [(instance: Instance, snapshot: WidgetSnapshot?, isFresh: Bool)])
        -> NeedsYouSummary
    {
        let parts = refreshes.map { refresh in
            Part(
                instanceName: WidgetText.bounded(refresh.instance.name, limit: WidgetText.instanceNameLimit),
                count: refresh.snapshot.map { WidgetText.clamped($0.needsYouCount) } ?? 0,
                isFresh: refresh.snapshot != nil && refresh.isFresh)
        }
        return NeedsYouSummary(parts: parts, total: parts.reduce(0) { $0 + $1.count })
    }
}
