import SwiftUI
import YakKit

/// The Sub-threads group: the parent, the summary, one row per sub-thread, the awake stepper, the messages and
/// "Stop all sub-threads".
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
    @State private var areMessagesOpen = false
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        let rows = makeRows()
        OverviewGroupCard(kind: .subThreads, title: "overview.group.subThreads") {
            ForEach(rows.indices, id: \.self) { index in
                if index > 0 { RowDivider() }
                rows[index]
            }
        }
        .confirmationDialog(
            Text("overview.drop.title \(dropTarget?.title ?? "")"), isPresented: dropBinding, titleVisibility: .visible
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

    private func makeRows() -> [AnyView] {
        var rows: [AnyView] = []
        if let group {
            if let parent = group.parent {
                rows.append(AnyView(parentRow(parent)))
            }
            if group.total > 0 || group.parent == nil || onAdd != nil { rows.append(AnyView(summary(group))) }
            if case .refused(_, let message) = group.add { rows.append(AnyView(refusal(message))) }
            for row in group.rows {
                rows.append(AnyView(SubThreadRowView(row: row, model: model) { dropTarget = $0 }))
            }
            if let awake = group.awake { rows.append(AnyView(stepper(awake))) }
            if !group.messages.isEmpty { rows.append(AnyView(messages(group.messages))) }
            if group.canStop { rows.append(AnyView(stopAll)) }
        }
        if load != .ready {
            rows.append(
                AnyView(
                    GroupNotice(
                        load: load, showsSkeleton: model.showsSkeleton, retry: retry, openThreads: openThreads)))
        }
        return rows
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

    /// The count, the awake limit of a root under it, and "Add" on the trailing side. Add stays on screen and is
    /// disabled while the server refuses it.
    private func summary(_ group: SubThreadsGroup) -> some View {
        let isRefused = if case .refused = group.add { true } else { false }
        return OverviewRow(title: Text("drawer.children \(group.total) \(group.working)")) {
            if let awake = group.awake { Text("overview.awake.line \(model.pendingAwakeLimit ?? awake.value)") }
        } trailing: {
            if let onAdd {
                Button(action: onAdd) {
                    Label("overview.addSubThread", systemImage: "plus").font(.yak(.body, .medium))
                        .frame(minHeight: 44).contentShape(.rect)
                }
                .buttonStyle(MutedWhenDisabledButtonStyle(color: .primary)).disabled(isRefused)
                .accessibilityLabel(Text("overview.add")).accessibilityIdentifier("overview.addSubThread")
            }
        }
        .accessibilityIdentifier("overview.summary")
    }

    private func refusal(_ message: String) -> some View {
        Text(message).font(.yak(.footnote, .medium)).foregroundStyle(Color.yakMuted)
            .fixedSize(horizontal: false, vertical: true)
            .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 16).padding(.vertical, 10)
            .accessibilityIdentifier("overview.addRefusal")
    }

    private func stepper(_ awake: AwakeStepperValue) -> some View {
        let value = model.pendingAwakeLimit ?? awake.value
        let isBusy = model.runningAction == .limit || model.isOffline
        return VStack(alignment: .leading, spacing: 4) {
            HStack(spacing: 4) {
                Text("overview.awake.title").frame(maxWidth: .infinity, alignment: .leading)
                    .fixedSize(horizontal: false, vertical: true)
                stepButton(
                    "minus", label: "overview.awake.less", isEnabled: value > awake.range.lowerBound && !model.isOffline
                ) {
                    model.proposeAwakeLimit(value - 1)
                }
                Text(value, format: .number).font(.yak(.body)).frame(minWidth: 28)
                stepButton(
                    "plus", label: "overview.awake.more", isEnabled: value < awake.range.upperBound && !model.isOffline
                ) {
                    model.proposeAwakeLimit(value + 1)
                }
            }
            Text("overview.awake.counts \(awake.awake) \(awake.waiting)").font(.yak(.footnote, .medium))
                .foregroundStyle(Color.yakMuted)
        }
        .padding(.horizontal, 16).padding(.vertical, 8).opacity(isBusy && model.runningAction == .limit ? 0.7 : 1)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(Text("overview.awake.label \(value)"))
        .accessibilityValue(Text("overview.awake.counts \(awake.awake) \(awake.waiting)"))
        .accessibilityAdjustableAction { direction in
            guard !model.isOffline else { return }
            model.proposeAwakeLimit(direction == .increment ? value + 1 : value - 1)
        }
        .accessibilityIdentifier("overview.awake")
    }

    private func stepButton(_ symbol: String, label: LocalizedStringKey, isEnabled: Bool, action: @escaping () -> Void)
        -> some View
    {
        Button(action: action) {
            Image(systemName: symbol).frame(width: 44, height: 44).contentShape(.rect)
        }
        .buttonStyle(.plain).disabled(!isEnabled).accessibilityHidden(true)
    }

    private func messages(_ items: [StackMessage]) -> some View {
        VStack(alignment: .leading, spacing: 0) {
            Button {
                withMotion(Motion.content, reduceMotion: reduceMotion) { areMessagesOpen.toggle() }
            } label: {
                OverviewRow(title: Text("overview.messages.folded \(items.count)")) {
                    EmptyView()
                } trailing: {
                    Image(systemName: areMessagesOpen ? "chevron.up" : "chevron.down").font(.yak(.footnote, .semibold))
                        .foregroundStyle(Color.yakMuted).accessibilityHidden(true)
                }
                .contentShape(.rect)
            }
            .buttonStyle(.plain).accessibilityAddTraits(.isButton)
            .accessibilityValue(areMessagesOpen ? Text("overview.expanded") : Text("overview.collapsed"))
            .accessibilityIdentifier("overview.messages")
            if areMessagesOpen {
                ForEach(items) { item in
                    RowDivider()
                    messageRow(item)
                }
            }
        }
    }

    private func messageRow(_ item: StackMessage) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack(spacing: 6) {
                Text("overview.message.fromTo \(item.fromName) \(item.toName)").font(.yak(.footnote, .semibold))
                if let kind = kindLabel(item.kind) {
                    kind.font(.yak(.footnote, .medium)).foregroundStyle(Color.yakMuted)
                }
            }
            Text(item.text).font(.yak(.callout)).textSelection(.enabled).fixedSize(horizontal: false, vertical: true)
            if let age = age(of: item) { age.font(.yak(.footnote, .medium)).foregroundStyle(Color.yakMuted) }
        }
        .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 16).padding(.vertical, 10)
        .accessibilityElement(children: .combine).accessibilityIdentifier("overview.message.\(item.id.value)")
    }

    private func kindLabel(_ kind: StackMessageKind) -> Text? {
        switch kind {
        case .result: Text("overview.message.kind.result")
        case .question: Text("overview.message.kind.question")
        case .answer: Text("overview.message.kind.answer")
        case .failure: Text("overview.message.kind.failure")
        default: nil
        }
    }

    private func age(of item: StackMessage) -> Text? {
        guard let date = RelativeAge.parse(item.createdAt) else { return nil }
        switch RelativeAge.value(from: date, now: Date()) {
        case .now: return Text("overview.age.now")
        case .minutes(let value): return Text("overview.age.minutes \(value)")
        case .hours(let value): return Text("overview.age.hours \(value)")
        case .days(let value): return Text("overview.age.days \(value)")
        case .date(let value): return Text(value.formatted(date: .abbreviated, time: .omitted))
        }
    }

    private var stopAll: some View {
        let isStopping = model.runningAction == .stop
        return Button(role: .destructive) {
            isStopAskOpen = true
        } label: {
            HStack(spacing: 8) {
                if isStopping { ProgressView() }
                Text(isStopping ? "overview.stopAll.stopping" : "overview.stopAll")
                    .fixedSize(horizontal: false, vertical: true)
                Spacer(minLength: 0)
            }
            .padding(.horizontal, 16).padding(.vertical, 10)
            .frame(minHeight: 44).contentShape(.rect)
        }
        .buttonStyle(MutedWhenDisabledButtonStyle(color: .yakWarning)).disabled(isStopping || model.isOffline)
        .accessibilityHint(model.isOffline ? Text("overview.notOffline") : Text(""))
        .accessibilityIdentifier("overview.stopAll")
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
