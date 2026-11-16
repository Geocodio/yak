import Foundation
import SwiftUI
import Testing
import UIKit

@testable import Yak

/// The first screen of a huge message costs the main actor about what the first screen of a short one costs,
/// whatever the document is made of and however the chat holds the message, and the memory stays bounded at the
/// first screen and after scrolling to the end.
///
/// Memory is only comparable when one input runs per process, because the allocator keeps what earlier inputs
/// freed. `scripts/markdown-isolated.sh` runs each input in its own test process with `YAK_LAYOUT_INPUT` set;
/// a plain run checks the time of every input and the memory of none.
@MainActor @Suite(.serialized) struct MarkdownLayoutTests {
    static let timeLimit = 0.5
    static let scrollStepLimit = 0.25
    static let memoryLimitMegabytes = 400.0

    /// What holds the message: the chat's scroll view with the message alone, its rows in a lazy stack of
    /// messages, the whole view in a lazy stack of messages, or in an eager stack of messages.
    enum Container: CaseIterable {
        case scrollView, flattenedIntoLazyMessages, lazyMessages, eagerMessages
    }

    /// Set to the name of one `Input` to measure memory; see the suite comment.
    nonisolated static let isolatedInput = ProcessInfo.processInfo.environment["YAK_LAYOUT_INPUT"].flatMap {
        $0.isEmpty ? nil : $0
    }

    enum Input: String, CaseIterable {
        case paragraphs, listItems, tables, fallbackParagraphs
        case oneParagraph, oneCodeBlock, manyLinks, ordinary
        case manyLinesParagraph, manyLinesItem, plainSubitems, quoteParagraphs, itemParagraphs, wideTable, quotedCode,
            listedCode, shortHeadings
        case setextHeading, htmlBlock, bigItems, wrappedQuote, shortQuote, overCapTable, longCodeLine
        case autolinks, spacedLinks, images, codeSpans, strongRuns, wideItems, denseTable
        case formFeeds, verticalTabs, nextLines, lineSeparators, paragraphSeparators, listedFormFeeds, quotedFormFeeds
        case entityLineSeparators
        case quoteWords, quoteHttp, itemHttp, fallbackQuoteHttp, quotedItemHttp, longTableRows
        case quoteManyParagraphs, itemManyParagraphs, quotedHttpParagraphs, nestedItems
        case emojiCode, emojiQuotedCode, emojiHeadings, emojiDense, emojiQuarter, emojiSpaced, emojiFamilies,
            emojiCells,
            wideCells
        case zwjChainCode, zwjChainParagraph, zwjChainLines, zalgoParagraph, zalgoCode, jamoParagraph, jamoCode
        case emojiPagedFirst, emojiPagedMiddle, emojiPagedLast
        case emojiKeycaps, emojiHearts, emojiCopyright, emojiKeycapCode, emojiCopyrightCode, emojiKeycapHeadings
        case answerCode, answerParagraphs, answerList, answerTable, answerMixed, answerEmoji
        case twoEmojiLogs, threeEmojiLogs, wideCodeLog
        case heartsParagraph, smileysSpaced, heartsHeading, heartsListItems, heartsHeadings

        /// Test logs of 600 lines, 100 columns and an emoji on every line, each in its own fence.
        nonisolated static func emojiLogs(_ count: Int) -> String {
            let lines = (0..<600).map { index -> String in
                let line = "\(index % 7 == 6 ? "\u{274C}" : "\u{2705}") test_case_\(index) passed in 0.0\(index % 10)s "
                return line + String(repeating: ".", count: max(0, 100 - line.count))
            }
            return String(repeating: "```\n" + lines.joined(separator: "\n") + "\n```\n\n", count: count)
        }

