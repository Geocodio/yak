/// What the menu of a message that is waiting for its turn offers.
public enum QueuedMessageMenuItem: Equatable, Sendable {
    case edit, sendNow, sendAfterTurn, remove
}

public enum QueuedMessageMenu {
    /// Edit only while the server allows the text to change; sending now or after the turn depends on the mode.
    public static func items(canEditText: Bool, isQueued: Bool) -> [QueuedMessageMenuItem] {
        (canEditText ? [.edit] : []) + [isQueued ? .sendNow : .sendAfterTurn, .remove]
    }
}
