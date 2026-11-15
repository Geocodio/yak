public enum ConnectionLine: Equatable, Sendable {
    case slow, offlineSavedCopy, offline, reconnecting

    /// A socket that is `.offline` while requests succeed (no realtime configured, the engine polls) shows nothing.
    public static func make(_ snapshot: ConversationSnapshot) -> ConnectionLine? {
        if snapshot.load == .slow { return .slow }
        if snapshot.isOffline { return snapshot.show != nil ? .offlineSavedCopy : .offline }
        return snapshot.connection == .reconnecting ? .reconnecting : nil
    }
}
