import SwiftUI
import YakKit

/// The thread's detail: measures it, asks the layout rules which slots to draw, and lays the panes and their bars out.
/// The pane host stays at one place in the tree for every arrangement, so a pane that moves between slots, or between
/// one slot and two, is never rebuilt. Below the panes, a single pane has the one bar it always had; two panes each
/// get a bar under their own slot, so no content sits under a bar.
struct ThreadLayoutView: View {
    static let coordinateSpace = "thread.detail"

    let thread: ThreadModel
    let scene: SceneModel
    /// What the panes handed up for the bar and the menu.
    let slots: PaneSlots
    /// The pane control of a single pane, from the size classes and the system strip.
    let singlePlacement: PaneControlPlacement
    let hasStrip: Bool
    let isCompactWidth: Bool
    /// The activity line above a pane's bar, or nil.
    let activity: (PaneID) -> ActivityLine?
    let openChat: (_ focusComposer: Bool) -> Void

    @State private var model = PaneSlotsModel()
    /// The upper share while the handle is being dragged.
    @State private var liveFraction: Double?
    @Environment(\.verticalSizeClass) private var verticalSizeClass
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    private var hasLoadedPanes: Bool { thread.snapshot?.show?.panes != nil }
    private var cornerRadius: CGFloat { hasStrip ? 28 : 24 }

    var body: some View {
        GeometryReader { proxy in
            let inputs = LayoutInputs(
                isCompactWidth: isCompactWidth, isCompactHeight: verticalSizeClass == .compact,
                detailWidth: Double(proxy.size.width), hasVerticalToolbarEdge: hasStrip)
            let plan = PaneLayoutRules.plan(scene.paneState, available: thread.panes, inputs: inputs)
            layout(plan)
                .onChange(of: inputs, initial: true) { old, new in inputsChanged(from: old, to: new) }
                .onChange(of: thread.panes) { availableChanged(inputs) }
                .onChange(of: plan, initial: true) {
                    scene.updatePlan(plan)
                    model.show(plan)
                }
        }
        .onDisappear { scene.updatePlan(nil) }
    }

    // MARK: Rules

    private func inputsChanged(from old: LayoutInputs, to new: LayoutInputs) {
        guard hasLoadedPanes else { return }
        let count = Set(thread.panes).count
        scene.apply(
            PaneLayoutRules.arrangementChanged(
                from: ThreadLayout.arrangement(old, slotPaneCount: count),
                to: ThreadLayout.arrangement(new, slotPaneCount: count), state: scene.paneState,
                available: thread.panes, inputs: new, oldInputs: old))
    }

    private func availableChanged(_ inputs: LayoutInputs) {
        guard hasLoadedPanes else { return }
        scene.apply(PaneLayoutRules.availableChanged(state: scene.paneState, available: thread.panes, inputs: inputs))
    }

    private func paneInFocus(_ plan: SlotPlan) -> PaneID {
        for slot in plan.slots where slot.position == plan.focusedPosition {
            if case .pane(let pane) = slot.content { return pane }
        }
        return .chat
    }

    // MARK: Layout

    private func layout(_ plan: SlotPlan) -> some View {
        let focused = paneInFocus(plan)
        return VStack(spacing: 0) {
            if singlePlacement.hidesTitle, let line = thread.connectionLine { ConnectionBanner(line: line) }
            ZStack {
                PaneHost(thread: thread, focused: focused, slots: model, cornerRadius: cornerRadius)
                    .frame(
                        maxWidth: .infinity, maxHeight: .infinity,
                        alignment: plan.arrangement == .single ? .center : .topLeading)
                slotLayer(plan, focused: focused)
            }
            .background(plan.arrangement == .single ? Color.clear : Color(.secondarySystemBackground))
            .coordinateSpace(name: Self.coordinateSpace)
            if plan.arrangement == .single {
                bar(for: focused, placement: singlePlacement, slot: nil, beside: nil, plan: plan)
            }
        }
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("thread.detail")
        .accessibilityValue(plan.arrangement.rawValue)
    }