        nonisolated var text: String {
            switch self {
            case .zwjChainCode: "```\n" + String(repeating: "\u{1F44D}\u{200D}", count: 28_000) + "\n```"
            case .zwjChainParagraph: String(repeating: "\u{1F44D}\u{200D}", count: 28_000)
            case .zwjChainLines:
                String(repeating: String(repeating: "\u{1F44D}\u{200D}", count: 3_000) + "\n\n", count: 9)
            case .zalgoParagraph: "a" + String(repeating: "\u{0301}", count: 99_000)
            case .zalgoCode: "```\na" + String(repeating: "\u{0301}", count: 99_000) + "\n```"
            case .jamoParagraph: String(repeating: "\u{1100}", count: 66_000)
            case .jamoCode: "```\n" + String(repeating: "\u{1100}", count: 66_000) + "\n```"
            case .emojiPagedFirst, .emojiPagedMiddle, .emojiPagedLast: String(repeating: "\u{1F600}", count: 49_000)
            case .paragraphs: String(repeating: "a\n\n", count: 66_333)
            case .listItems: String(repeating: "- a\n", count: 49_750)
            case .tables:
                (0..<20).map { _ in "a|b\n-|-\n" + String(repeating: "1|2\n", count: 2_400) }.joined(separator: "\n")
            case .fallbackParagraphs:
                String(repeating: " ", count: 401) + "x\n\n" + String(repeating: "a\n\n", count: 66_000)
            case .oneParagraph: String(repeating: "word ", count: 39_800)
            case .oneCodeBlock: "```\n" + String(repeating: "let a = 1\n", count: 19_900) + "```"
            case .manyLinks: String(repeating: "[a](b)", count: 33_000)
            case .manyLinesParagraph: String(repeating: "a\n", count: 99_000)
            case .manyLinesItem: "- a\n" + String(repeating: "  a\n", count: 99_000)
            case .plainSubitems:
                String(repeating: " ", count: 401) + "x\n\n- a\n" + String(repeating: "  - b\n", count: 33_000)
            case .quoteParagraphs: String(repeating: "> a\n>\n", count: 2_995)
            case .itemParagraphs: "- a\n\n" + String(repeating: "  b\n\n", count: 2_995)
            case .wideTable:
                "|" + String(repeating: "h|", count: 1_499) + "\n|" + String(repeating: "-|", count: 1_499) + "\n|"
                    + String(repeating: "c|", count: 1_499) + "\n"
            case .quotedCode: "> ```\n" + String(repeating: "> let a = 1\n", count: 16_000) + "> ```"
            case .listedCode: "- a\n\n  ```\n" + String(repeating: "  let a = 1\n", count: 16_000) + "  ```"
            case .shortHeadings: String(repeating: "# a\n", count: 49_000)
            case .setextHeading: String(repeating: "a\n", count: 99_000) + "==="
            case .htmlBlock: "<div>\n" + String(repeating: "a\n", count: 99_000)
            case .bigItems: String(repeating: "- a\n" + String(repeating: "a\n", count: 298) + "\n", count: 330)
            case .wrappedQuote: String(repeating: "> " + String(repeating: "word ", count: 80) + "\n>\n", count: 480)
            case .shortQuote: String(repeating: String(repeating: "> a\n", count: 9) + ">\n", count: 495)
            case .overCapTable: "a|b\n-|-\n" + String(repeating: "1|2\n", count: 49_000)
            case .longCodeLine: "```\n" + String(repeating: "x", count: 199_000) + "\n```"
            case .autolinks: String(repeating: "<https://a.b> ", count: 13_000)
            case .spacedLinks: String(repeating: "[a](https://a.b) ", count: 11_000)
            case .images: String(repeating: "![a](b) ", count: 24_000)
            case .codeSpans: String(repeating: "`a` ", count: 48_000)
            case .strongRuns: String(repeating: "**a** ", count: 32_000)
            case .wideItems: String(repeating: "- " + String(repeating: "word ", count: 980) + "\n", count: 40)
            case .denseTable:
                "|" + String(repeating: "h|", count: 64) + "\n|" + String(repeating: "-|", count: 64) + "\n"
                    + String(repeating: "|" + String(repeating: "w w w w w|", count: 64) + "\n", count: 45)
            case .formFeeds: String(repeating: "a\u{0C}", count: 99_000)
            case .verticalTabs: String(repeating: "a\u{0B}", count: 99_000)
            case .nextLines: String(repeating: "a\u{85}", count: 66_000)
            case .lineSeparators: String(repeating: "a\u{2028}", count: 49_000)
            case .paragraphSeparators: String(repeating: "a\u{2029}", count: 49_000)
            case .listedFormFeeds: "- " + String(repeating: "a\u{0C}", count: 99_000)
            case .quotedFormFeeds: "> " + String(repeating: "a\u{0C}", count: 45_000)
            case .entityLineSeparators: String(repeating: "a&#8232;", count: 24_000)
            case .quoteWords: "> " + String(repeating: "word ", count: 19_799)
            case .quoteHttp: "> " + String(repeating: "http ", count: 19_799)
            case .itemHttp: "- a\n\n  " + String(repeating: "http ", count: 19_790)
            case .fallbackQuoteHttp:
                String(repeating: " ", count: 401) + "x\n> " + String(repeating: "http ", count: 19_700)
            case .quotedItemHttp: "> - " + String(repeating: "http ", count: 17_000)
            case .longTableRows:
                "a|b\n-|-\n"
                    + String(
                        repeating: String(repeating: "word ", count: 999) + "|" + String(repeating: "word ", count: 999)
                            + "\n", count: 19)
            case .quoteManyParagraphs: String(repeating: "> a\n>\n", count: 33_000)
            case .itemManyParagraphs: "- a\n\n" + String(repeating: "  b\n\n", count: 20_000)
            case .quotedHttpParagraphs:
                String(repeating: "> " + String(repeating: "http ", count: 400) + "\n>\n", count: 95)
            case .nestedItems:
                String(repeating: "- a\n", count: 3)
                    + String(repeating: "  - " + String(repeating: "word ", count: 30) + "\n", count: 800)
            case .emojiCode: "```\n" + String(repeating: "\u{1F600}", count: 49_000) + "\n```"
            case .emojiQuotedCode: "> ```\n> " + String(repeating: "\u{1F600}", count: 48_000) + "\n> ```"
            case .emojiHeadings:
                String(repeating: "# " + String(repeating: "\u{1F600}", count: 1_999) + "\n", count: 24)
            case .emojiDense: String(repeating: "\u{1F600}", count: 49_000)
            case .emojiQuarter: String(repeating: "\u{1F600}", count: 12_000)
            case .emojiSpaced: String(repeating: "\u{1F600} ", count: 24_000)
            case .emojiFamilies:
                String(repeating: "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466} ", count: 7_600)
            case .emojiCells:
                "a|b\n-|-\n"
                    + String(
                        repeating: String(repeating: "\u{1F600}", count: 200) + "|"
                            + String(repeating: "\u{1F600}", count: 200) + "\n", count: 120)
            case .wideCells:
                "a|b\n-|-\n"
                    + String(
                        repeating: String(repeating: "w", count: 240) + "|" + String(repeating: "w", count: 240) + "\n",
                        count: 299)
            case .emojiKeycaps: String(repeating: "1\u{FE0F}\u{20E3}", count: 28_000)
            case .emojiHearts: String(repeating: "\u{2764}\u{FE0F}", count: 33_000)
            case .emojiCopyright: String(repeating: "\u{00A9}\u{FE0F}", count: 39_900)
            case .emojiKeycapCode: "```\n" + String(repeating: "1\u{FE0F}\u{20E3}", count: 28_000) + "\n```"
            case .emojiCopyrightCode: "```\n" + String(repeating: "\u{00A9}\u{FE0F}", count: 39_900) + "\n```"
            case .emojiKeycapHeadings:
                String(repeating: "# " + String(repeating: "1\u{FE0F}\u{20E3}", count: 240) + "\n", count: 116)
            case .answerCode: MarkdownSamples.codeBlock
            case .answerParagraphs: MarkdownSamples.paragraphs
            case .answerList: MarkdownSamples.list
            case .answerTable: MarkdownSamples.table
            case .answerMixed: MarkdownSamples.mixed
            case .answerEmoji: MarkdownSamples.emojiText
            case .wideCodeLog:
                "```\n"
                    + (0..<600).map { "\u{2705} " + String(repeating: "\u{6F22}\u{5B57}", count: 50) + "\($0)" }
                    .joined(separator: "\n") + "\n```"
            case .twoEmojiLogs: Self.emojiLogs(2)
            case .threeEmojiLogs: Self.emojiLogs(3)
            case .heartsParagraph: String(repeating: "\u{2764}", count: 66_000)
            case .smileysSpaced: String(repeating: "\u{263A} ", count: 49_000)
            case .heartsHeading: "# " + String(repeating: "\u{2764}", count: 66_000)
            case .heartsListItems:
                String(repeating: "- " + String(repeating: "\u{2764}", count: 300) + "\n", count: 220)
            case .heartsHeadings: String(repeating: "# " + String(repeating: "\u{2764}", count: 120) + "\n", count: 540)
            case .ordinary: "# Title\n\nSome **bold** text.\n\n- one\n- two\n\n| a | b |\n|---|---|\n| 1 | 2 |\n"
            }
        }

