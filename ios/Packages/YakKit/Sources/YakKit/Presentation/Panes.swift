public enum PaneID: String, CaseIterable, Codable, Sendable { case chat, preview, files, terminal, overview }

public enum Panes {
    /// Panes this build has, one line per pane. Files exists only while a Files mode is built.
    public static let built: [PaneID] =
        [
            .chat,
            .terminal,
        ] + (FilesMode.built.isEmpty ? [] : [.files])

    /// In `PaneID.allCases` order: Chat always; Preview when `server.preview`; Files when `server.files || server.changes`;
    /// Terminal when `server.terminal`; Overview always. Then filtered by `built`. A nil `server` gives Chat.
    public static func available(server: ThreadPanes?, built: [PaneID] = Panes.built) -> [PaneID] {
        guard let server else { return [.chat].filter(built.contains) }
        return PaneID.allCases.filter { pane in
            guard built.contains(pane) else { return false }
            switch pane {
            case .chat, .overview: return true
            case .preview: return server.preview
            case .files: return server.files || server.changes
            case .terminal: return server.terminal
            }
        }
    }

    /// The pane `step` places away (-1 or +1) in `panes`; nil at either end or when `pane` is not in `panes`.
    public static func neighbor(of pane: PaneID, in panes: [PaneID], step: Int) -> PaneID? {
        guard let index = panes.firstIndex(of: pane) else { return nil }
        let target = index + step
        return panes.indices.contains(target) ? panes[target] : nil
    }

    /// First to move into the strip's overflow when it runs short. Chat never does.
    public static let overflowOrder: [PaneID] = [.terminal, .overview, .preview, .files]
}

public enum PaneControlPlacement: Equatable, Sendable {
    /// Compact width: the pane button is the first item of the bar.
    case menuButton
    /// Regular width without a system strip: panes as a row at the start of the bar.
    case barRow
    /// `toolbarVerticalEdge` is set: panes are bottom-bar toolbar items that the system puts in its strip.
    case systemStrip
    /// Compact height without a system strip: the app's own strip on the trailing edge, title hidden.
    case trailingStrip

    public static func choose(isCompactWidth: Bool, isCompactHeight: Bool, hasVerticalToolbarEdge: Bool)
        -> PaneControlPlacement
    {
        if hasVerticalToolbarEdge { return .systemStrip }
        if isCompactHeight { return .trailingStrip }
        return isCompactWidth ? .menuButton : .barRow
    }

    public var declaresBottomToolbarItems: Bool { self == .systemStrip }
    public var showsPaneButtonInBar: Bool { self == .menuButton }
    public var showsPaneRowInBar: Bool { self == .barRow }
    public var hidesTitle: Bool { self == .trailingStrip }
}
