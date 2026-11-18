import Foundation

/// Which panes a thread shows and where, kept in the scene so a layout change can keep or restore them.
///
/// The state is one record: the last two-slot pair (the focused pane, its partner and the side the partner sits on),
/// and the pane that the Overview panel covers. Nothing else is remembered, so any size shows the pair the same way
/// and a fold, an unfold or a rotation changes nothing but how the pair is drawn.
///
/// One bit of history is kept on purpose: `secondLeads` while the panel is open. Opening the panel from
/// [chat | preview] or from [preview | chat] draws the same plan, yet a later change to a stacked size orders the
/// pair differently. Dropping the bit would leave a closing panel no side to put the covered pane back on.
public struct PaneState: Codable, Equatable, Sendable {
    public var focused: PaneID
    /// The pane beside `focused`, set only when the person asks for it. Nil is one pane at every size.
    public var second: PaneID?
    /// The second pane sits in the first position (leading or lower).
    public var secondLeads: Bool
    /// While the Overview is in the pair: the pane that sat in the Overview's place before the panel opened, and
    /// comes back when it closes.
    public var behind: PaneID?
    /// The upper slot's share of the height, clamped on use.
    public var stackedFraction: Double

    /// The Overview is one of the pair: a pane where it is a pane, the side panel where it is a panel.
    public var isOverviewInPair: Bool { focused == .overview || second == .overview }

    public init(
        focused: PaneID = .chat, second: PaneID? = nil, secondLeads: Bool = false, behind: PaneID? = nil,
        stackedFraction: Double = 0.5
    ) {
        self.focused = focused
        self.second = second
        self.secondLeads = secondLeads
        self.behind = behind
        self.stackedFraction = stackedFraction
    }

    private enum CodingKeys: String, CodingKey {
        case focused, second, secondLeads, behind, stackedFraction
    }

    /// Restored state is untrusted: a missing key takes its default and a pane name this build does not know is dropped.
    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        func pane(_ key: CodingKeys) -> PaneID? {
            (try? container.decodeIfPresent(String.self, forKey: key)).flatMap { $0 }.flatMap(PaneID.init(rawValue:))
        }
        self.init(
            focused: pane(.focused) ?? .chat,
            second: pane(.second),
            secondLeads: (try? container.decodeIfPresent(Bool.self, forKey: .secondLeads)).flatMap { $0 } ?? false,
            behind: pane(.behind),
            stackedFraction: (try? container.decodeIfPresent(Double.self, forKey: .stackedFraction)).flatMap { $0 }
                ?? 0.5)
    }
}

public enum SlotPosition: String, Codable, Equatable, Hashable, Sendable {
    case single, leading, trailing, upper, lower
}

public enum SlotContent: Equatable, Hashable, Sendable {
    case pane(PaneID)
    case overviewPanel
}

public struct PaneSlot: Equatable, Sendable {
    public let position: SlotPosition
    public let content: SlotContent

    public init(position: SlotPosition, content: SlotContent) {
        self.position = position
        self.content = content
    }
}

/// What the pane menu's "Second pane" section shows.
public struct SecondPaneChoices: Equatable, Sendable {
    /// False where the size has one slot: the section is left out.
    public let isOffered: Bool
    /// The panes that can be the second pane, in display order (the shown focused pane is not one).
    public let panes: [PaneID]
    /// The pane that is the second pane now; nil is "None".
    public let current: PaneID?
}

public enum PaneMark: Equatable, Sendable { case focused, second, none }

public struct SlotPlan: Equatable, Sendable {
    /// What is shown: `.single` when the second slot would be empty.
    public let arrangement: PaneArrangement
    /// Reading order: leading then trailing, upper then lower.
    public let slots: [PaneSlot]
    public let focusedPosition: SlotPosition
    /// The slots with a bar, and the pane control in each (nil: none).
    public let bars: [SlotPosition: PaneControlPlacement?]
    /// Stacked only, clamped.
    public let upperFraction: Double?
    /// The distinct panes the plan was made from; `PaneLayoutRules.select` refuses any other.
    public let available: [PaneID]
    /// The size lets the Overview be a side panel (whether or not it is open).
    public let overviewIsPanel: Bool
    /// What the size allows, whatever is shown: `.single` is the only arrangement with one slot.
    public let sizeArrangement: PaneArrangement

    public var shownPanes: [PaneID] {
        slots.compactMap { slot in
            if case .pane(let pane) = slot.content { return pane }
            return nil
        }
    }
}

