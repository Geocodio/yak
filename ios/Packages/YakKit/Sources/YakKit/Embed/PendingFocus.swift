import Foundation

/// A message or card of a thread's page that a push or a link named, waiting for a page that shows that thread.
public struct PendingFocus: Equatable, Sendable {
    public let threadID: String
    /// The element id the page scrolls to, as `EmbedAppMessage.focus` sends it.
    public let fragment: String

    /// Nil when the thread id or the fragment would not pass the page's checks.
    public init?(threadID: String, fragment: String?) {
        guard let fragment, ThreadIdentifier.isValid(threadID), EmbedAppMessage.isValidFragment(fragment) else {
            return nil
        }
        self.threadID = threadID
        self.fragment = fragment
    }
}

/// The one pending target of a window: set by a push or a link, handed out once, replaced by a later one, and dropped
/// when the person leaves its thread. Pure state; the web conversation decides when to ask.
public struct PendingFocusSlot: Equatable, Sendable {
    public private(set) var target: PendingFocus?

    public init() {}

    /// A newer push or link replaces the target; one without a target clears it, so an older target never fires for
    /// a thread the person opened plainly afterwards.
    public mutating func set(_ target: PendingFocus?) { self.target = target }

    /// The target for `threadID`, which is cleared by being handed out. A target for another thread stays.
    public mutating func take(forThread threadID: String) -> PendingFocus? {
        guard let target, target.threadID == threadID else { return nil }
        self.target = nil
        return target
    }

    /// The person moved to `threadID` (or to none): a target for any other thread is dropped.
    public mutating func drop(unlessForThread threadID: String?) {
        guard let target, target.threadID != threadID else { return }
        self.target = nil
    }

    public mutating func clear() { target = nil }
}