        /// The page of the document the view starts on, as if the reader had opened that many pages.
        nonisolated var initialPage: Int {
            switch self {
            case .emojiPagedMiddle: 10
            case .emojiPagedLast: 20
            default: 0
            }
        }
    }

    static func footprintBytes() -> Int {
        var info = task_vm_info_data_t()
        var count = mach_msg_type_number_t(MemoryLayout<task_vm_info_data_t>.size / MemoryLayout<integer_t>.size)
        let result = withUnsafeMutablePointer(to: &info) {
            $0.withMemoryRebound(to: integer_t.self, capacity: Int(count)) {
                task_info(mach_task_self_, task_flavor_t(TASK_VM_INFO), $0, &count)
            }
        }
        return result == KERN_SUCCESS ? Int(info.phys_footprint) : 0
    }

    /// Hosts the document at width 390 in a window and lays out one frame; returns seconds on the main actor.
    func firstScreen(_ document: [MarkdownRow], initialPage: Int = 0, in container: Container) -> (
        host: UIHostingController<AnyView>, window: UIWindow, seconds: Double
    ) {
        let root: AnyView
        switch container {
        case .scrollView:
            root = AnyView(ScrollView { MarkdownRowsView(rows: document, initialPage: initialPage).padding(16) })
        case .flattenedIntoLazyMessages:
            root = AnyView(
                ScrollView {
                    LazyVStack(alignment: .leading) {
                        Text("Earlier message")
                        MarkdownRowList(rows: document, initialPage: initialPage)
                        Text("Later message")
                    }.padding(16)
                })
        case .lazyMessages:
            root = AnyView(
                ScrollView {
                    LazyVStack {
                        Text("Earlier message")
                        MarkdownRowsView(rows: document, initialPage: initialPage)
                        Text("Later message")
                    }.padding(16)
                })
        case .eagerMessages:
            root = AnyView(
                ScrollView {
                    VStack {
                        Text("Earlier message")
                        MarkdownRowsView(rows: document, initialPage: initialPage)
                        Text("Later message")
                    }.padding(16)
                })
        }
        let clock = ContinuousClock()
        let start = clock.now
        let host = UIHostingController(rootView: root)
        let scene = UIApplication.shared.connectedScenes.compactMap { $0 as? UIWindowScene }.first!
        let window = UIWindow(windowScene: scene)
        window.frame = CGRect(x: 0, y: 0, width: 390, height: 844)
        window.rootViewController = host
        window.makeKeyAndVisible()
        host.view.layoutIfNeeded()
        RunLoop.main.run(until: Date().addingTimeInterval(0.05))
        let elapsed = clock.now - start
        return (host, window, Double(elapsed.components.seconds) + Double(elapsed.components.attoseconds) / 1e18)
    }

