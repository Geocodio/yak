import Foundation
import Testing
import YakKit

@testable import Yak

@MainActor private final class OpenRecorder {
    var opened: [String] = []
    /// Resumes the open of the first request when set.
    var gate: CheckedContinuation<Void, Never>?
    var holdsFirst = false

    func open(_ path: String, _ edit: Bool) async {
        if holdsFirst, opened.isEmpty {
            opened.append("start \(path)")
            await withCheckedContinuation { gate = $0 }
        }
        opened.append("\(path) edit:\(edit)")
    }
}

@MainActor @Suite struct FileOpenRequestTests {
    @Test func eachTapIsANewRequest() {
        #expect(FileOpenRequest(path: "a", edit: true) != FileOpenRequest(path: "a", edit: true))
    }

    @Test func aRequestOpensForEditingAndClears() async {
        let recorder = OpenRecorder()
        let handler = FileOpenHandler(open: recorder.open)
        var current: FileOpenRequest? = FileOpenRequest(path: ".yak/preview.yml", edit: true)
        let request = current
        await handler.take(request, current: { current }, clear: { current = nil })
        #expect(recorder.opened == [".yak/preview.yml edit:true"])
        #expect(current == nil)
    }

    @Test func theSameRequestSeenTwiceOpensOnce() async {
        let recorder = OpenRecorder()
        let handler = FileOpenHandler(open: recorder.open)
        var current: FileOpenRequest? = FileOpenRequest(path: "a", edit: false)
        let request = current
        await handler.take(request, current: { current }, clear: { current = nil })
        await handler.take(request, current: { current }, clear: { current = nil })
        #expect(recorder.opened.count == 1)
    }

    @Test func aNoRequestOpensNothing() async {
        let recorder = OpenRecorder()
        let handler = FileOpenHandler(open: recorder.open)
        await handler.take(nil, current: { nil }, clear: {})
        #expect(recorder.opened.isEmpty)
    }

    @Test func aNewerRequestOpensLastAndIsNotClearedByTheOlderOne() async {
        let recorder = OpenRecorder()
        recorder.holdsFirst = true
        let handler = FileOpenHandler(open: recorder.open)
        var current: FileOpenRequest? = FileOpenRequest(path: "first", edit: false)
        let first = current
        let firstTake = Task { await handler.take(first, current: { current }, clear: { current = nil }) }
        for _ in 0..<100 where recorder.gate == nil { await Task.yield() }
        current = FileOpenRequest(path: "second", edit: true)
        let second = current
        let secondTake = Task { await handler.take(second, current: { current }, clear: { current = nil }) }
        for _ in 0..<20 { await Task.yield() }
        #expect(recorder.opened == ["start first"], "the second open waits for the first")
        recorder.gate?.resume()
        await firstTake.value
        await secondTake.value
        #expect(recorder.opened == ["start first", "first edit:false", "second edit:true"])
        #expect(current == nil)
    }
}
