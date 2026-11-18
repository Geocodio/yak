import Foundation
import Testing
import YakKit

@testable import Yak

@MainActor private final class Recorder {
    var added: [(String, Int)] = []
    var asked: [(Int, Int)] = []
}

@MainActor private func model(
    _ recorder: Recorder, accepts: Bool = true, attached: Int = 0, limits: (Int, Int)? = (3, 1000)
) -> ComposerDropModel {
    ComposerDropModel(
        acceptsDrops: { accepts }, attached: { attached },
        limits: { limits.map { (maxFiles: $0.0, maxFileBytes: $0.1) } },
        add: { data, name, _ in recorder.added.append((name, data.count)) })
}

private func items(_ sizes: [Int?], isImage: Bool = false) -> [DroppedItem] {
    sizes.map { DroppedItem(byteCount: $0, isImage: isImage) }
}

private func loader(
    _ recorder: Recorder, returning size: @escaping @Sendable (Int, Int) -> Int = { _, _ in 10 }
) -> @Sendable (Int, Int) async throws -> (Data, String, String) {
    { index, limit in
        await MainActor.run { recorder.asked.append((index, limit)) }
        return (Data(count: size(index, limit)), "file\(index).txt", "text/plain")
    }
}

@MainActor @Test func acceptedItemsGoThroughAddInOrder() async {
    let recorder = Recorder()
    await model(recorder).receive(items([10, 20]), names: ["a", "b"], load: loader(recorder))
    #expect(recorder.added.map(\.0) == ["file0.txt", "file1.txt"])
}

@MainActor @Test func refusedItemsAreNeverRead() async {
    let recorder = Recorder()
    let drop = model(recorder, limits: (1, 100))
    await drop.receive(items([10, 20, 5000]), names: ["a", "b", "c"], load: loader(recorder))
    #expect(recorder.asked.map(\.0) == [0])
}

@MainActor @Test func theLineCountsEachRefusal() async {
    let recorder = Recorder()
    let drop = model(recorder, limits: (1, 100))
    await drop.receive(items([10, 20, 5000, nil]), names: ["a", "b", "big.bin", "x"], load: loader(recorder))
    let line = drop.line ?? ""
    #expect(
        line.contains("1 files were not attached") && line.contains("big.bin") && line.contains("could not be read"))
    #expect(drop.refusalCount == 1)
}

@MainActor @Test(arguments: [false]) func aDropOnAComposerThatCannotSendIsIgnored(accepts: Bool) async {
    let recorder = Recorder()
    let drop = model(recorder, accepts: accepts)
    await drop.receive(items([10]), names: ["a"], load: loader(recorder))
    #expect(recorder.added.isEmpty && recorder.asked.isEmpty && drop.line == nil)
}

@MainActor @Test func aHundredAndOneItemsReadAtMostTheRemainingCount() async {
    let recorder = Recorder()
    let drop = model(recorder, attached: 1, limits: (3, 1000))
    await drop.receive(
        items(Array(repeating: 10, count: 101)), names: Array(repeating: "a", count: 101), load: loader(recorder))
    #expect(recorder.asked.count == 2)
    #expect(drop.line?.contains("99 files were not attached") == true)
}

@MainActor @Test func aFileThatGrowsPastTheLimitWhileReadingIsRefused() async {
    let recorder = Recorder()
    let drop = model(recorder, limits: (3, 1000))
    await drop.receive(items([500]), names: ["grew.bin"], load: loader(recorder, returning: { _, limit in limit + 1 }))
    #expect(recorder.added.isEmpty && recorder.asked.first?.1 == 1000)
    #expect(drop.line?.contains("grew.bin") == true)
}

@MainActor @Test func aLongFileNameIsCutInTheLine() async {
    let recorder = Recorder()
    let drop = model(recorder, limits: (3, 100))
    let name = "dir/" + String(repeating: "x", count: 296)
    await drop.receive(items([5000]), names: [name], load: loader(recorder))
    let line = drop.line ?? ""
    #expect(!line.contains("/") && line.count < 200)
}

@MainActor @Test func noLimitsRefusesTheDrop() async {
    let recorder = Recorder()
    let drop = model(recorder, limits: nil)
    await drop.receive(items([10]), names: ["a"], load: loader(recorder))
    #expect(recorder.added.isEmpty && drop.line == "Attachments are not available right now.")
}
