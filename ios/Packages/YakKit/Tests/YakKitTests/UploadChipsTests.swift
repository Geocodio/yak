import Foundation
import Testing

@testable import YakKit

private func item(_ id: String, thread: String = "d0e515ef", files: Int = 1, text: String = "Look") -> OutboxItem {
    OutboxItem(
        id: UUID(uuidString: id)!, target: .thread(thread), text: text, model: nil, delivery: .steer,
        attachments: (0..<files).map {
            OutboxAttachment(reference: "r\($0)", fileName: "f\($0)", contentType: "image/png", path: "p\($0)")
        }, state: .sending, createdAt: Date(timeIntervalSince1970: 0))
}

private let first = "6F1C2F9E-3B1D-4C55-9A53-0D4B5C8F7A10"
private let second = "6F1C2F9E-3B1D-4C55-9A53-0D4B5C8F7A11"

@Test func anUploadShowsItsPercentAndAFailureShowsFailed() {
    let items = [item(first), item(second)]
    let summary = UploadSummary(progress: [UUID(uuidString: first)!: 0.426], failed: [UUID(uuidString: second)!])
    let chips = UploadChips.make(items: items, threadID: "d0e515ef", summary: summary, isOffline: false)
    #expect(chips.map(\.state) == [.uploading(percent: 42), .failed])
}

@Test func offlineAnUploadWaitsForTheNetwork() {
    let summary = UploadSummary(progress: [UUID(uuidString: first)!: 0.5])
    let chips = UploadChips.make(items: [item(first)], threadID: "d0e515ef", summary: summary, isOffline: true)
    #expect(chips.map(\.state) == [.waitingForNetwork])
}

@Test func otherThreadsAndMessagesWithoutFilesOrUploadsHaveNoChip() {
    let summary = UploadSummary(progress: [UUID(uuidString: first)!: 0.5], failed: [UUID(uuidString: second)!])
    let items = [item(first, thread: "a1b2c3d4"), item(second, files: 0), item("6F1C2F9E-3B1D-4C55-9A53-0D4B5C8F7A12")]
    #expect(UploadChips.make(items: items, threadID: "d0e515ef", summary: summary, isOffline: false).isEmpty)
}
