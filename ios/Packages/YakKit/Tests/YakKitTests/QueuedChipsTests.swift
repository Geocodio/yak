import Testing

@testable import YakKit

private func message(
    _ id: String, hasUUID: Bool = true, state: MessageState = .pending, mode: MessageMode? = .queue,
    canEditText: Bool = true, isRetry: Bool = false
) -> ThreadMessage {
    ThreadMessage(
        id: id, uuid: hasUUID ? "uuid-\(id)" : nil, text: "Text of \(id)", authorInitials: "MH", state: state,
        mode: mode,
        sentAt: "2026-11-20T10:00:00Z", bodyHtml: "", isRetry: isRetry, canEditText: canEditText)
}

private func queued(_ count: Int) -> [QueuedChip] {
    QueuedChips.chips(from: (1...count).map { message("queued-message-\($0)") })
}

@Test func onlyWaitingMessagesTheServerSavedAsQueuedAreChips() {
    let messages = [
        message("42", state: .read, mode: nil),
        // A steer row Yak has not read yet stays in the page's own list.
        message("43", mode: nil),
        message("queued-message-7", state: .read),
        message("queued-message-8", hasUUID: false),
        message("queued-message-9", isRetry: true),
        message("queued-message-10", mode: .steer, canEditText: false),
        message("queued-message-11"),
    ]
    let chips = QueuedChips.chips(from: messages)
    #expect(chips.map(\.uuid) == ["uuid-queued-message-10", "uuid-queued-message-11"])
    #expect(chips[0].isQueued == false && chips[1].isQueued)
    #expect(chips[0].menu == [.sendAfterTurn, .remove])
    #expect(chips[1].menu == [.edit, .sendNow, .remove])
}

@Test func aChipCarriesItsFilesAndItsChannel() {
    let source = ThreadSource(key: SourceKey(rawValue: "slack"), label: "Slack")
    let withSource = ThreadMessage(
        id: "queued-message-1", uuid: "u", text: "t", authorInitials: "MH", state: .pending, mode: .queue,
        sentAt: "", bodyHtml: "", via: source)
    #expect(QueuedChips.chips(from: [withSource]).first?.sourceLabel == "Slack")
    #expect(QueuedChips.chips(from: [message("queued-message-1")]).first?.attachmentCount == 0)
}

@Test func aChannelWithNoLabelShowsItsKeyAndOneWithNeitherShowsNothing() {
    func chip(_ source: ThreadSource) -> QueuedChip? {
        let value = ThreadMessage(
            id: "queued-message-1", uuid: "u", text: "t", authorInitials: "MH", state: .pending, mode: .queue,
            sentAt: "", bodyHtml: "", via: source)
        return QueuedChips.chips(from: [value]).first
    }
    #expect(chip(ThreadSource(key: SourceKey(rawValue: "email"), label: ""))?.sourceLabel == "email")
    #expect(chip(ThreadSource(key: SourceKey(rawValue: ""), label: " "))?.sourceLabel == nil)
}

@Test func upToThreeChipsShowAndMoreFoldBehindAnNMoreRow() {
    #expect(QueuedChips.visible(queued(1), isExpanded: false).hiddenCount == 0)
    #expect(QueuedChips.visible(queued(3), isExpanded: false).shown.count == 3)
    let five = QueuedChips.visible(queued(5), isExpanded: false)
    #expect(five.shown.map(\.uuid) == ["uuid-queued-message-1", "uuid-queued-message-2", "uuid-queued-message-3"])
    #expect(five.hiddenCount == 2)
    let all = QueuedChips.visible(queued(5), isExpanded: true)
    #expect(all.shown.count == 5 && all.hiddenCount == 0)
    #expect(QueuedChips.visible([], isExpanded: false).shown.isEmpty)
}

@Test func waitingMessagesCanBeChangedExceptInReadOnlyClosedAndLegacyThreads() {
    #expect(QueuedChips.canManage(composerState: .working))
    #expect(QueuedChips.canManage(composerState: .idle))
    #expect(QueuedChips.canManage(composerState: .stopped))
    #expect(!QueuedChips.canManage(composerState: .readonly))
    #expect(!QueuedChips.canManage(composerState: .disabledClosed))
    #expect(!QueuedChips.canManage(composerState: .legacy))
    #expect(!QueuedChips.canManage(composerState: nil))
}
