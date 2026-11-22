import Foundation
import Testing

@testable import YakKit

@Suite struct FileViewerPolicyTests {
    private let policy = FileViewerPolicy(instanceURL: URL(string: "https://yak.example.com")!)
    private static let id = "01HZX3K9ABCDEFGHJKMNPQRSTV"
    private static let page = "https://yak.example.com/files/\(id)"

    private struct Case: Sendable, CustomTestStringConvertible {
        let url: String?
        var method: String? = nil
        var isMainFrame = true
        var isUserInitiated = false
        let expected: FileViewerPolicy.Decision
        var testDescription: String { "\(method ?? "-") \(url ?? "nil") main:\(isMainFrame) tap:\(isUserInitiated)" }
    }

    private static let cases: [Case] = [
        // What the viewer loads by itself.
        Case(url: page, expected: .allow),
        Case(url: page, method: "get", expected: .allow),
        Case(url: page + "/raw", isMainFrame: false, expected: .allow),
        Case(url: "about:blank", isMainFrame: false, expected: .allow),
        Case(url: "about:srcdoc", isMainFrame: false, expected: .allow),
        // Not by itself: the bytes in the main frame, the page in a frame, anything else.
        Case(url: page + "/raw", expected: .cancel),
        Case(url: page, isMainFrame: false, expected: .cancel),
        Case(url: "about:blank", expected: .cancel),
        Case(url: nil, expected: .cancel),
        Case(url: "https://yak.example.com/app/embed/threads/d0e515ef", expected: .cancel),
        Case(url: "https://yak.example.com/threads/d0e515ef", expected: .cancel),
        Case(url: "https://yak.example.com/artifacts/7/viewer/report.html", expected: .cancel),
        Case(url: "https://yak.example.com/files", expected: .cancel),
        // Other origins.
        Case(url: "https://other.example.com/files/\(id)", expected: .cancel),
        Case(url: "https://yak.example.com.evil.test/files/\(id)", expected: .cancel),
        Case(url: "http://yak.example.com/files/\(id)", expected: .cancel),
        Case(url: "https://yak.example.com:8443/files/\(id)", expected: .cancel),
        Case(url: "https://user:secret@yak.example.com/files/\(id)", expected: .cancel),
        Case(url: "https://user@yak.example.com/files/\(id)", expected: .cancel),
        // Other paths: dot segments, encoding, length, trailing parts, query, fragment.
        Case(url: "https://yak.example.com/files/../files/\(id)", expected: .cancel),
        Case(url: "https://yak.example.com/files/\(id)/../\(id)", expected: .cancel),
        Case(url: "https://yak.example.com/files/%2e%2e/\(id)", expected: .cancel),
        Case(url: "https://yak.example.com/files/\(id.dropLast())%2F", expected: .cancel),
        Case(url: "https://yak.example.com/files/\(id.dropLast())", expected: .cancel),
        Case(url: "https://yak.example.com/files/\(id)A", expected: .cancel),
        Case(url: page + "/", expected: .cancel),
        Case(url: page + "//raw", isMainFrame: false, expected: .cancel),
        Case(url: page + "/raw/x", isMainFrame: false, expected: .cancel),
        Case(url: page + "?x=1", expected: .cancel),
        Case(url: page + "/raw?x=1", isMainFrame: false, expected: .cancel),
        Case(url: page + "#top", expected: .cancel),
        // Not a GET.
        Case(url: page, method: "POST", expected: .cancel),
        Case(url: page + "/raw", method: "POST", isMainFrame: false, expected: .cancel),
        Case(url: page, method: "PUT", isUserInitiated: true, expected: .cancel),
        // A tap leaves the viewer through the link rule.
        Case(
            url: "https://example.com/docs", isUserInitiated: true,
            expected: .openLink(URL(string: "https://example.com/docs")!)),
        Case(
            url: "https://example.com/docs", isMainFrame: false, isUserInitiated: true,
            expected: .openLink(URL(string: "https://example.com/docs")!)),
        Case(url: "https://yak.example.com/threads/d0e515ef", isUserInitiated: true, expected: .openThread("d0e515ef")),
        Case(url: "http://example.com/docs", isUserInitiated: true, expected: .cancel),
        Case(url: "javascript:alert(1)", isUserInitiated: true, expected: .cancel),
        Case(url: "https://user:secret@example.com/", isUserInitiated: true, expected: .cancel),
        Case(url: page, isUserInitiated: true, expected: .openLink(URL(string: page)!)),
        // The page's Download link.
        Case(
            url: page + "/raw?download=1", isUserInitiated: true,
            expected: .download(URL(string: page + "/raw?download=1")!)),
        Case(
            url: page + "/raw?download=1", isMainFrame: false, isUserInitiated: true,
            expected: .download(URL(string: page + "/raw?download=1")!)),
        Case(url: page + "/raw?download=1", expected: .cancel),
        Case(url: page + "/raw?download=1", method: "POST", isUserInitiated: true, expected: .cancel),
        Case(
            url: page + "/raw?download=1&x=1", isUserInitiated: true,
            expected: .openLink(URL(string: page + "/raw?download=1&x=1")!)),
        Case(
            url: "https://other.example.com/files/\(id)/raw?download=1", isUserInitiated: true,
            expected: .openLink(URL(string: "https://other.example.com/files/\(id)/raw?download=1")!)),
    ]

