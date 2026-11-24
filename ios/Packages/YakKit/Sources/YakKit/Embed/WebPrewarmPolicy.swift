import Foundation

/// When the app prepares the web conversation of the thread that is likely to open next: the session is minted and the
/// web view created before the person taps a row. One page at most is prepared, for one thread.
public struct WebPrewarmPolicy: Equatable, Sendable {
    /// The app is in front and not covered.
    public var isActive: Bool
    /// The app lock covers the content, or an input-blocking cover is up.
    public var isLocked: Bool
    /// The instance is paired and its session runs.
    public var isSessionActive: Bool
    public var isOnline: Bool
    /// The network is under Low Data Mode, which the person chose to save data with.
    public var isLowDataMode: Bool
    /// The server offers the conversation as a page.
    public var serverOffersPage: Bool
    /// A page already exists or a thread is already shown, so nothing more is prepared.
    public var hasPreparedPage: Bool
    /// The thread that is likely to open: the one the window shows or will show, else the last opened.
    public var candidateThreadID: String?

    public init(
        isActive: Bool, isLocked: Bool, isSessionActive: Bool, isOnline: Bool, isLowDataMode: Bool,
        serverOffersPage: Bool, hasPreparedPage: Bool, candidateThreadID: String?
    ) {
        self.isActive = isActive
        self.isLocked = isLocked
        self.isSessionActive = isSessionActive
        self.isOnline = isOnline
        self.isLowDataMode = isLowDataMode
        self.serverOffersPage = serverOffersPage
        self.hasPreparedPage = hasPreparedPage
        self.candidateThreadID = candidateThreadID
    }

    /// The thread to prepare, or nil when nothing should be prepared now.
    public var threadToPrepare: String? {
        guard isActive, !isLocked, isSessionActive, isOnline, !isLowDataMode, serverOffersPage, !hasPreparedPage,
            let id = candidateThreadID, !id.isEmpty
        else { return nil }
        return id
    }
}
