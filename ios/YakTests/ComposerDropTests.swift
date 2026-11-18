import Foundation
import Testing
import YakKit

@testable import Yak

@MainActor private final class Recorder {
    var added: [(String, Int)] = []
    var asked: [(Int, Int)] = []
    var statted: [Int] = []
}

@MainActor private func model(
    _ recorder: Recorder, accepts: Bool = true, attached: Int = 0, limits: (Int, Int)? = (3, 1000),
    timeLimit: Duration = .seconds(60)
) -> ComposerDropModel {
    ComposerDropModel(
        acceptsDrops: { accepts }, attached: { attached },
        limits: { limits.map { (maxFiles: $0.0, maxFileBytes: $0.1) } },
        add: { data, name, _ in recorder.added.append((name, data.count)) }, timeLimit: timeLimit)
}

private func statter(
    _ recorder: Recorder, _ sizes: [Int?], names: [String]? = nil, isImage: Bool = false
) -> (Int) async -> (item: DroppedItem, name: String) {
    { index in
        await MainActor.run { recorder.statted.append(index) }
        return (DroppedItem(byteCount: sizes[index], isImage: isImage), names?[index] ?? "file\(index).txt")
    }
}

private func loader(
    _ recorder: Recorder, name: String? = nil, returning size: @escaping @Sendable (Int, Int) -> Int = { _, _ in 10 }
) -> (Int, Int) async throws -> (Data, String, String) {
    { index, limit in
        await MainActor.run { recorder.asked.append((index, limit)) }
        return (Data(count: size(index, limit)), name ?? "file\(index).txt", "text/plain")
    }
}

@MainActor @Test func acceptedItemsGoThroughAddInOrder() async {
    let recorder = Recorder()
    await model(recorder).receive(count: 2, stat: statter(recorder, [10, 20]), load: loader(recorder))
    #expect(recorder.added.map(\.0) == ["file0.txt", "file1.txt"])
}

@MainActor @Test func filesPastTheFreePlacesAreNeverOpened() async {
    let recorder = Recorder()
    let drop = model(recorder, limits: (1, 100))
    await drop.receive(count: 3, stat: statter(recorder, [10, 20, 5000]), load: loader(recorder))
    #expect(recorder.statted == [0] && recorder.asked.map(\.0) == [0])
    #expect(drop.line?.contains("2 files were not attached") == true)
}

@MainActor @Test func aTooLargeFileIsNeverReadAndTheLineNamesIt() async {
    let recorder = Recorder()
    let drop = model(recorder, limits: (3, 100))
    await drop.receive(
        count: 3, stat: statter(recorder, [5000, nil, 10], names: ["big.bin", "x", "ok.txt"]),
        load: loader(recorder))
    let line = drop.line ?? ""
    #expect(line.contains("big.bin") && line.contains("could not be read"))
    #expect(recorder.asked.map(\.0) == [2] && recorder.added.count == 1)
    #expect(drop.refusalCount == 1)
}

@MainActor
@Test(arguments: [
    (ComposerState.readonly, false), (.disabledClosed, false), (.disabledComputer, false),
    (.disabledBlocked, false), (.idle, true),
])
func aComposerThatCannotSendTakesNoDrop(state: ComposerState, accepts: Bool) async {
    let bar = ComposerBarState.make(
        composer: state, canDrive: true, turn: nil, notice: nil, typedText: "", attachmentCount: 0)
    #expect(ComposerDropModel.accepts(bar) == accepts)
    let recorder = Recorder()
    let drop = model(recorder, accepts: ComposerDropModel.accepts(bar))
    await drop.receive(count: 1, stat: statter(recorder, [10]), load: loader(recorder))
    #expect(recorder.added.isEmpty == !accepts && (accepts || (recorder.asked.isEmpty && drop.line == nil)))
}

@MainActor @Test func readOnlyThreadsAreRefused() {
    let bar = ComposerBarState.make(
        composer: nil, canDrive: false, turn: nil, notice: nil, typedText: "", attachmentCount: 0)
    #expect(!ComposerDropModel.accepts(bar))
}

