import Foundation
import Testing

@testable import YakKit

@Suite struct FileViewerPolicyTests {
    private static let instance = URL(string: "https://yak.example.com")!
    private static let id = "01HZX3K9ABCDEFGHJKMNPQRSTV"
    private static let otherID = "01HZX3K9ZZZZZZZZZZZZZZZZZZ"
    private static let page = "https://yak.example.com/files/\(id)"
    private static let download = page + "/raw?download=1"
    private let policy = FileViewerPolicy(fileURL: URL(string: page)!, instanceURL: instance)!

    private struct Case: Sendable, CustomTestStringConvertible {
        let url: String?
        var method: String?
        var isMainFrame = true
        var isUserInitiated = false
        let expected: FileViewerPolicy.Decision
        var testDescription: String { "\(method ?? "-") \(url ?? "nil") main:\(isMainFrame) tap:\(isUserInitiated)" }
    }

    private static func link(_ text: String) -> FileViewerPolicy.Decision { .openLink(URL(string: text)!) }

    private static let cases: [Case] = [
        // What the viewer loads by itself: its own page in the main frame, its own bytes in the frame.
        Case(url: page, expected: .allow),
        Case(url: page, method: "get", expected: .allow),
        Case(url: page + "/raw", isMainFrame: false, expected: .allow),
        Case(url: "about:blank", isMainFrame: false, expected: .allow),
        Case(url: "about:srcdoc", isMainFrame: false, expected: .allow),
        // Not by itself: the bytes in the main frame, the page in a frame, a download, nothing at all.
        Case(url: page + "/raw", expected: .cancel),
        Case(url: page, isMainFrame: false, expected: .cancel),
        Case(url: "about:blank", expected: .cancel),
        Case(url: nil, expected: .cancel),
        Case(url: download, expected: .cancel),
        Case(url: download, isMainFrame: false, expected: .cancel),
        // No other id: neither its bytes in the frame nor, by itself, its page.
        Case(url: "https://yak.example.com/files/\(otherID)/raw", isMainFrame: false, expected: .cancel),
        Case(url: "https://yak.example.com/files/\(otherID)/raw?download=1", isMainFrame: false, expected: .cancel),
        // The load went to another page of the instance: the session ended and the server sent the login page.
        Case(url: "https://yak.example.com/login", expected: .signedOut),
        Case(url: "https://yak.example.com/login?x=1", expected: .signedOut),
        Case(url: "https://yak.example.com/files/\(otherID)", expected: .signedOut),
        Case(url: "https://yak.example.com/app/embed/threads/d0e515ef", expected: .signedOut),
        Case(url: page + "?x=1", expected: .signedOut),
        Case(url: page + "#top", expected: .signedOut),
        Case(url: "https://yak.example.com/files/../files/\(id)", expected: .signedOut),
        Case(url: "https://yak.example.com/files/%2e%2e/\(id)", expected: .signedOut),
        Case(url: "https://yak.example.com/login", isMainFrame: false, expected: .cancel),
        // Other origins, schemes and user:password never count as the instance.
        Case(url: "https://other.example.com/files/\(id)", expected: .cancel),
        Case(url: "https://yak.example.com.evil.test/login", expected: .cancel),
        Case(url: "http://yak.example.com/login", expected: .cancel),
        Case(url: "https://yak.example.com:8443/login", expected: .cancel),
        Case(url: "https://user:secret@yak.example.com/files/\(id)", expected: .cancel),
        Case(url: "https://user@yak.example.com/login", expected: .cancel),
        // Not a GET.
        Case(url: page, method: "POST", expected: .cancel),
        Case(url: page + "/raw", method: "POST", isMainFrame: false, expected: .cancel),
        Case(url: "https://yak.example.com/login", method: "POST", expected: .cancel),
        Case(url: page, method: "PUT", isUserInitiated: true, expected: .cancel),
        Case(url: download, method: "POST", isUserInitiated: true, expected: .cancel),
        // A tap on the page leaves the viewer through the link rule; its own Download link goes to Quick Look.
        Case(url: "https://example.com/docs", isUserInitiated: true, expected: link("https://example.com/docs")),
        Case(url: "https://yak.example.com/threads/d0e515ef", isUserInitiated: true, expected: .openThread("d0e515ef")),
        Case(url: download, isUserInitiated: true, expected: .download(URL(string: download)!)),
        Case(url: page, isUserInitiated: true, expected: link(page)),
        Case(url: "http://example.com/docs", isUserInitiated: true, expected: .cancel),
        Case(url: "javascript:alert(1)", isUserInitiated: true, expected: .cancel),
        Case(url: "https://user:secret@example.com/", isUserInitiated: true, expected: .cancel),
        Case(
            url: "https://yak.example.com/files/\(otherID)/raw?download=1", isUserInitiated: true,
            expected: link("https://yak.example.com/files/\(otherID)/raw?download=1")),
        Case(url: download + "&x=1", isUserInitiated: true, expected: link(download + "&x=1")),
        // A tap in the report (a frame) opens the browser sheet and nothing else: no thread, no download, no file.
        Case(
            url: "https://example.com/docs", isMainFrame: false, isUserInitiated: true,
            expected: link("https://example.com/docs")),
        Case(
            url: "https://yak.example.com/threads/d0e515ef", isMainFrame: false, isUserInitiated: true,
            expected: link("https://yak.example.com/threads/d0e515ef")),
        Case(url: download, isMainFrame: false, isUserInitiated: true, expected: link(download)),
        Case(url: page, isMainFrame: false, isUserInitiated: true, expected: link(page)),
        Case(url: "http://example.com/docs", isMainFrame: false, isUserInitiated: true, expected: .cancel),
        Case(url: "javascript:alert(1)", isMainFrame: false, isUserInitiated: true, expected: .cancel),
    ]

