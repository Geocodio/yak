import Observation
import SwiftUI
import YakKit

/// What the chips above the composer remember about their messages: which one an action is running for, why an
/// action failed, and what is opened out. Actions are passed in, so the rules are tested without a thread.
@MainActor @Observable
final class QueuedChipsModel {
    private(set) var busy: Set<String> = []
    private(set) var failures: [String: String] = [:]
    private(set) var expandedTexts: Set<String> = []
    private(set) var isListExpanded = false

    /// Runs one action on one message; a second action on a message that is busy is ignored. A refusal or failure
    /// stays beside the message until the next action on it.
    func run(_ uuid: String, _ work: @MainActor () async -> ThreadActionOutcome) async {
        guard !busy.contains(uuid) else { return }
        busy.insert(uuid)
        failures[uuid] = nil
        let outcome = await work()
        busy.remove(uuid)
        failures[uuid] = ThreadModel.words(for: outcome)
    }

    func toggleText(_ uuid: String) {
        if expandedTexts.contains(uuid) { expandedTexts.remove(uuid) } else { expandedTexts.insert(uuid) }
    }

    func toggleList() { isListExpanded.toggle() }

    /// Forgets messages that are no longer waiting.
    func keep(_ uuids: Set<String>) {
        failures = failures.filter { uuids.contains($0.key) }
        expandedTexts.formIntersection(uuids)
        busy.formIntersection(uuids)
    }
}

/// The messages waiting for a busy thread, above the native composer while the conversation is the web page (which
/// leaves them out of its own list). Each offers what the native chat's own waiting message offered.
struct QueuedChipsView: View {
    let thread: ThreadModel
    @State private var model = QueuedChipsModel()
    @State private var editing: EditTarget?
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    private struct EditTarget: Identifiable {
        let uuid: String
        let text: String
        var id: String { uuid }
    }

    private var chips: [QueuedChip] { QueuedChips.chips(from: thread.snapshot?.show?.messages ?? []) }
    private var canManage: Bool { QueuedChips.canManage(composerState: thread.snapshot?.show?.composer.state) }

    var body: some View {
        let all = chips
        let list = QueuedChips.visible(all, isExpanded: model.isListExpanded)
        Group {
            if !all.isEmpty {
                VStack(spacing: 0) {
                    ForEach(list.shown) { chip in
                        if chip.id != list.shown.first?.id { Divider().overlay(Color.yakBorderStrong) }
                        row(chip)
                    }
                    if all.count > QueuedChips.collapsedLimit {
                        Divider().overlay(Color.yakBorderStrong)
                        moreRow(hiddenCount: list.hiddenCount)
                    }
                    Rectangle().fill(Color.yakBorderStrong).frame(height: Design.Space.line)
                }
                .background(Color.yakBubble)
                .accessibilityElement(children: .contain)
                .accessibilityLabel("queued.list.label")
                .accessibilityIdentifier("queued.list")
                .transition(.opacity)
            }
        }
        .motion(Motion.content, value: list.shown)
        .motion(Motion.content, value: model.isListExpanded)
        .onChange(of: all.map(\.uuid), initial: true) { model.keep(Set(all.map(\.uuid))) }
        .sheet(item: $editing) { target in
            EditQueuedSheet(uuid: target.uuid, text: target.text, thread: thread)
        }
    }

    // MARK: Rows

    private func row(_ chip: QueuedChip) -> some View {
        let isBusy = model.busy.contains(chip.uuid)
        let isDisabled = isBusy || thread.isOffline || !canManage
        let isExpanded = model.expandedTexts.contains(chip.uuid)
        return VStack(alignment: .leading, spacing: Design.Space.nudge) {
            HStack(alignment: .center, spacing: Design.Space.compact) {
                modeLabel(chip)
                Button {
                    model.toggleText(chip.uuid)
                } label: {
                    Text(verbatim: chip.text).font(.yak(.footnote)).foregroundStyle(.primary)
                        .lineLimit(isExpanded ? nil : 2).multilineTextAlignment(.leading)
                        .fixedSize(horizontal: false, vertical: true).id(dynamicTypeSize)
                        .frame(maxWidth: .infinity, minHeight: Design.Size.control, alignment: .leading)
                        .contentShape(.rect)
                }
                .buttonStyle(.plain)
                .accessibilityHint(isExpanded ? Text("queued.collapse.hint") : Text("queued.expand.hint"))
                .accessibilityIdentifier("queued.text.\(chip.uuid)")
                trailing(chip, isBusy: isBusy, isDisabled: isDisabled)
            }
            if let detail = detail(chip) {
                Text(verbatim: detail).font(.yak(.caption, .medium)).foregroundStyle(Color.yakMuted)
                    .fixedSize(horizontal: false, vertical: true).id(dynamicTypeSize)
            }
            if let failure = model.failures[chip.uuid] {
                Text(verbatim: failure).font(.yak(.caption, .medium)).foregroundStyle(Color.yakDanger)
                    .fixedSize(horizontal: false, vertical: true).id(dynamicTypeSize)
                    .accessibilityIdentifier("queued.failure.\(chip.uuid)")
            }
        }
        .padding(.leading, Design.Space.medium).padding(.trailing, Design.Space.tight)
        .opacity(isBusy ? Design.disabledOpacity : 1)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("queued.row.\(chip.uuid)")
    }

