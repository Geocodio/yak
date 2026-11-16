import Foundation

/// Which panes a thread shows and where, kept in the scene so a layout change can keep or restore them.
public struct PaneState: Codable, Equatable, Sendable {
    public var focused: PaneID
    /// Nil until a two-slot layout needs one.
    public var second: PaneID?
    /// The second pane sits in the first position (leading or lower).
    public var secondLeads: Bool
    public var lastSecond: PaneID?
    public var isOverviewPanelOpen: Bool
    /// The upper slot's share of the height, clamped on use.
    public var stackedFraction: Double

    public init(
        focused: PaneID = .chat, second: PaneID? = nil, secondLeads: Bool = false, lastSecond: PaneID? = nil,
        isOverviewPanelOpen: Bool = false, stackedFraction: Double = 0.5
    ) {
        self.focused = focused
        self.second = second
        self.secondLeads = secondLeads
        self.lastSecond = lastSecond
        self.isOverviewPanelOpen = isOverviewPanelOpen
        self.stackedFraction = stackedFraction
    }

    private enum CodingKeys: String, CodingKey {
        case focused, second, secondLeads, lastSecond, isOverviewPanelOpen, stackedFraction
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
            lastSecond: pane(.lastSecond),
            isOverviewPanelOpen: (try? container.decodeIfPresent(Bool.self, forKey: .isOverviewPanelOpen))
                .flatMap { $0 } ?? false,
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

    public var shownPanes: [PaneID] {
        slots.compactMap { slot in
            if case .pane(let pane) = slot.content { return pane }
            return nil
        }
    }
}

public enum PaneLayoutRules {
    public static let stackedFractionRange: ClosedRange<Double> = 0.3...0.7

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
        let focused = resolved.focused
        var arrangement = size
        var slots: [PaneSlot]
        if size.slotCount == 1 {
            slots = []
        } else if resolved.isOverviewPanelOpen {
            slots = [
                PaneSlot(position: .leading, content: .pane(focused)),
                PaneSlot(position: .trailing, content: .overviewPanel),
            ]
        } else if let second = resolved.second {
            let pair = resolved.secondLeads ? [second, focused] : [focused, second]
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
            overviewIsPanel: overviewIsPanel(arrangement: size, inputs: inputs) && available.contains(.overview))
    }