@MainActor @Test func aHundredAndOneItemsOpenAtMostTheRemainingCount() async {
    let recorder = Recorder()
    let drop = model(recorder, attached: 1, limits: (3, 1000))
    await drop.receive(
        count: 101, stat: statter(recorder, Array(repeating: 10, count: 101)), load: loader(recorder))
    #expect(recorder.statted.count == 2 && recorder.asked.count == 2)
    #expect(drop.line?.contains("99 files were not attached") == true)
}

@MainActor @Test func aFileThatGrowsPastTheLimitWhileReadingIsRefused() async {
    let recorder = Recorder()
    let drop = model(recorder, limits: (3, 1000))
    await drop.receive(
        count: 1, stat: statter(recorder, [500], names: ["grew.bin"]),
        load: loader(recorder, name: "grew.bin", returning: { _, limit in limit + 1 }))
    #expect(recorder.added.isEmpty && recorder.asked.first?.1 == 1000)
    #expect(drop.line?.contains("grew.bin") == true)
}

@MainActor @Test func aLongFileNameIsCutAtSixtyCharactersInTheLine() async {
    let recorder = Recorder()
    let drop = model(recorder, limits: (3, 100))
    let name = "dir/" + String(repeating: "x", count: 296)
    await drop.receive(count: 1, stat: statter(recorder, [5000], names: [name]), load: loader(recorder))
    let line = drop.line ?? ""
    #expect(!line.contains("/"))
    // The safe name "dir-xxx..." is cut to 59 characters and an ellipsis.
    #expect(line.contains("dir-" + String(repeating: "x", count: 55) + "\u{2026}"))
    #expect(!line.contains(String(repeating: "x", count: 56)))
}

@MainActor @Test func addReceivesTheSafeName() async {
    let recorder = Recorder()
    let hostile = "dir/..\u{7}/na\u{0}me.txt"
    await model(recorder).receive(
        count: 1, stat: statter(recorder, [10]), load: loader(recorder, name: hostile))
    let added = recorder.added.first?.0 ?? ""
    #expect(added == ComposerAttachments.safeFileName(hostile))
    #expect(!added.contains("/") && !added.unicodeScalars.contains { $0.properties.generalCategory == .control })
}

@MainActor @Test func noLimitsRefusesTheDrop() async {
    let recorder = Recorder()
    let drop = model(recorder, limits: nil)
    await drop.receive(count: 1, stat: statter(recorder, [10]), load: loader(recorder))
    #expect(recorder.added.isEmpty && drop.line == "Attachments are not available right now.")
}

@MainActor @Test func aFileThatFailsDoesNotStopTheRest() async {
    let recorder = Recorder()
    let drop = model(recorder, limits: (3, 1000))
    struct Failed: Error {}
    await drop.receive(
        count: 2, stat: statter(recorder, [10, 10]),
        load: { index, _ in
            if index == 0 { throw Failed() }
            return (Data(count: 5), "b.txt", "text/plain")
        })
    #expect(recorder.added.map(\.0) == ["b.txt"])
    #expect(drop.line?.contains("1 files could not be read") == true)
}

@MainActor @Test func theWholeDropIsBoundedInTime() async {
    let recorder = Recorder()
    let drop = model(recorder, limits: (5, 1000), timeLimit: .milliseconds(50))
    await drop.receive(
        count: 3,
        stat: { _ in
            try? await Task.sleep(for: .milliseconds(120))
            return (DroppedItem(byteCount: 10, isImage: false), "a.txt")
        },
        load: loader(recorder))
    #expect(recorder.added.count == 1)
    #expect(drop.line?.contains("2 files could not be read") == true)
}

@MainActor @Test func aProviderThatNeverAnswersTimesOut() async {
    let value = await TimeBound.run(seconds: 0.05, fallback: Optional<Int>.none) { _ in }
    #expect(value == nil)
    let answered = await TimeBound.run(seconds: 5, fallback: Optional<Int>.none) { finish in finish(7) }
    #expect(answered == 7)
    // A late answer after the timeout is ignored.
    let late = await TimeBound.run(seconds: 0.05, fallback: -1) { finish in
        DispatchQueue.global().asyncAfter(deadline: .now() + 0.3) { finish(1) }
    }
    #expect(late == -1)
}