    @Test(arguments: cases)
    private func decidesNavigations(_ item: Case) {
        let url = item.url.flatMap { URL(string: $0) }
        #expect(
            policy.decide(
                url: url, method: item.method, isMainFrame: item.isMainFrame, isUserInitiated: item.isUserInitiated)
                == item.expected)
    }

    @Test func aReportNeverGetsAThreadADownloadOrAFileOfItsOwn() {
        let targets = [
            "https://yak.example.com/threads/d0e515ef", Self.download, Self.page,
            "https://yak.example.com/files/\(Self.otherID)",
        ]
        for text in targets {
            let decision = policy.decide(
                url: URL(string: text), method: nil, isMainFrame: false, isUserInitiated: true)
            guard case .openLink = decision else {
                Issue.record("a report's tap on \(text) was \(decision)")
                continue
            }
        }
    }

    @Test func loopbackHTTPWorksOnlyWhenAllowed() throws {
        let instance = URL(string: "http://127.0.0.1:8123")!
        let address = URL(string: "http://127.0.0.1:8123/files/\(Self.id)")!
        let open = try #require(FileViewerPolicy(fileURL: address, instanceURL: instance, allowsLoopbackHTTP: true))
        #expect(FileViewerPolicy(fileURL: address, instanceURL: instance) == nil)
        #expect(open.decide(url: address, method: nil, isMainFrame: true, isUserInitiated: false) == .allow)
        let download = URL(string: address.absoluteString + "/raw?download=1")!
        #expect(
            open.decide(url: download, method: nil, isMainFrame: true, isUserInitiated: true) == .download(download))
        let thread = URL(string: "http://127.0.0.1:8123/threads/d0e515ef")!
        #expect(
            open.decide(url: thread, method: nil, isMainFrame: true, isUserInitiated: true) == .openThread("d0e515ef"))
        let login = URL(string: "http://127.0.0.1:8123/login")!
        #expect(open.decide(url: login, method: nil, isMainFrame: true, isUserInitiated: false) == .signedOut)
    }

    @Test func showsOnlyAFilePageOfTheInstance() {
        let other = ["/raw", "?x=1", "#a"].map { Self.page + $0 }
        for text in other + [
            "https://other.example.com/files/\(Self.id)", "https://yak.example.com/threads/d0e515ef",
            "https://yak.example.com/files/short",
        ] {
            #expect(FileViewerPolicy(fileURL: URL(string: text)!, instanceURL: Self.instance) == nil, "\(text)")
        }
        #expect(policy.fileID == Self.id)
    }

    @Test(arguments: [
        (401, FileViewerPolicy.Failure.signedOut), (419, .signedOut), (403, .unavailable), (404, .unavailable),
        (410, .unavailable), (500, .server(500)), (503, .server(503)), (429, .server(429)),
    ])
    func namesAFailedResponseOfItsOwnPageAndBytes(status: Int, failure: FileViewerPolicy.Failure) {
        let page = URL(string: Self.page)!
        let raw = URL(string: Self.page + "/raw")!
        #expect(policy.failure(ofResponseFrom: page, status: status, isMainFrame: true) == failure)
        #expect(policy.failure(ofResponseFrom: raw, status: status, isMainFrame: false) == failure)
    }

    @Test func aResponseOfAnythingElseIsNotAFailureOfTheViewer() {
        let page = URL(string: Self.page)!
        let raw = URL(string: Self.page + "/raw")!
        #expect(policy.failure(ofResponseFrom: page, status: 200, isMainFrame: true) == nil)
        #expect(policy.failure(ofResponseFrom: page, status: 304, isMainFrame: true) == nil)
        #expect(policy.failure(ofResponseFrom: raw, status: 404, isMainFrame: true) == nil)
        #expect(policy.failure(ofResponseFrom: page, status: 404, isMainFrame: false) == nil)
        for text in [
            "https://other.example.com/x", "https://yak.example.com/files/\(Self.otherID)/raw",
            "https://yak.example.com/files/\(Self.otherID)", "https://yak.example.com/anything",
            Self.page + "/raw?x=1",
        ] {
            let url = URL(string: text)!
            #expect(policy.failure(ofResponseFrom: url, status: 404, isMainFrame: false) == nil, "\(text)")
            #expect(policy.failure(ofResponseFrom: url, status: 404, isMainFrame: true) == nil, "\(text)")
        }
    }

    @Test func aPageTheWebViewCannotShowOrAnAttachmentGoesToQuickLook() {
        let page = URL(string: Self.page)!
        func action(
            status: Int = 200, isMainFrame: Bool = true, canShow: Bool = true, disposition: String? = nil,
            url: URL? = nil
        ) -> FileViewerPolicy.ResponseAction {
            policy.responseAction(
                url: url ?? page, status: status, isMainFrame: isMainFrame, canShowMIMEType: canShow,
                contentDisposition: disposition)
        }
        #expect(action() == .allow)
        #expect(action(canShow: false) == .quickLook(page))
        #expect(action(disposition: "attachment; filename=\"a.pdf\"") == .quickLook(page))
        #expect(action(disposition: "  Attachment") == .quickLook(page))
        #expect(action(disposition: "inline; filename=\"a.png\"") == .allow)
        #expect(action(status: 404, canShow: false) == .fail(.unavailable))
        #expect(action(status: 401) == .fail(.signedOut))
        // Only its own page in the main frame is handed over; a frame never starts a download.
        #expect(action(isMainFrame: false, canShow: false) == .allow)
        let other = URL(string: "https://yak.example.com/files/\(Self.otherID)")!
        #expect(action(canShow: false, url: other) == .allow)
    }

    @Test(arguments: [
        ("NSURLErrorDomain", -999, true), ("WebKitErrorDomain", 102, true), ("NSURLErrorDomain", -1009, false),
        ("NSURLErrorDomain", -1001, false), ("WebKitErrorDomain", 101, false), ("WebKitErrorDomain", 100, false),
        ("NSCocoaErrorDomain", -999, false), ("SomethingElse", 102, false),
    ])
    func ignoresOnlyTheCancellationItCaused(domain: String, code: Int, isIgnored: Bool) {
        #expect(FileViewerPolicy.isOwnCancellation(domain: domain, code: code) == isIgnored)
    }

    @Test(
        arguments: [
            ("Weekly report.html - Yak", "Weekly report.html"), ("  Weekly report  ", "Weekly report"),
            ("Notes - Yak - Yak", "Notes - Yak"), ("   ", nil), ("", nil), (nil, nil),
        ] as [(String?, String?)])
    func namesTheFileFromThePageTitle(title: String?, name: String?) {
        #expect(FileViewerPolicy.name(fromPageTitle: title) == name)
    }

    @Test func theThreadPolicyOpensAFilePageInTheViewer() {
        let thread = EmbedNavigationPolicy(instanceURL: Self.instance)
        let file = URL(string: Self.page)!
        #expect(thread.decide(url: file, method: nil, isMainFrame: true, isUserInitiated: true) == .openFile(file))
        #expect(thread.decide(url: file, method: nil, isMainFrame: true, isUserInitiated: false) == .cancel)
        #expect(thread.decide(url: file, method: "POST", isMainFrame: true, isUserInitiated: true) != .openFile(file))
        for text in [
            Self.page + "?x=1", Self.page + "/raw", Self.page + "/../x", "https://other.example.com/files/\(Self.id)",
        ] {
            let url = URL(string: text)!
            #expect(thread.decide(url: url, method: nil, isMainFrame: true, isUserInitiated: true) != .openFile(url))
        }
    }

    @Test func readsTheIdOfAnyOfTheThreeAddressForms() {
        let thread = EmbedNavigationPolicy(instanceURL: Self.instance)
        for text in [Self.page, Self.page + "/raw", Self.download] {
            #expect(thread.sharedFileID(of: URL(string: text)!) == Self.id, "\(text)")
        }
        for text in [Self.page + "?x=1", Self.page + "/raw?x=1", "https://other.example.com/files/\(Self.id)"] {
            #expect(thread.sharedFileID(of: URL(string: text)!) == nil, "\(text)")
        }
    }
}