    private func modeLabel(_ chip: QueuedChip) -> some View {
        Label(
            chip.isQueued ? "queued.mode.queued" : "queued.mode.steering",
            systemImage: chip.isQueued ? "clock" : "arrow.turn.up.right"
        )
        .font(.yak(.caption, .medium)).foregroundStyle(Color.yakMuted)
        .labelStyle(.titleAndIcon).fixedSize(horizontal: false, vertical: true).id(dynamicTypeSize)
    }

    /// Where the message came from and how many files it carries, in one quiet line.
    private func detail(_ chip: QueuedChip) -> String? {
        var parts: [String] = []
        if let source = chip.sourceLabel { parts.append(String(localized: "queued.via \(source)")) }
        if chip.attachmentCount > 0 { parts.append(String(localized: "queued.attached \(chip.attachmentCount)")) }
        return parts.isEmpty ? nil : parts.joined(separator: " · ")
    }

    @ViewBuilder private func trailing(_ chip: QueuedChip, isBusy: Bool, isDisabled: Bool) -> some View {
        if isBusy {
            ProgressView().controlSize(.small).frame(minWidth: Design.Size.control, minHeight: Design.Size.control)
                .accessibilityLabel("queued.sending").accessibilityIdentifier("queued.sending.\(chip.uuid)")
        } else {
            Menu {
                ForEach(chip.menu, id: \.self) { item in menuButton(item, chip: chip) }
            } label: {
                Image(systemName: "ellipsis").foregroundStyle(.primary)
                    .frame(minWidth: Design.Size.control, minHeight: Design.Size.control).contentShape(.rect)
            }
            .tint(.primary).disabled(isDisabled).opacity(isDisabled ? Design.disabledOpacity : 1)
            .accessibilityLabel("chat.message.menu")
            .accessibilityIdentifier("queued.menu.\(chip.uuid)")
        }
    }

    @ViewBuilder private func menuButton(_ item: QueuedMessageMenuItem, chip: QueuedChip) -> some View {
        switch item {
        case .edit:
            Button {
                editing = EditTarget(uuid: chip.uuid, text: chip.text)
            } label: {
                Label("chat.message.edit", systemImage: "pencil")
            }
        case .sendNow:
            Button {
                Task {
                    await model.run(chip.uuid) {
                        await thread.setQueuedMode(chip.uuid, mode: .steer, reportsLine: false)
                    }
                }
            } label: {
                Label("chat.message.sendNow", systemImage: "paperplane")
            }
        case .sendAfterTurn:
            Button {
                Task {
                    await model.run(chip.uuid) {
                        await thread.setQueuedMode(chip.uuid, mode: .queue, reportsLine: false)
                    }
                }
            } label: {
                Label("chat.message.sendAfterTurn", systemImage: "clock")
            }
        case .remove:
            Button(role: .destructive) {
                Task { await model.run(chip.uuid) { await thread.withdrawQueued(chip.uuid, reportsLine: false) } }
            } label: {
                Label("chat.message.remove", systemImage: "trash")
            }
        }
    }

    private func moreRow(hiddenCount: Int) -> some View {
        Button {
            model.toggleList()
        } label: {
            Group {
                if model.isListExpanded { Text("queued.fewer") } else { Text("queued.more \(hiddenCount)") }
            }
            .font(.yak(.footnote, .semibold)).foregroundStyle(.primary)
            .fixedSize(horizontal: false, vertical: true).id(dynamicTypeSize)
            .frame(maxWidth: .infinity, minHeight: Design.Size.control, alignment: .leading)
            .padding(.horizontal, Design.Space.medium).contentShape(.rect)
        }
        .buttonStyle(.plain)
        .accessibilityIdentifier("queued.more")
    }
}