    @ViewBuilder private func slotLayer(_ plan: SlotPlan, focused: PaneID) -> some View {
        switch plan.arrangement {
        case .single:
            EmptyView()
        case .sideBySide, .book:
            HStack(spacing: 6) {
                ForEach(plan.slots, id: \.position) { slot($0, plan: plan, focused: focused) }
            }
        case .stacked, .laptop:
            stacked(plan, focused: focused)
        }
    }

    private func stacked(_ plan: SlotPlan, focused: PaneID) -> some View {
        GeometryReader { proxy in
            let hasHandle = plan.arrangement.hasDragHandle
            let band = hasHandle ? PaneDivider.bandHeight : 6
            let available = max(0, proxy.size.height - band)
            let fraction = PaneLayoutRules.clampedFraction(liveFraction ?? plan.upperFraction ?? 0.5)
            let upper = plan.slots.first { $0.position == .upper }
            let lower = plan.slots.first { $0.position == .lower }
            VStack(spacing: 0) {
                if let upper {
                    slot(upper, plan: plan, focused: focused, height: hasHandle ? available * fraction : nil)
                }
                if hasHandle {
                    PaneDivider(
                        fraction: fraction, availableHeight: available,
                        upperName: PaneCatalog.descriptor(for: upper.flatMap(pane(of:)) ?? .chat)?.name ?? "pane.chat",
                        drag: { liveFraction = PaneLayoutRules.clampedFraction($0) },
                        commit: {
                            var next = scene.paneState
                            next.stackedFraction = $0
                            scene.apply(next)
                            liveFraction = nil
                        })
                } else {
                    Color.clear.frame(height: band)
                }
                if let lower { slot(lower, plan: plan, focused: focused) }
            }
            .frame(height: proxy.size.height)
        }
    }

    private func pane(of slot: PaneSlot) -> PaneID? {
        if case .pane(let pane) = slot.content { pane } else { nil }
    }

    /// One slot: its marker (or the Overview panel) and, below it, its bar.
    private func slot(_ slot: PaneSlot, plan: SlotPlan, focused: PaneID, height: CGFloat? = nil) -> some View {
        VStack(spacing: 6) {
            switch slot.content {
            case .pane(let pane):
                SlotMarker(
                    position: slot.position, pane: pane, isFocused: slot.position == plan.focusedPosition,
                    model: model, focus: { scene.focusSlot(slot.position) })
            case .overviewPanel:
                GeometryReader { proxy in
                    OverviewSidePanel(thread: thread, width: Double(proxy.size.width)) { scene.select(.overview) }
                }
                .background(Color(.systemBackground))
                .clipShape(RoundedRectangle(cornerRadius: cornerRadius))
            }
            if let entry = plan.bars[slot.position] {
                let shown =
                    plan.arrangement == .stacked || plan.arrangement == .laptop ? focused : (pane(of: slot) ?? focused)
                bar(
                    for: shown, placement: entry, slot: slot.position,
                    beside: plan.slots.compactMap(pane(of:)).first { $0 != shown }, plan: plan)
            }
        }
        .frame(maxWidth: .infinity, maxHeight: height == nil ? .infinity : nil)
        .frame(height: height)
    }

    private func bar(
        for pane: PaneID, placement: PaneControlPlacement?, slot: SlotPosition?, beside: PaneID?, plan: SlotPlan
    ) -> some View {
        let line = plan.shownPanes.contains(.chat) ? nil : activity(pane)
        return VStack(spacing: 6) {
            if let line { ActivityStrip(line: line) { openChat($0) } }
            BottomBar(
                thread: thread, slots: slots, placement: placement, pane: pane, slot: slot,
                select: { scene.select($0, from: slot) }, beside: beside)
        }
        .animation(.easeInOut(duration: 0.2), value: line)
    }
}