@Suite struct RecentTouchGateTests {
    private let start = ContinuousClock.now

    @Test func noTouchNoAction() {
        var gate = RecentTouchGate()
        let taken1 = gate.takesAction(at: start)
        #expect(!taken1)
    }

    @Test func aTouchLetsOneActionThrough() {
        var gate = RecentTouchGate()
        gate.touched(at: start)
        let taken2 = gate.takesAction(at: start.advanced(by: .milliseconds(300)))
        #expect(taken2)
        let taken3 = gate.takesAction(at: start.advanced(by: .milliseconds(400)))
        #expect(!taken3)
    }

    @Test func anActionAfterTheWindowIsNotTheResultOfTheTouch() {
        var gate = RecentTouchGate()
        gate.touched(at: start)
        let taken4 = gate.takesAction(at: start.advanced(by: RecentTouchGate.window + .milliseconds(1)))
        #expect(!taken4)
        let taken5 = gate.takesAction(at: start.advanced(by: .seconds(30)))
        #expect(!taken5)
    }

    @Test func theEdgeOfTheWindowStillCounts() {
        var gate = RecentTouchGate()
        gate.touched(at: start)
        let taken6 = gate.takesAction(at: start.advanced(by: RecentTouchGate.window))
        #expect(taken6)
    }

    @Test func aNewTouchTakesTheNextAction() {
        var gate = RecentTouchGate()
        gate.touched(at: start)
        let taken7 = gate.takesAction(at: start.advanced(by: .milliseconds(100)))
        #expect(taken7)
        gate.touched(at: start.advanced(by: .seconds(5)))
        let taken8 = gate.takesAction(at: start.advanced(by: .seconds(5) + .milliseconds(100)))
        #expect(taken8)
        let taken9 = gate.takesAction(at: start.advanced(by: .seconds(5) + .milliseconds(200)))
        #expect(!taken9)
    }

    @Test func aScriptThatClicksOnATimerLongAfterTheLastTouchGetsNothing() {
        var gate = RecentTouchGate()
        gate.touched(at: start)
        for second in 2...20 {
            let taken = gate.takesAction(at: start.advanced(by: .seconds(second)))
            #expect(!taken)
        }
    }

    @Test func anActionBeforeTheTouchIsNotItsResult() {
        var gate = RecentTouchGate()
        gate.touched(at: start.advanced(by: .seconds(2)))
        let taken10 = gate.takesAction(at: start)
        #expect(!taken10)
    }
}
