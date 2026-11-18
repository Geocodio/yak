import Foundation
import Testing
import YakKit

@testable import Yak

@MainActor @Test func newWindowAsksForABlankWindowEachTime() {
    var requests: [WindowRequest] = []
    for _ in 0..<2 { WindowRoutes.newWindow(open: { requests.append($0) }, supportsMultipleWindows: true) }
    #expect(requests.count == 2 && requests[0] != requests[1])
    for request in requests {
        if case .blank = request { continue }
        Issue.record("not a blank window")
    }
}

@MainActor @Test func nothingOpensWithoutMultipleWindows() {
    var requests: [WindowRequest] = []
    WindowRoutes.newWindow(open: { requests.append($0) }, supportsMultipleWindows: false)
    WindowRoutes.open(
        thread: "d0e515ef", instance: UUID(), open: { requests.append($0) }, supportsMultipleWindows: false)
    #expect(requests.isEmpty)
}

@MainActor @Test func openInNewWindowNamesTheActiveInstanceAndTheThread() {
    var requests: [WindowRequest] = []
    let instance = UUID()
    WindowRoutes.open(
        thread: "d0e515ef", instance: instance, open: { requests.append($0) }, supportsMultipleWindows: true)
    #expect(requests == [.thread(instance: instance, id: "d0e515ef")])
}