    /// A pane picked in the rail, the row, a pane menu or Command 1 to 5. `slot` is the bar whose menu was used, nil otherwise.
    public static func select(_ pane: PaneID, from slot: SlotPosition?, state: PaneState, plan: SlotPlan) -> PaneState {
        guard plan.available.contains(pane) else { return state }
        var next = stateShown(by: plan, base: state)
        if pane == .overview && plan.overviewIsPanel {
            next.isOverviewPanelOpen.toggle()
            return tidy(next, plan: plan)
        }
        if let shown = plan.slots.first(where: { $0.content == .pane(pane) }) {
            return focus(shown.position, state: state, plan: plan)
        }
        let target = plan.slots.first { $0.position == slot && $0.content != .overviewPanel }?.position
        if plan.slots.count == 1 || next.isOverviewPanelOpen || target == nil || target == plan.focusedPosition {
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
        return next
    }

    /// Called when the arrangement changes (rotation, Split View, a window resize, fold, unfold, a pose).
    public static func arrangementChanged(
        from old: PaneArrangement, to new: PaneArrangement, state: PaneState, available: [PaneID], inputs: LayoutInputs
    ) -> PaneState {
        var next = state
        if old.slotCount == 2 && new.slotCount == 1 {
            if let second = next.second { next.lastSecond = second }
            next.second = nil
            next.secondLeads = false
        } else if old.slotCount == 1 && new.slotCount == 2 {
            let candidates = slotPanes(available: available, arrangement: new, inputs: inputs)
            if next.focused == .overview, let beside = next.second, candidates.contains(beside) {
                // Folded from an open panel: the pane that sat beside it comes back, `lastSecond` stays hidden.
            } else {
                next.second = defaultSecond(focused: next.focused, lastSecond: next.lastSecond, candidates: candidates)
            }
            next.secondLeads = false
        }
        next = resolve(next, available: available, arrangement: new, inputs: inputs)
        if new == .laptop, next.second != nil {
            if next.focused == .chat {
                next.secondLeads = false
            } else if next.second == .chat {
                next.secondLeads = true
            }
        }
        return next
    }

    /// Called when the thread's panes change (a pane hidden by the server, a pane plan merged).
    public static func availableChanged(state: PaneState, available: [PaneID], inputs: LayoutInputs) -> PaneState {
        let size = ThreadLayout.arrangement(inputs, slotPaneCount: Set(available).count)
        return resolve(state, available: available, arrangement: size, inputs: inputs)
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

    // MARK: Internals

    private static func isFirstOfPair(_ position: SlotPosition) -> Bool { position == .leading || position == .lower }

    /// The state with every self-contradiction repaired for `arrangement`: gone panes replaced, the Overview made a
    /// panel or a pane as the size says, and a second pane present wherever two pane slots need one.
    private static func resolve(
        _ state: PaneState, available: [PaneID], arrangement: PaneArrangement, inputs: LayoutInputs
    ) -> PaneState {
        let present = PaneID.allCases.filter(available.contains)
        var next = state
        next.stackedFraction = clampedFraction(next.stackedFraction)
        if !present.contains(next.focused) { next.focused = present.contains(.chat) ? .chat : (present.first ?? .chat) }
        if let last = next.lastSecond, !present.contains(last) { next.lastSecond = nil }
        let hasOverview = present.contains(.overview)
        let panel = hasOverview && overviewIsPanel(arrangement: arrangement, inputs: inputs)
        let candidates = slotPanes(available: present, arrangement: arrangement, inputs: inputs)
        if next.second == next.focused { next.second = nil }
        let secondIsGone = next.second.map { !candidates.contains($0) } ?? false

        if panel {
            if next.focused == .overview {
                next.isOverviewPanelOpen = true
                next.secondLeads = false
                let replacement = next.second.flatMap { candidates.contains($0) ? $0 : nil }
                next.focused =
                    replacement
                    ?? defaultSecond(focused: .overview, lastSecond: next.lastSecond, candidates: candidates) ?? .chat
                if next.second == next.focused { next.second = nil }
            }
        } else {
            if next.isOverviewPanelOpen && hasOverview {
                // The panel becomes the focused Overview pane; the pane it sat beside is kept as the second pane so
                // that opening the panel again brings it back. A hidden second pane moves to `lastSecond` (when two
                // slots show both) or stays in `lastSecond` already (when one slot shows neither).
                let beside = next.focused
                if beside != .overview {
                    if arrangement.slotCount == 2, let hidden = next.second, hidden != beside {
                        next.lastSecond = hidden
                    }
                    next.second = beside
                    next.secondLeads = arrangement.slotCount == 2
                }
                next.focused = .overview
            }
            next.isOverviewPanelOpen = false
            if next.second == next.focused { next.second = nil }
        }
        if !hasOverview { next.isOverviewPanelOpen = false }

        let needsSecond = arrangement.slotCount == 2 && !next.isOverviewPanelOpen
        if secondIsGone || (needsSecond && next.second == nil) {
            next.second = defaultSecond(focused: next.focused, lastSecond: next.lastSecond, candidates: candidates)
        }
        return next
    }

    /// The state that describes what `plan` shows.
    private static func stateShown(by plan: SlotPlan, base: PaneState) -> PaneState {
        var next = base
        next.isOverviewPanelOpen = plan.slots.contains { $0.content == .overviewPanel }
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

    /// A second pane that duplicates the focused one, is gone, or would be the panel is dropped.
    private static func tidy(_ state: PaneState, plan: SlotPlan) -> PaneState {
        var next = state
        if let second = next.second,
            second == next.focused || !plan.available.contains(second) || (second == .overview && plan.overviewIsPanel)
        {
            next.second = nil
        }
        return next
    }
}
