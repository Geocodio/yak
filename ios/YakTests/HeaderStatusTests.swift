import Testing
import YakKit

@testable import Yak

/// The header's dot and its connection line.
@Suite struct HeaderStatusTests {
    @Test func theDotFollowsTheRunningStateThenTheTone() {
        #expect(HeaderMark.make(dot: .running, tone: .bad) == .running)
        #expect(HeaderMark.make(dot: .neutral, tone: .ok) == .ok)
        #expect(HeaderMark.make(dot: .neutral, tone: .bad) == .bad)
        #expect(HeaderMark.make(dot: .neutral, tone: .info) == .info)
        #expect(HeaderMark.make(dot: .neutral, tone: .warn) == .warn)
        #expect(HeaderMark.make(dot: .attention, tone: nil) == .warn)
        #expect(HeaderMark.make(dot: .attention, tone: .bad) == .bad)
        #expect(HeaderMark.make(dot: .neutral, tone: nil) == .ring)
        #expect(HeaderMark.make(dot: .neutral, tone: .neutral) == .ring)
    }

    @Test func theHeaderHidesTheLineOnlyWhileTheChatPaneShowsItsOwn() {
        let line = ConnectionLine.offlineSavedCopy
        // Chat visible, Chat draws a line: hidden.
        #expect(
            HeaderConnection.line(line, chatIsVisible: true, chatShowsOwnLine: true) == nil)
        // Another pane shows: the header says it.
        #expect(
            HeaderConnection.line(line, chatIsVisible: false, chatShowsOwnLine: true) == line)
        // Chat shows but has no line of its own: the header says it.
        #expect(
            HeaderConnection.line(line, chatIsVisible: true, chatShowsOwnLine: false) == line)
        #expect(
            HeaderConnection.line(nil, chatIsVisible: false, chatShowsOwnLine: false) == nil)
    }
}