public enum PaneLayoutRules {
    public static let stackedFractionRange: ClosedRange<Double> = 0.3...0.7

    /// The version a window's stored pane state is written with. Version 1 is the first in which the second pane
    /// is always the person's choice; a state stored before has no version and its second pane was the app's.
    public static let storedFormatVersion = 1

    /// Whether a stored state's second pane (and the pane it covers) is kept: only when it was stored by a build that
    /// leaves the choice to the person.
    public static func keepsStoredSecondPane(formatVersion: Int?) -> Bool {
        (formatVersion ?? 0) >= storedFormatVersion
    }

    /// The Overview is a side panel exactly when the width is not compact, the height is not compact, and the
    /// size's arrangement is `.sideBySide` or `.book`. Everywhere else it is a pane.
    public static func overviewIsPanel(arrangement: PaneArrangement, inputs: LayoutInputs) -> Bool {
        !inputs.isCompactWidth && !inputs.isCompactHeight && (arrangement == .sideBySide || arrangement == .book)
    }

    /// Panes that may sit in a pane slot: `available` without `.overview` where `overviewIsPanel`.
    public static func slotPanes(available: [PaneID], arrangement: PaneArrangement, inputs: LayoutInputs) -> [PaneID] {
        let panel = overviewIsPanel(arrangement: arrangement, inputs: inputs)
        return PaneID.allCases.filter { available.contains($0) && !(panel && $0 == .overview) }
    }

    /// `available` always contains `.chat` (the thread's pane list guarantees it); an empty set falls back to Chat.
    public static func plan(_ state: PaneState, available: [PaneID], inputs: LayoutInputs) -> SlotPlan {
        let size = ThreadLayout.arrangement(inputs, slotPaneCount: Set(available).count)
        let resolved = resolve(state, available: available, arrangement: size, inputs: inputs)
        let shownFocus = besideOfPanel(resolved, size: size, inputs: inputs) ?? resolved.focused
        let focused = shownFocus
        var arrangement = size
        var slots: [PaneSlot]
        if size.slotCount == 1 {
            slots = []
        } else if besideOfPanel(resolved, size: size, inputs: inputs) != nil {
            slots = [
                PaneSlot(position: .leading, content: .pane(focused)),
                PaneSlot(position: .trailing, content: .overviewPanel),
            ]
        } else if let second = resolved.second {
            var secondLeads = resolved.secondLeads
            if size == .laptop {
                // The laptop pose draws Chat in the lower slot; the pair itself is not changed.
                if focused == .chat {
                    secondLeads = false
                } else if second == .chat {
                    secondLeads = true
                }
            }
            let pair = secondLeads ? [second, focused] : [focused, second]
            if size == .stacked || size == .laptop {
                slots = [
                    PaneSlot(position: .upper, content: .pane(pair[1])),
                    PaneSlot(position: .lower, content: .pane(pair[0])),
                ]
            } else {
                slots = [
                    PaneSlot(position: .leading, content: .pane(pair[0])),
                    PaneSlot(position: .trailing, content: .pane(pair[1])),
                ]
            }
        } else {
            slots = []
        }
        if slots.isEmpty {
            arrangement = .single
            slots = [PaneSlot(position: .single, content: .pane(focused))]
        }
        let focusedPosition = slots.first { $0.content == .pane(focused) }?.position ?? .single

        let bars: [SlotPosition: PaneControlPlacement?]
        switch arrangement {
        case .single:
            bars = [
                .single: PaneControlPlacement.choose(
                    isCompactWidth: inputs.isCompactWidth, isCompactHeight: inputs.isCompactHeight,
                    hasVerticalToolbarEdge: inputs.hasVerticalToolbarEdge)
            ]
        case .sideBySide, .book:
            let control: PaneControlPlacement? = inputs.hasVerticalToolbarEdge ? nil : .menuButton
            bars = Dictionary(
                uniqueKeysWithValues: slots.filter { $0.content != .overviewPanel }.map { ($0.position, control) })
        case .stacked, .laptop:
            bars = [.lower: inputs.hasVerticalToolbarEdge ? nil : .barRow]
        }
        return SlotPlan(
            arrangement: arrangement, slots: slots, focusedPosition: focusedPosition, bars: bars,
            upperFraction: arrangement.hasDragHandle ? clampedFraction(state.stackedFraction) : nil,
            available: PaneID.allCases.filter(available.contains),
            overviewIsPanel: overviewIsPanel(arrangement: size, inputs: inputs) && available.contains(.overview),
            sizeArrangement: size)
    }

