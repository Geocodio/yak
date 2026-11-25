import SwiftUI
import YakKit

/// The Sub-threads group in the web's words: the heading with the count, Add and a menu with "Stop all sub-threads",
/// the definition line, one row per sub-thread, and the limit line with the stepper behind "Change".
struct SubThreadsGroupView: View {
    let group: SubThreadsGroup?
    let load: GroupLoad
    let model: OverviewModel
    let retry: () -> Void
    let openThreads: () -> Void
    /// Opens the add sheet; nil when the person cannot drive this thread.
    var onAdd: (() -> Void)?

    @State private var dropTarget: SubThreadRow?
    @State private var isStopAskOpen = false
    @State private var isChangingLimit = false
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        VStack(alignment: .leading, spacing: Design.Space.small) {
            header
            Text("subThread.definition").font(.yak(.footnote, .medium)).foregroundStyle(Color.yakMuted)
                .fixedSize(horizontal: false, vertical: true)
                .padding(.horizontal, Design.Space.medium).padding(.bottom, Design.Space.tight)
                .accessibilityIdentifier("overview.definition")
            let items = makeItems()
            if !items.isEmpty {
                VStack(alignment: .leading, spacing: 0) {
                    ForEach(Array(items.enumerated()), id: \.element.id) { index, item in
                        if index > 0 { RowDivider() }
                        item.view
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Color.yakCard, in: .rect(cornerRadius: Design.Radius.group))
            }
            if let group, let line = group.limitLine { limitLine(line, awake: group.awake) }
        }
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("overview.group.\(OverviewGroupKind.subThreads.rawValue)")
        .confirmationDialog(
            Text("overview.drop.title \(dropTarget?.name ?? "")"), isPresented: dropBinding, titleVisibility: .visible
        ) {
            Button("overview.child.drop", role: .destructive) {
                if let target = dropTarget { Task { await model.perform(.drop, on: target.id) } }
            }
            Button("overview.cancel", role: .cancel) {}
        } message: {
            Text("overview.drop.message")
        }
        .confirmationDialog(
            Text("overview.stopAll.title"), isPresented: $isStopAskOpen, titleVisibility: .visible
        ) {
            Button("overview.stopAll.confirm", role: .destructive) { Task { await model.stopAll() } }
            Button("overview.cancel", role: .cancel) {}
        } message: {
            Text("overview.stopAll.message")
        }
        .sensoryFeedback(.warning, trigger: dropTarget?.id) { _, id in id != nil }
        .sensoryFeedback(.warning, trigger: isStopAskOpen) { _, isOpen in isOpen }
        .sensoryFeedback(.error, trigger: model.refusalCount)
    }

    private var dropBinding: Binding<Bool> {
        Binding(get: { dropTarget != nil }, set: { if !$0 { dropTarget = nil } })
    }

    // MARK: Heading

    private var header: some View {
        // At the largest text sizes the buttons go under the heading, so it keeps the whole width.
        let layout =
            dynamicTypeSize.isAccessibilitySize
            ? AnyLayout(VStackLayout(alignment: .leading, spacing: Design.Space.tight))
            : AnyLayout(HStackLayout(spacing: Design.Space.small))
        return layout {
            HStack(spacing: Design.Space.small) {
                Image(systemName: "square.3.layers.3d").font(.yak(.footnote, .semibold)).accessibilityHidden(true)
                Text("overview.group.subThreads").font(.yakGroupHeading).accessibilityAddTraits(.isHeader)
                if let group {
                    Text("subThread.count \(group.total) \(group.maxChildren)").font(.yak(.footnote, .medium))
                }
            }
            .fixedSize(horizontal: false, vertical: true)
            .foregroundStyle(Color.yakMuted)
            .accessibilityElement(children: .combine)
            if !dynamicTypeSize.isAccessibilitySize { Spacer(minLength: 0) }
            HStack(spacing: 0) {
                if let onAdd { addButton(onAdd) }
                if let group, group.canStop, group.total > 0 { sectionMenu }
            }
        }
        .padding(.leading, Design.Space.medium)
    }

    private func addButton(_ action: @escaping () -> Void) -> some View {
        let isRefused = if case .refused = group?.add { true } else { false }
        return Button(action: action) {
            Image(systemName: "plus").font(.yak(.body, .medium))
                .frame(minWidth: Design.Size.control, minHeight: Design.Size.control).contentShape(.rect)
        }
        .buttonStyle(MutedWhenDisabledButtonStyle(color: .primary)).disabled(isRefused)
        .accessibilityLabel(Text("overview.add")).accessibilityIdentifier("overview.addSubThread")
    }

    private var sectionMenu: some View {
        let isStopping = model.runningAction == .stop
        return Menu {
            Button(role: .destructive) {
                isStopAskOpen = true
            } label: {
                Label("overview.stopAll", systemImage: "stop.circle")
            }
        } label: {
            Group {
                if isStopping { ProgressView() } else { Image(systemName: "ellipsis").font(.yak(.body, .medium)) }
            }
            .frame(minWidth: Design.Size.control, minHeight: Design.Size.control).contentShape(.rect)
        }
        .tint(.primary)
        .disabled(isStopping || model.isOffline)
        .accessibilityLabel(Text("subThread.menu"))
        .accessibilityHint(model.isOffline ? Text("overview.notOffline") : Text(""))
        .accessibilityIdentifier("overview.menu")
    }

    // MARK: Rows

    private struct Item: Identifiable {
        let id: String
        let view: AnyView
    }

