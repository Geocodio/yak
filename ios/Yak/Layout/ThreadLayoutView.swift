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
    @State private var hinge = HingeState()
    /// The upper share while the handle is being dragged.
    @State private var liveFraction: Double?
    @Environment(\.verticalSizeClass) private var verticalSizeClass
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @Environment(\.webConversation) private var web
    /// The least height a stacked slot keeps: its marker and its bar.
    @ScaledMetric(relativeTo: .body) private var minimumSlotHeight: CGFloat = 150

    private var hasLoadedPanes: Bool { thread.snapshot?.show?.panes != nil }
    private var cornerRadius: CGFloat { hasStrip ? 28 : 24 }

    var body: some View {
        GeometryReader { proxy in
            let inputs = LayoutInputs(
                isCompactWidth: isCompactWidth, isCompactHeight: verticalSizeClass == .compact,
                detailWidth: Double(proxy.size.width), hasVerticalToolbarEdge: hasStrip, posture: hinge.posture,
                fold: hinge.fold, isKeyboardOnLowerHalf: hinge.isKeyboardOnLowerHalf)
            let plan = PaneLayoutRules.plan(scene.paneState, available: thread.panes, inputs: inputs)
            layout(plan)
                .onChange(of: inputs, initial: true) { old, new in inputsChanged(from: old, to: new) }
                .onChange(of: thread.panes) { availableChanged(inputs) }
                .onChange(of: plan, initial: true) { place(plan) }
                .onChange(of: inputs.isKeyboardOnLowerHalf) { place(plan) }
        }
        .readsHinge(into: hinge)
        .onDisappear { scene.clearPlan(ownedBy: thread.threadID) }
    }

    /// Hands the plan to the scene and the host. In the laptop pose with the keyboard on the lower half, the pane that
    /// is not focused is hidden (alive, no frame) until the keyboard closes.
    private func place(_ plan: SlotPlan) {
        scene.updatePlan(plan, ownedBy: thread.threadID)
        model.show(plan)
        if plan.arrangement == .laptop, hinge.isKeyboardOnLowerHalf {
            for slot in plan.slots where slot.position != plan.focusedPosition { model.clear(slot.position) }
        }
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
            // The saved page of the web conversation carries its own line.
            if singlePlacement.hidesTitle,
                let line = HeaderConnection.line(
                    thread.connectionLine, chatIsVisible: focused == .chat,
                    chatShowsOwnLine: web.map {
                        ($0.savedCopy != nil && $0.savedCopyState != nil) || $0.showsOfflineLine
                    }
                        ?? false)
            {
                ConnectionBanner(line: line)
            }
            ZStack {
                PaneHost(
                    thread: thread, focused: focused, slots: model, pickCount: scene.panePickCount,
                    cornerRadius: cornerRadius
                )
                .frame(
                    maxWidth: .infinity, maxHeight: .infinity,
                    alignment: plan.arrangement == .single ? .center : .topLeading)
                slotLayer(plan, focused: focused)
            }
            .background(plan.arrangement == .single ? Color.clear : Color.yakCard)
            .coordinateSpace(name: Self.coordinateSpace)
            if plan.arrangement == .single {
                bar(for: focused, placement: singlePlacement, slot: nil, beside: nil, plan: plan)
            }
        }
        .accessibilityElement(children: .contain)
        // The arrangement is in the identifier, which VoiceOver never speaks; the layout tests read it from there.
        .accessibilityIdentifier("thread.detail.\(plan.arrangement.rawValue)")
    }

    @ViewBuilder private func slotLayer(_ plan: SlotPlan, focused: PaneID) -> some View {
        switch plan.arrangement {
        case .single:
            EmptyView()
        case .sideBySide:
            sideBySide(plan, focused: focused)
        case .stacked:
            stacked(plan, focused: focused)
        case .book, .laptop:
            fold(plan, focused: focused)
        }
    }

    private func sideBySide(_ plan: SlotPlan, focused: PaneID) -> some View {
        HStack(spacing: 6) {
            ForEach(plan.slots, id: \.position) { slot($0, plan: plan, focused: focused) }
        }
    }

    /// The two poses around the fold. Without the system's fold API the poses fall back to the plain stacks.
    @ViewBuilder private func fold(_ plan: SlotPlan, focused: PaneID) -> some View {
        if plan.arrangement == .laptop, hinge.isKeyboardOnLowerHalf,
            let own = plan.slots.first(where: { $0.position == plan.focusedPosition })
        {
            // The system puts the keyboard on the lower half: the focused pane takes the space above it, with the
            // one bar on the keyboard.
            slot(own, plan: plan, focused: focused, forcedBar: .barRow)
        } else if #available(iOS 27.1, *), plan.slots.count == 2 {
            FoldSlots(
                primary: slot(plan.slots[0], plan: plan, focused: focused),
                secondary: slot(plan.slots[1], plan: plan, focused: focused))
        } else if plan.arrangement == .laptop {
            stacked(plan, focused: focused)
        } else {
            sideBySide(plan, focused: focused)
        }
    }

    private func stacked(_ plan: SlotPlan, focused: PaneID) -> some View {
        GeometryReader { proxy in
            let hasHandle = plan.arrangement.hasDragHandle
            let band = hasHandle ? PaneDivider.bandHeight : 6
            let available = max(0, proxy.size.height - band)
            let fraction = PaneLayoutRules.clampedFraction(
                liveFraction ?? plan.upperFraction ?? 0.5, availableHeight: Double(available),
                minimumSlotHeight: Double(minimumSlotHeight))
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
    private func slot(
        _ slot: PaneSlot, plan: SlotPlan, focused: PaneID, height: CGFloat? = nil,
        forcedBar: PaneControlPlacement? = nil
    ) -> some View {
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
                .background(.yakPage)
                .clipShape(RoundedRectangle(cornerRadius: cornerRadius))
            }
            if let entry = forcedBar.map({ Optional($0) }) ?? plan.bars[slot.position] {
                let shown =
                    plan.arrangement == .stacked || plan.arrangement == .laptop ? focused : (pane(of: slot) ?? focused)
                bar(
                    for: shown, placement: entry, slot: forcedBar == nil ? slot.position : .lower,
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
        .motion(Motion.crossFade, value: line)
    }
}