    /// A pane picked in the rail, the row, a pane menu or Command 1 to 5. `slot` is the bar whose menu was used, nil otherwise.
    public static func select(_ pane: PaneID, from slot: SlotPosition?, state: PaneState, plan: SlotPlan) -> PaneState {
        guard plan.available.contains(pane) else { return state }
        var next = stateShown(by: plan, base: state)
        let isPanelOpen = plan.slots.contains { $0.content == .overviewPanel }
        if pane == .overview && plan.overviewIsPanel {
            if isPanelOpen {
                // The pane beside the panel keeps the focus.
                let beside = plan.slots.first { $0.position == .leading }?.content.paneID ?? next.focused
                let isOverviewFirst = next.focused == .overview ? !next.secondLeads : next.secondLeads
                next.focused = beside
                let covered = next.behind
                // A covered pane returns on the side the Overview had; with none, one pane remains.
                next.second = covered
                next.secondLeads = covered != nil && isOverviewFirst
            } else {
                next.behind = next.second
                next.second = .overview
                if next.behind == nil { next.secondLeads = false }
            }
            return tidy(next, plan: plan)
        }
        if let shown = plan.slots.first(where: { $0.content == .pane(pane) }) {
            return focus(shown.position, state: state, plan: plan)
        }
        let target = plan.slots.first { $0.position == slot && $0.content != .overviewPanel }?.position
        if plan.slots.count == 1 {
            // A picked partner trades places with the focused pane; any other pane takes the focus.
            if next.second == pane { next.second = next.focused }
            next.focused = pane
        } else if isPanelOpen {
            // The shown pane beside the panel is replaced; the record keeps which of the pair had the focus.
            if next.focused == .overview { next.second = pane } else { next.focused = pane }
        } else if target == nil || target == plan.focusedPosition {
            // The focused slot takes the pane; the other slot keeps its pane and its side.
            next.focused = pane
        } else {
            next.second = next.focused
            next.secondLeads = isFirstOfPair(plan.focusedPosition)
            next.focused = pane
        }
        return tidy(next, plan: plan)
    }

    /// A tap or a click in a slot.
    public static func focus(_ position: SlotPosition, state: PaneState, plan: SlotPlan) -> PaneState {
        guard plan.slots.count == 2, position != plan.focusedPosition,
            let other = plan.slots.first(where: { $0.position == position }), case .pane(let pane) = other.content
        else {
            return state
        }
        var next = stateShown(by: plan, base: state)
        next.second = next.focused
        next.secondLeads = isFirstOfPair(plan.focusedPosition)
        next.focused = pane
        return tidy(next, plan: plan)
    }

    /// Called whenever the layout inputs change (rotation, Split View, a window resize, fold, unfold, a pose), even
    /// when the arrangement value stays the same: a side-by-side size can lose the panel to a short height. The pair is
    /// kept as it is, with one exception. An open Overview panel that stops
    /// being a panel (the width drops) becomes the focused Overview pane, taking the pane that sat beside it as the
    /// second pane on the same side, so nothing moves. `oldInputs` are the inputs `old` was made from: the old size had
    /// the panel only when they allow it.
    public static func arrangementChanged(
        from old: PaneArrangement, to new: PaneArrangement, state: PaneState, available: [PaneID], inputs: LayoutInputs,
        oldInputs: LayoutInputs
    ) -> PaneState {
        var next = state
        let wasPanel = overviewIsPanel(arrangement: old, inputs: oldInputs)
        if wasPanel, !overviewIsPanel(arrangement: new, inputs: inputs), available.contains(.overview),
            next.second == .overview, next.focused != .overview
        {
            next.second = next.focused
            next.focused = .overview
            next.secondLeads.toggle()
        }
        return resolve(next, available: available, arrangement: new, inputs: inputs)
    }

    /// Called when the thread's panes change (a pane hidden by the server, a pane plan merged).
    public static func availableChanged(state: PaneState, available: [PaneID], inputs: LayoutInputs) -> PaneState {
        let size = ThreadLayout.arrangement(inputs, slotPaneCount: Set(available).count)
        return resolve(state, available: available, arrangement: size, inputs: inputs)
    }

