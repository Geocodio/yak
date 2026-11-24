import Foundation
import Testing

@testable import YakKit

private let threadID = "d0e515ef"
private let start = Date(timeIntervalSince1970: 1_800_000_000)

private func item(
    _ id: String = "6F1C2F9E-3B1D-4C55-9A53-0D4B5C8F7A10", target: OutboxTarget = .thread(threadID),
    state: OutboxState = .queued, text: String = "Hello", attachments: Int = 0, offset: TimeInterval = 0
) -> OutboxItem {
    OutboxItem(
        id: UUID(uuidString: id)!, target: target, text: text, model: nil, delivery: .steer,
        attachments: (0..<attachments).map {
            OutboxAttachment(reference: "r\($0)", fileName: "f\($0)", contentType: "image/png", path: "p\($0)")
        }, state: state, createdAt: start.addingTimeInterval(offset))
}

private func rows(_ items: [OutboxItem]) throws -> [EmbedOutboxItem] {
    guard case .outbox(let id, let rows) = EmbedOutboxProjection.message(threadID: threadID, items: items) else {
        throw EmbedProjectionFailure()
    }
    #expect(id == threadID)
    return rows
}

@Test(arguments: [
    (OutboxState.queued, EmbedOutboxItem.Status.queued), (.sending, .sending), (.slow, .slow), (.waiting, .waiting),
])
func eachStateMapsToItsStatusWithoutAReason(state: OutboxState, status: EmbedOutboxItem.Status) throws {
    let row = try #require(try rows([item(state: state)]).first)
    #expect(row.status == status && row.reason == nil)
}

@Test func aRefusedItemCarriesTheServersSentence() throws {
    let refusal = OutboxRefusal(code: "forbidden", message: "You cannot send messages to this thread.")
    let row = try #require(try rows([item(state: .refused(refusal), attachments: 1)]).first)
    #expect(row.status == .refused && row.reason == "You cannot send messages to this thread.")
    #expect(row.attachmentCount == 1)
}

@Test func theKeyIsTheSendKeyInLowercase() throws {
    let row = try #require(try rows([item()]).first)
    #expect(row.key == "6f1c2f9e-3b1d-4c55-9a53-0d4b5c8f7a10")
}

@Test func theRowKeepsTheTextAndTheCreationTime() throws {
    let row = try #require(try rows([item(text: "Also cover the export job", offset: 60)]).first)
    #expect(row.text == "Also cover the export job" && row.createdAt == start.addingTimeInterval(60))
}

@Test func onlyTheThreadsItemsAreProjectedOldestFirst() throws {
    let other = item("00000000-0000-0000-0000-000000000001", target: .thread("a1b2c3d4"))
    let newThread = item(
        "00000000-0000-0000-0000-000000000002",
        target: .newThread(NewThreadDraft(repo: "r", mode: "code")))
    let later = item("00000000-0000-0000-0000-000000000003", text: "second", offset: 10)
    let earlier = item("00000000-0000-0000-0000-000000000004", text: "first", offset: 1)
    #expect(try rows([other, later, newThread, earlier]).map(\.text) == ["first", "second"])
}

@Test func anEmptyOutboxProjectsAnEmptyList() throws {
    #expect(try rows([]).isEmpty)
}

@Test func theProjectionEncodesLikeTheFixtureShape() throws {
    let message = EmbedOutboxProjection.message(threadID: threadID, items: [item(state: .waiting)])
    let dictionary = try #require(message.dictionary())
    let list = try #require(dictionary["items"] as? [[String: Any]])
    #expect(Set(list[0].keys) == ["key", "text", "status", "reason", "createdAt", "attachmentCount"])
    #expect(list[0]["status"] as? String == "waiting" && list[0]["reason"] is NSNull)
}

private struct EmbedProjectionFailure: Error {}

@Test func aFailedBackgroundUploadIsShownAsNotSentWithRetry() throws {
    let sent = item(state: .sending, attachments: 1)
    guard
        case .outbox(_, let failedRows) = EmbedOutboxProjection.message(
            threadID: threadID, items: [sent], failedUploads: [sent.id])
    else { throw EmbedProjectionFailure() }
    #expect(failedRows.map(\.status) == [.refused])
    #expect(failedRows.first?.reason == EmbedOutboxProjection.failedUploadReason)
    #expect(try rows([sent]).map(\.status) == [.sending])
}
