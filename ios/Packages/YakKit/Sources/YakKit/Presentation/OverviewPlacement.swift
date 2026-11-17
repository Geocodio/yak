import Foundation

/// Where the Overview shows: as a pane of its own, or as a panel beside the conversation.
public enum OverviewPlacement: Equatable, Sendable {
    /// Compact width, or too narrow for a panel.
    case pane
    case sidePanel(width: Double)

    /// The content width below which the Overview stays a pane.
    public static let minimumContentWidth: Double = 760

    public static func choose(isCompactWidth: Bool, availableWidth: Double) -> OverviewPlacement {
        guard !isCompactWidth, availableWidth >= minimumContentWidth else { return .pane }
        return .sidePanel(width: min(400, max(320, availableWidth * 0.38)))
    }
}