    /// The panes the "Second pane" menu offers and the one that is the second pane now.
    public static func secondChoices(state: PaneState, plan: SlotPlan, inputs: LayoutInputs) -> SecondPaneChoices {
        let size = ThreadLayout.arrangement(inputs, slotPaneCount: plan.available.count)
        guard size.slotCount == 2 else { return SecondPaneChoices(isOffered: false, panes: [], current: nil) }
        let shownFocus = plan.slots.first { $0.position == plan.focusedPosition }?.content.paneID
        let other = plan.slots.first { $0.position != plan.focusedPosition }?.content
        let current: PaneID?
        switch other {
        case .pane(let pane)?: current = pane
        case .overviewPanel?: current = .overview
        case nil: current = nil
        }
        return SecondPaneChoices(
            isOffered: true, panes: plan.available.filter { $0 != shownFocus }, current: current)
    }

    /// The person's pick in the "Second pane" menu: nil closes the second pane (and the Overview panel), a pane
    /// opens it in the other slot and keeps the shown focused pane focused. Nothing changes at a one-slot size, for
    /// the focused pane, or for a pane that is not available.
    public static func setSecond(_ pane: PaneID?, state: PaneState, plan: SlotPlan) -> PaneState {
        guard plan.sizeArrangement.slotCount == 2 else { return state }
        let isPanelOpen = plan.slots.contains { $0.content == .overviewPanel }
        let shownFocus = plan.slots.first { $0.position == plan.focusedPosition }?.content.paneID
        guard let pane else {
            var next = stateShown(by: plan, base: state)
            if next.focused == .overview, let beside = next.second { next.focused = beside }
            next.second = nil
            next.behind = nil
            next.secondLeads = false
            return tidy(next, plan: plan)
        }
        guard plan.available.contains(pane), pane != shownFocus else { return state }
        if pane == .overview && plan.overviewIsPanel {
            return isPanelOpen ? state : select(.overview, from: nil, state: state, plan: plan)
        }
        if plan.slots.contains(where: { $0.position != plan.focusedPosition && $0.content == .pane(pane) }) {
            return state
        }
        var next = stateShown(by: plan, base: state)
        if isPanelOpen, next.focused == .overview, let beside = next.second { next.focused = beside }
        if next.second == nil { next.secondLeads = false }
        next.second = pane
        next.behind = nil
        return tidy(next, plan: plan)
    }

    public static func defaultSecond(focused: PaneID, lastSecond: PaneID?, candidates: [PaneID]) -> PaneID? {
        if let lastSecond, lastSecond != focused, candidates.contains(lastSecond) { return lastSecond }
        if focused != .chat, candidates.contains(.chat) { return .chat }
        return PaneID.allCases.first { $0 != focused && candidates.contains($0) }
    }

    public static func marks(state: PaneState, plan: SlotPlan) -> [PaneID: PaneMark] {
        var marks = Dictionary(uniqueKeysWithValues: PaneID.allCases.map { ($0, PaneMark.none) })
        guard case .pane(let focused)? = plan.slots.first(where: { $0.position == plan.focusedPosition })?.content
        else {
            return marks
        }
        marks[focused] = .focused
        for slot in plan.slots where slot.position != plan.focusedPosition {
            switch slot.content {
            case .pane(let pane): marks[pane] = .second
            case .overviewPanel: marks[.overview] = .second
            }
        }
        return marks
    }

    /// Higher stays longer. Chat highest; then the spec's overflow order reversed; focused and second lifted above the rest.
    public static func stripPriorities(available: [PaneID], state: PaneState, plan: SlotPlan) -> [PaneID: Int] {
        var priorities: [PaneID: Int] = [:]
        for pane in available {
            if pane == .chat {
                priorities[pane] = 100
            } else if plan.shownPanes.contains(pane)
                || (pane == .overview && plan.slots.contains { $0.content == .overviewPanel })
            {
                priorities[pane] = 90
            } else {
                priorities[pane] = (Panes.overflowOrder.firstIndex(of: pane).map { ($0 + 1) * 10 }) ?? 0
            }
        }
        return priorities
    }

    public static func clampedFraction(_ value: Double) -> Double {
        guard value.isFinite else { return 0.5 }
        return min(max(value, stackedFractionRange.lowerBound), stackedFractionRange.upperBound)
    }