    private func makeItems() -> [Item] {
        var items: [Item] = []
        if let group {
            if let parent = group.parent { items.append(Item(id: "parent", view: AnyView(parentRow(parent)))) }
            if case .refused(_, let message) = group.add {
                items.append(Item(id: "refusal", view: AnyView(refusal(message))))
            }
            for row in group.rows {
                items.append(
                    Item(
                        id: "row:\(row.id)", view: AnyView(SubThreadRowView(row: row, model: model) { dropTarget = $0 })
                    ))
            }
        }
        if load != .ready {
            items.append(
                Item(
                    id: "notice",
                    view: AnyView(
                        GroupNotice(
                            load: load, showsSkeleton: model.showsSkeleton, retry: retry, openThreads: openThreads))))
        }
        return items
    }

    private func parentRow(_ parent: StackParent) -> some View {
        Button {
            model.openParent()
        } label: {
            OverviewRow(title: Text("overview.parent \(parent.title)")) {
                EmptyView()
            } trailing: {
                Image(systemName: "chevron.right").font(.yak(.footnote, .semibold)).foregroundStyle(Color.yakMuted)
                    .accessibilityHidden(true)
            }
            .contentShape(.rect)
        }
        .buttonStyle(.plain).accessibilityElement(children: .combine).accessibilityIdentifier("overview.parent")
    }

    private func refusal(_ message: String) -> some View {
        Text(message).font(.yak(.footnote, .medium)).foregroundStyle(Color.yakMuted)
            .fixedSize(horizontal: false, vertical: true)
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.horizontal, Design.Space.rowInset).padding(.vertical, Design.Space.snug)
            .accessibilityIdentifier("overview.addRefusal")
    }

    // MARK: Limit

    /// "2 can run at the same time" with "Change", which swaps the line for the stepper.
    @ViewBuilder private func limitLine(_ line: SubThreadsGroup.LimitLine, awake: AwakeStepperValue?) -> some View {
        if isChangingLimit, let awake {
            stepper(awake)
        } else {
            let layout =
                dynamicTypeSize.isAccessibilitySize
                ? AnyLayout(VStackLayout(alignment: .leading, spacing: Design.Space.nudge))
                : AnyLayout(HStackLayout(spacing: Design.Space.compact))
            layout {
                Text("subThread.limit.line \(model.pendingAwakeLimit ?? line.current)")
                    .font(.yak(.footnote, .medium)).foregroundStyle(Color.yakMuted)
                    .fixedSize(horizontal: false, vertical: true)
                if line.canChange, awake != nil {
                    Button {
                        isChangingLimit = true
                    } label: {
                        Text("subThread.limit.change").font(.yak(.footnote, .semibold))
                            .frame(minHeight: Design.Size.control).contentShape(.rect)
                    }
                    .buttonStyle(.plain).foregroundStyle(Color.yakSlate)
                    .accessibilityIdentifier("overview.limit.change")
                }
            }
            .padding(.horizontal, Design.Space.medium)
        }
    }

    private func stepper(_ awake: AwakeStepperValue) -> some View {
        let value = model.pendingAwakeLimit ?? awake.value
        let isBusy = model.runningAction == .limit || model.isOffline
        return VStack(alignment: .leading, spacing: Design.Space.tight) {
            HStack(spacing: Design.Space.tight) {
                Text("overview.awake.title").font(.yak(.footnote, .medium)).foregroundStyle(Color.yakMuted)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .fixedSize(horizontal: false, vertical: true)
                stepButton(
                    "minus", isEnabled: value > awake.range.lowerBound && !model.isOffline
                ) {
                    model.proposeAwakeLimit(value - 1)
                }
                Text(value, format: .number).font(.yak(.body)).frame(minWidth: Design.Size.stepValue)
                stepButton(
                    "plus", isEnabled: value < awake.range.upperBound && !model.isOffline
                ) {
                    model.proposeAwakeLimit(value + 1)
                }
            }
            Text("subThread.limit.note \(awake.range.upperBound)").font(.yak(.footnote, .medium))
                .foregroundStyle(Color.yakMuted).fixedSize(horizontal: false, vertical: true)
        }
        .padding(.horizontal, Design.Space.medium)
        .opacity(isBusy && model.runningAction == .limit ? Design.busyOpacity : 1)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(Text("overview.awake.label \(value)"))
        .accessibilityValue(Text("overview.awake.counts \(awake.awake) \(awake.waiting)"))
        .accessibilityAdjustableAction { direction in
            guard !model.isOffline else { return }
            model.proposeAwakeLimit(direction == .increment ? value + 1 : value - 1)
        }
        .accessibilityIdentifier("overview.awake")
    }

    private func stepButton(_ symbol: String, isEnabled: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: symbol).frame(width: Design.Size.control, height: Design.Size.control)
                .background(Color.yakFill, in: .rect(cornerRadius: Design.Radius.control))
                .contentShape(.rect)
        }
        .buttonStyle(.plain).disabled(!isEnabled).accessibilityHidden(true)
    }
}

/// A label in one color, and the muted color while the button is disabled. The plain style would dim the label to a
/// contrast the accessibility audit rejects.
struct MutedWhenDisabledButtonStyle: ButtonStyle {
    let color: Color
    @Environment(\.isEnabled) private var isEnabled

    func makeBody(configuration: Configuration) -> some View {
        configuration.label.foregroundStyle(isEnabled ? color : Color.yakMuted)
            .opacity(configuration.isPressed ? 0.6 : 1)
    }
}