    @Test(arguments: cases)
    private func decidesNavigations(_ item: Case) {
        let url = item.url.flatMap { URL(string: $0) }
        #expect(
            policy.decide(
                url: url, method: item.method, isMainFrame: item.isMainFrame, isUserInitiated: item.isUserInitiated)
                == item.expected)
    }

    @Test func loopbackHTTPWorksOnlyWhenAllowed() {
        let address = URL(string: "http://127.0.0.1:8123/files/\(Self.id)")!
        let open = FileViewerPolicy(instanceURL: URL(string: "http://127.0.0.1:8123")!, allowsLoopbackHTTP: true)
        let closed = FileViewerPolicy(instanceURL: URL(string: "http://127.0.0.1:8123")!)
        #expect(open.decide(url: address, method: nil, isMainFrame: true, isUserInitiated: false) == .allow)
        #expect(closed.decide(url: address, method: nil, isMainFrame: true, isUserInitiated: false) == .cancel)
    }

    @Test(arguments: [
        (401, FileViewerPolicy.Failure.signedOut), (419, .signedOut), (403, .unavailable), (404, .unavailable),
        (410, .unavailable), (500, .server(500)), (503, .server(503)), (429, .server(429)),
    ])
    func namesAFailedResponse(status: Int, failure: FileViewerPolicy.Failure) {
        #expect(policy.failure(ofResponseFrom: URL(string: Self.page)!, status: status) == failure)
        #expect(policy.failure(ofResponseFrom: URL(string: Self.page + "/raw")!, status: status) == failure)
    }

    @Test func aGoodResponseOrAnotherOriginIsNotAFailure() {
        #expect(policy.failure(ofResponseFrom: URL(string: Self.page)!, status: 200) == nil)
        #expect(policy.failure(ofResponseFrom: URL(string: Self.page)!, status: 304) == nil)
        #expect(policy.failure(ofResponseFrom: URL(string: "https://other.example.com/x")!, status: 404) == nil)
    }

    @Test func showsOnlyAFilePage() {
        #expect(policy.canShow(URL(string: Self.page)!))
        #expect(!policy.canShow(URL(string: Self.page + "/raw")!))
        #expect(!policy.canShow(URL(string: "https://other.example.com/files/\(Self.id)")!))
        #expect(!policy.canShow(URL(string: "https://yak.example.com/threads/d0e515ef")!))
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
        let thread = EmbedNavigationPolicy(instanceURL: URL(string: "https://yak.example.com")!)
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
}