    /// The fraction kept inside the range and also far enough from both ends that neither slot is shorter than
    /// `minimumSlotHeight` of the `availableHeight`. Where the height cannot hold two such slots the split is even.
    public static func clampedFraction(_ value: Double, availableHeight: Double, minimumSlotHeight: Double) -> Double {
        let base = clampedFraction(value)
        guard availableHeight.isFinite, availableHeight > 0, minimumSlotHeight.isFinite, minimumSlotHeight > 0 else {
            return base
        }
        let share = minimumSlotHeight / availableHeight
        guard share < 0.5 else { return 0.5 }
        return min(max(base, share), 1 - share)
    }

    // MARK: Internals

    private static func isFirstOfPair(_ position: SlotPosition) -> Bool { position == .leading || position == .lower }

    /// The pane beside the Overview panel, when `state` shows the panel at this size.
    private static func besideOfPanel(_ state: PaneState, size: PaneArrangement, inputs: LayoutInputs) -> PaneID? {
        guard size.slotCount == 2, state.isOverviewInPair, overviewIsPanel(arrangement: size, inputs: inputs) else {
            return nil
        }
        return state.focused == .overview ? state.second : state.focused
    }

    /// The state with every self-contradiction repaired for `arrangement`: gone panes replaced and a second pane
    /// that is gone dropped. No second pane is ever invented. The Overview stays in the pair wherever it is; the size decides whether
    /// it is drawn as a pane or as the panel.
    private static func resolve(
        _ state: PaneState, available: [PaneID], arrangement: PaneArrangement, inputs: LayoutInputs
    ) -> PaneState {
        let present = PaneID.allCases.filter(available.contains)
        var next = state
        next.stackedFraction = clampedFraction(next.stackedFraction)
        if !present.contains(next.focused) { next.focused = present.contains(.chat) ? .chat : (present.first ?? .chat) }
        if let second = next.second, second == next.focused || !present.contains(second) { next.second = nil }
        next = cleaned(next, available: present)
        guard arrangement.slotCount == 2 else { return next }
        let candidates = slotPanes(available: present, arrangement: arrangement, inputs: inputs)
        let isPanelShown =
            present.contains(.overview) && overviewIsPanel(arrangement: arrangement, inputs: inputs)
            && next.isOverviewInPair
        if isPanelShown {
            // The pane beside the panel is a pane slot's pane. A focused Overview with none cannot fill the width as a
            // pane here, so Chat (or the first pane) takes the focus and the panel stays closed.
            if next.focused == .overview, next.second.map({ !candidates.contains($0) }) ?? true {
                next.second = nil
                next.focused = candidates.contains(.chat) ? .chat : (candidates.first ?? .chat)
                next.secondLeads = false
            }
        } else if next.second.map({ !candidates.contains($0) }) ?? true {
            next.second = nil
        }
        return cleaned(next, available: present)
    }

    /// The state that describes what `plan` shows, keeping what the plan cannot show: which of the pair holds the
    /// focus while the Overview is the panel, and the pane the panel covers.
    private static func stateShown(by plan: SlotPlan, base: PaneState) -> PaneState {
        var next = base
        if plan.slots.contains(where: { $0.content == .overviewPanel }) {
            let beside = plan.slots.first { $0.position == .leading }?.content.paneID ?? next.focused
            if next.focused == .overview {
                next.second = beside
            } else {
                next.focused = beside
                next.second = .overview
            }
            return tidy(next, plan: plan)
        }
        guard let focusedSlot = plan.slots.first(where: { $0.position == plan.focusedPosition }),
            case .pane(let focused) = focusedSlot.content
        else {
            return next
        }
        next.focused = focused
        if let other = plan.slots.first(where: { $0.position != plan.focusedPosition }),
            case .pane(let second) = other.content
        {
            next.second = second
            next.secondLeads = isFirstOfPair(other.position)
        }
        return tidy(next, plan: plan)
    }

    /// A second pane that duplicates the focused one or is gone is dropped.
    private static func tidy(_ state: PaneState, plan: SlotPlan) -> PaneState {
        var next = state
        if let second = next.second, second == next.focused || !plan.available.contains(second) { next.second = nil }
        return cleaned(next, available: plan.available)
    }

    /// `behind` is only kept while the Overview is in the pair and the pane is still there and not shown.
    private static func cleaned(_ state: PaneState, available: [PaneID]) -> PaneState {
        var next = state
        if let behind = next.behind,
            !next.isOverviewInPair || !available.contains(behind) || behind == .overview || behind == next.focused
                || behind == next.second
        {
            next.behind = nil
        }
        return next
    }
}

extension SlotContent {
    fileprivate var paneID: PaneID? {
        if case .pane(let pane) = self { return pane }
        return nil
    }
}
