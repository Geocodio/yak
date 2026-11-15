import Testing

@testable import YakKit

@Suite struct PerformanceMarksTests {
    @Test func namesAreUniqueAndKebabCase() {
        let names = PerformanceMark.allCases.map(\.rawValue)
        #expect(Set(names).count == names.count)
        #expect(names.count == 5)
        for name in names {
            #expect(!name.isEmpty)
            #expect(name.allSatisfy { $0.isLowercase || $0 == "-" })
            #expect(!name.hasPrefix("-") && !name.hasSuffix("-") && !name.contains("--"))
        }
    }

    @Test func onlyAllPanesOpenIsAnEvent() {
        for mark in PerformanceMark.allCases {
            #expect(mark.isInterval == (mark != .allPanesOpen))
        }
    }

    @Test func endWithoutBeginIsIgnored() {
        var open = OpenPerformanceIntervals<Int>()
        for mark in PerformanceMark.allCases {
            #expect(open.end(mark) == nil)
        }
    }

    @Test func eventsAreNeverOpened() {
        var open = OpenPerformanceIntervals<Int>()
        #expect(open.begin(.allPanesOpen, token: 1).shouldBegin == false)
        #expect(open.end(.allPanesOpen) == nil)
    }

    @Test func coldStartEndsOnce() {
        var open = OpenPerformanceIntervals<Int>()
        #expect(open.begin(.coldStartToThread, token: 1).shouldBegin)
        #expect(open.end(.coldStartToThread) == 1)
        #expect(open.begin(.coldStartToThread, token: 2).shouldBegin == false)
        #expect(open.end(.coldStartToThread) == nil)
    }

    @Test func paneSwitchRestarts() {
        var open = OpenPerformanceIntervals<Int>()
        #expect(open.begin(.paneSwitch, token: 1).endPrevious == nil)
        let second = open.begin(.paneSwitch, token: 2)
        #expect(second.endPrevious == 1)
        #expect(second.shouldBegin)
        #expect(open.end(.paneSwitch) == 2)
        #expect(open.end(.paneSwitch) == nil)
    }

    @Test func pushTapKeepsTheFirst() {
        var open = OpenPerformanceIntervals<Int>()
        #expect(open.begin(.pushTapToQuestion, token: 1).shouldBegin)
        let second = open.begin(.pushTapToQuestion, token: 2)
        #expect(second.shouldBegin == false)
        #expect(second.endPrevious == nil)
        #expect(open.end(.pushTapToQuestion) == 1)
        // A push tap can happen again after it ended.
        #expect(open.begin(.pushTapToQuestion, token: 3).shouldBegin)
    }

    @Test func hostileBeginsDoNotGrow() {
        var open = OpenPerformanceIntervals<Int>()
        let intervals = PerformanceMark.allCases.filter(\.isInterval)
        for mark in intervals {
            for token in 0..<10_000 {
                _ = open.begin(mark, token: token)
            }
        }
        for mark in intervals {
            let expected = mark.restartsOnBegin ? 9_999 : 0
            #expect(open.end(mark) == expected)
            #expect(open.end(mark) == nil)
        }
        for token in 0..<10_000 {
            let result = open.begin(.allPanesOpen, token: token)
            #expect(!result.shouldBegin)
            #expect(result.endPrevious == nil)
        }
    }
}
