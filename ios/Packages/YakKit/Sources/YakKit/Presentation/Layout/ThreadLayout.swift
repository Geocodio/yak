import Foundation

/// How far the iPhone Duo's hinge is open.
public enum HingePosture: String, Codable, Equatable, Sendable {
    case flat, partlyOpen, closed

    /// `systemState` is the system's hinge state name ("closed", "partiallyOpen", "fullyOpen"); anything else is `.flat`.
    public init(systemState: String) {
        switch systemState {
        case "closed": self = .closed
        case "partiallyOpen": self = .partlyOpen
        default: self = .flat
        }
    }
}

/// The direction the fold runs in.
public enum FoldAxis: Equatable, Sendable {
    /// The fold runs top to bottom: panes left and right of it (book).
    case vertical
    /// The fold runs side to side: panes above and below it (laptop).
    case horizontal

    /// From the active `.division` region's size. Nil for an empty, square or non-finite size.
    public init?(regionWidth: Double, regionHeight: Double) {
        guard regionWidth.isFinite, regionHeight.isFinite, regionWidth > 0, regionHeight > 0 else {
            return nil
        }
        if regionWidth < regionHeight {
            self = .vertical
        } else if regionWidth > regionHeight {
            self = .horizontal
        } else {
            return nil
        }
    }
}

public struct LayoutInputs: Equatable, Sendable {
    public var isCompactWidth: Bool
    public var isCompactHeight: Bool
    /// The detail column after the sidebar and the system strip.
    public var detailWidth: Double
    public var hasVerticalToolbarEdge: Bool
    public var posture: HingePosture
    /// Nil while the division region is inactive.
    public var fold: FoldAxis?
    /// The keyboard's frame covers the last known division (typing in the laptop pose).
    public var isKeyboardOnLowerHalf: Bool

    public init(
        isCompactWidth: Bool,
        isCompactHeight: Bool,
        detailWidth: Double,
        hasVerticalToolbarEdge: Bool,
        posture: HingePosture = .flat,
        fold: FoldAxis? = nil,
        isKeyboardOnLowerHalf: Bool = false
    ) {
        self.isCompactWidth = isCompactWidth
        self.isCompactHeight = isCompactHeight
        self.detailWidth = detailWidth
        self.hasVerticalToolbarEdge = hasVerticalToolbarEdge
        self.posture = posture
        self.fold = fold
        self.isKeyboardOnLowerHalf = isKeyboardOnLowerHalf
    }
}

public enum PaneArrangement: String, Codable, Equatable, Sendable {
    case single, sideBySide, stacked, book, laptop

    public var slotCount: Int { self == .single ? 1 : 2 }
    public var hasDragHandle: Bool { self == .stacked }
    public var barsPerSlot: Bool { self == .sideBySide || self == .book }
}

public enum ThreadLayout {
    public static let twoPaneMinimumWidth: Double = 760

    /// The arrangement the size allows. `slotPaneCount` is the number of distinct available panes, the Overview
    /// included: it fills a slot either as a pane or as the side panel.
    public static func arrangement(_ inputs: LayoutInputs, slotPaneCount: Int) -> PaneArrangement {
        if slotPaneCount < 2 || inputs.isCompactWidth {
            return .single
        }
        if inputs.posture == .partlyOpen {
            // The keyboard on the lower half may deactivate the region or change the height class; the laptop stays.
            if inputs.isKeyboardOnLowerHalf {
                return .laptop
            }
            switch inputs.fold {
            case .vertical: return .book
            case .horizontal: return .laptop
            case nil: break
            }
        }
        if inputs.isCompactHeight && !inputs.hasVerticalToolbarEdge {
            return .single
        }
        guard inputs.detailWidth.isFinite, inputs.detailWidth >= 0 else {
            return .single
        }
        return inputs.detailWidth >= twoPaneMinimumWidth ? .sideBySide : .stacked
    }
}