    /// The container an isolated run measures, `scrollView` unless `YAK_LAYOUT_CONTAINER` names another.
    nonisolated static var selectedContainers: [Container] {
        guard isolatedInput != nil else { return Container.allCases }
        let name = ProcessInfo.processInfo.environment["YAK_LAYOUT_CONTAINER"] ?? "scrollView"
        return Container.allCases.filter { "\($0)" == name }
    }

    nonisolated static var selectedInputs: [Input] {
        guard let name = isolatedInput else { return Input.allCases }
        return Input.allCases.filter { $0.rawValue == name }
    }

    /// Time at every input and container. With one input per process, also memory at the first screen and after
    /// scrolling to the end in steps of 800 points, which builds every row the lazy stack ever builds.
    @Test(arguments: selectedInputs, selectedContainers)
    func theFirstScreenAndTheEndAreCheap(input: Input, container: Container) async {
        let text = input.text
        let before = Self.footprintBytes()
        let document = await Task.detached { await AppMarkdownRenderer().parse(text) }.value
        let (host, window, seconds) = firstScreen(document, initialPage: input.initialPage, in: container)
        defer { window.isHidden = true }
        let firstScreenMegabytes = Double(Self.footprintBytes() - before) / 1_048_576
        #expect(seconds < Self.timeLimit, "\(input) in \(container) took \(seconds) s")
        guard Self.isolatedInput != nil else { return }
        #expect(
            firstScreenMegabytes < Self.memoryLimitMegabytes,
            "\(input) in \(container) first screen grew by \(firstScreenMegabytes) MB")
        let worst = Self.scrollToTheEnd(host)
        let endMegabytes = Double(Self.footprintBytes() - before) / 1_048_576
        print(
            "MEASURED \(input) \(container): first screen \(String(format: "%.2f", seconds)) s, "
                + "\(String(format: "%.0f", firstScreenMegabytes)) MB; after scrolling \(String(format: "%.0f", endMegabytes)) MB, "
                + "worst step \(String(format: "%.2f", worst)) s, \(document.count) rows")
        #expect(worst < Self.scrollStepLimit, "\(input) in \(container) had a scroll step of \(worst) s")
        #expect(
            endMegabytes < Self.memoryLimitMegabytes,
            "\(input) in \(container) grew by \(endMegabytes) MB after scrolling")
    }

    /// Scrolls to the end in steps of 800 points and returns the slowest step in seconds.
    static func scrollToTheEnd(_ host: UIViewController) -> Double {
        guard let scroller = scrollView(in: host.view) else { return .infinity }
        let clock = ContinuousClock()
        var worst = 0.0
        var steps = 0
        while scroller.contentOffset.y + 844 < scroller.contentSize.height, steps < 600 {
            let start = clock.now
            scroller.contentOffset.y += 800
            host.view.layoutIfNeeded()
            CATransaction.flush()
            let elapsed = clock.now - start
            worst = max(worst, Double(elapsed.components.seconds) + Double(elapsed.components.attoseconds) / 1e18)
            steps += 1
        }
        RunLoop.main.run(until: Date().addingTimeInterval(0.2))
        return worst
    }

    static func scrollView(in view: UIView) -> UIScrollView? {
        if let scroller = view as? UIScrollView { return scroller }
        for child in view.subviews { if let found = scrollView(in: child) { return found } }
        return nil
    }
}
