import Testing

@testable import YakKit

private let megabyte = 1_048_576
private func file(_ bytes: Int?, image: Bool = false) -> DroppedItem { DroppedItem(byteCount: bytes, isImage: image) }

@Suite struct DropIntakeTests {
    @Test func acceptsUpToTheRemainingCount() {
        let decisions = DropIntake.decide(
            [file(1), file(1), file(1)], attached: 2, maxFiles: 4, maxFileBytes: 10 * megabyte)
        #expect(decisions == [.accept, .accept, .tooMany])
    }

    @Test func checksTheSizeBeforeCounting() {
        let decisions = DropIntake.decide(
            [file(20 * megabyte), file(1)], attached: 0, maxFiles: 1, maxFileBytes: 10 * megabyte)
        #expect(decisions == [.tooLarge, .accept])
    }

    @Test func largeImagesMayBeDownscaled() {
        let decisions = DropIntake.decide(
            [file(20 * megabyte, image: true), file(60_000_000, image: true)], attached: 0, maxFiles: 5,
            maxFileBytes: 10 * megabyte)
        #expect(decisions == [.accept, .tooLarge])
    }

    @Test func unknownSizesAreUnreadable() {
        let decisions = DropIntake.decide([file(nil), file(-1)], attached: 0, maxFiles: 5, maxFileBytes: 100)
        #expect(decisions == [.unreadable, .unreadable])
    }

    @Test func hostileDrops() {
        let many = Array(repeating: file(1), count: 10_000)
        let decisions = DropIntake.decide(many, attached: 0, maxFiles: 5, maxFileBytes: 100)
        #expect(decisions.count == 100)
        let summary = DropIntake.summary(decisions, dropped: 10_000)
        #expect(summary == DropSummary(accepted: 5, tooMany: 9_995, tooLarge: 0, unreadable: 0))
        #expect(
            DropIntake.decide([file(Int.max)], attached: 0, maxFiles: 5, maxFileBytes: 100) == [.tooLarge])
        #expect(DropIntake.decide([file(1)], attached: 9, maxFiles: 5, maxFileBytes: 100) == [.tooMany])
        #expect(DropIntake.decide([file(1)], attached: -1, maxFiles: Int.max, maxFileBytes: 100) == [.accept])
        #expect(DropIntake.decide([file(1)], attached: Int.min, maxFiles: Int.max, maxFileBytes: 100) == [.accept])
        #expect(DropIntake.decide([file(1)], attached: 0, maxFiles: -1, maxFileBytes: 100) == [.tooMany])
        #expect(DropIntake.decide([file(1)], attached: 0, maxFiles: 5, maxFileBytes: -1) == [.tooLarge])
    }

    @Test func readLimitsFollowTheKind() {
        #expect(DropIntake.readLimit(isImage: true, maxFileBytes: 10 * megabyte) == 50_000_000)
        #expect(DropIntake.readLimit(isImage: false, maxFileBytes: 10 * megabyte) == 10_485_760)
        #expect(DropIntake.readLimit(isImage: false, maxFileBytes: -5) == 0)
    }

    @Test func lineNamesAreCut() {
        let name = DropIntake.lineName(String(repeating: "a", count: 100) + ".png")
        #expect(name.count == 60)
        #expect(name.hasSuffix("…"))
        #expect(DropIntake.lineName("notes.txt") == "notes.txt")
    }
}
