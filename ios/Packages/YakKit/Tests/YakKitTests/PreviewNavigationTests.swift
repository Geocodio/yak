import Foundation
import Testing

@testable import YakKit

private let preview = "t-p7m4q2hx9z.yak.example.test"
private let instance = "yak.example.com"

private func decide(_ text: String, main: Bool, user: Bool = true) -> PreviewNavigation.Decision {
    PreviewNavigation.decide(
        url: URL(string: text)!, previewHost: preview, instanceHost: instance, isMainFrame: main, isUserInitiated: user)
}

@Test func thePaneShowsOnlyThePreviewHost() {
    #expect(decide("https://t-p7m4q2hx9z.yak.example.test/pricing", main: true) == .allow)
    #expect(
        decide("https://other.example.com/docs", main: true)
            == .openInBrowser(URL(string: "https://other.example.com/docs")!))
    #expect(decide("https://other.example.com/embed", main: false) == .allow)
    #expect(decide("http://t-p7m4q2hx9z.yak.example.test/", main: true) == .cancel)
    #expect(decide("https://T-P7M4Q2HX9Z.yak.example.test/", main: true) == .allow)
    #expect(
        decide("https://t-p7m4q2hx9z.yak.example.test:8443/", main: true)
            == .openInBrowser(URL(string: "https://t-p7m4q2hx9z.yak.example.test:8443/")!))
}

@Test func theAuthBounceBecomesATicket() {
    let bounce =
        "https://yak.example.com/deployments/auth-bounce?to=https%3A%2F%2Ft-p7m4q2hx9z.yak.example.test%2Fpricing%3Fplan%3Dteam&signature=x"
    #expect(decide(bounce, main: true) == .mintTicket(path: "/pricing?plan=team"))
    let foreign = "https://yak.example.com/deployments/auth-bounce?to=https%3A%2F%2Fother.example.com%2Fx"
    #expect(decide(foreign, main: true) == .mintTicket(path: "/"))
    #expect(
        decide("https://yak.example.com/login", main: true)
            == .openInBrowser(URL(string: "https://yak.example.com/login")!))
    #expect(decide(bounce, main: false) == .cancel)
}

@Test func otherSchemes() {
    #expect(decide("about:blank", main: true) == .allow)
    #expect(decide("about:blank", main: false) == .allow)
    #expect(decide("javascript:alert(1)", main: true) == .cancel)
    #expect(decide("javascript:alert(1)", main: false) == .cancel)
    #expect(
        decide("mailto:a@example.test", main: true) == .openInBrowser(URL(string: "mailto:a@example.test")!))
    #expect(decide("mailto:a@example.test", main: false) == .cancel)
    #expect(decide("data:text/html,hi", main: true) == .cancel)
    #expect(decide("data:text/html,hi", main: false) == .allow)
    #expect(decide("blob:https://other.example.com/x", main: false) == .allow)
    #expect(decide("file:///etc/hosts", main: true) == .cancel)
}

@Test func aScriptCannotThrowThePersonOut() {
    for text in [
        "https://other.example.com/docs", "tel:+15550100", "mailto:a@example.test", "https://yak.example.com/login",
    ] {
        #expect(decide(text, main: true, user: false) == .cancelUnrequestedOpen)
    }
    let bounce = "https://yak.example.com/deployments/auth-bounce?to=https%3A%2F%2Ft-p7m4q2hx9z.yak.example.test%2Fa"
    #expect(decide(bounce, main: true, user: false) == .mintTicket(path: "/a"))
    #expect(decide("https://t-p7m4q2hx9z.yak.example.test/pricing", main: true, user: false) == .allow)
    #expect(decide("https://other.example.com/embed", main: false, user: false) == .allow)
}

@Test func loopbackHTTPOnlyInTestMode() {
    func loopback(_ text: String, allows: Bool) -> PreviewNavigation.Decision {
        PreviewNavigation.decide(
            url: URL(string: text)!, previewHost: "127.0.0.1:50211", instanceHost: "127.0.0.1:50210",
            isMainFrame: true, isUserInitiated: true, allowsLoopbackHTTP: allows)
    }
    #expect(loopback("http://127.0.0.1:50211/", allows: true) == .allow)
    #expect(loopback("http://127.0.0.1:50211/", allows: false) == .cancel)
    let bounce = "http://127.0.0.1:50210/deployments/auth-bounce?to=http%3A%2F%2F127.0.0.1%3A50211%2Fx"
    #expect(loopback(bounce, allows: true) == .mintTicket(path: "/x"))
}

@Test func pageAnswersThatNeedAction() {
    func respond(_ status: Int, _ text: String, main: Bool = true) -> PreviewNavigation.ResponseDecision {
        PreviewNavigation.respond(status: status, url: URL(string: text)!, previewHost: preview, isMainFrame: main)
    }
    let auth = "https://t-p7m4q2hx9z.yak.example.test/_yak/auth?ticket=t&to=%2Fpricing"
    #expect(respond(401, auth) == .mintAgain(path: "/pricing"))
    #expect(respond(401, "https://t-p7m4q2hx9z.yak.example.test/_yak/auth") == .mintAgain(path: "/"))
    for status in [425, 502, 404] {
        #expect(respond(status, "https://t-p7m4q2hx9z.yak.example.test/") == .refetchStatus)
    }
    #expect(respond(200, "https://t-p7m4q2hx9z.yak.example.test/") == .allow)
    #expect(respond(500, "https://t-p7m4q2hx9z.yak.example.test/") == .allow)
    #expect(respond(401, auth, main: false) == .allow)
    #expect(respond(401, "https://other.example.com/_yak/auth") == .allow)
}

@Test func aTicketMustMatchThePreview() {
    let previewURL = URL(string: "https://t-p7m4q2hx9z.yak.example.test/")!
    func accepted(_ text: String, preview: URL = previewURL, loopback: Bool = false) -> Bool {
        PreviewNavigation.isAcceptedTicket(URL(string: text)!, previewURL: preview, allowsLoopbackHTTP: loopback)
    }
    #expect(accepted("https://t-p7m4q2hx9z.yak.example.test/_yak/auth?ticket=t"))
    #expect(!accepted("https://evil.example.com/_yak/auth"))
    #expect(!accepted("http://t-p7m4q2hx9z.yak.example.test/_yak/auth"))
    #expect(!accepted("https://t-p7m4q2hx9z.yak.example.test/other"))
    #expect(!accepted("https://t-p7m4q2hx9z.yak.example.test:444/_yak/auth"))
    let local = URL(string: "http://127.0.0.1:50211/")!
    #expect(accepted("http://127.0.0.1:50211/_yak/auth", preview: local, loopback: true))
    #expect(!accepted("http://127.0.0.1:50211/_yak/auth", preview: local))
}

@Test(arguments: [
    ("pricing", "/pricing"), ("//other.example.com/x", "/other.example.com/x"), ("\\\\other", "/other"),
    ("  /a b?c=1#top ", "/a%20b?c=1"), ("", "/"), ("/\u{7}x", "/x"),
    ("https://t-p7m4q2hx9z.yak.example.test/plans?y=1", "/plans?y=1"),
    ("https://other.example.com/x", "/https://other.example.com/x"),
    ("/a%20b", "/a%20b"), ("/100%", "/100%25"),
])
func addressesStayOnTheHost(typed: String, expected: String) {
    #expect(PreviewAddress.normalize(typed, previewHost: preview) == expected)
}

@Test func anAddressHasALimit() {
    let long = PreviewAddress.normalize(String(repeating: "é", count: 3000))
    #expect(long.count <= 2048 && !long.hasSuffix("%") && URL(string: long) != nil)
}

@Test func anAddressBuildsAURLOnThePreviewOrigin() {
    let previewURL = URL(string: "https://t-p7m4q2hx9z.yak.example.test:8443/old?x=1")!
    #expect(
        PreviewAddress.url(path: "//evil.example.com/a b", previewURL: previewURL)?.absoluteString
            == "https://t-p7m4q2hx9z.yak.example.test:8443/evil.example.com/a%20b")
}

@Test func aTicketNeverLeavesThePane() {
    let landed = URL(string: "https://t-p7m4q2hx9z.yak.example.test/_yak/auth?ticket=secret&to=%2Fpricing")!
    #expect(PreviewAddress.display(landed) == "/pricing")
    #expect(PreviewAddress.copyable(landed) == URL(string: "https://t-p7m4q2hx9z.yak.example.test/pricing"))
    #expect(PreviewAddress.copyable(landed)?.absoluteString.contains("secret") == false)
    #expect(
        PreviewAddress.display(nil) == "/"
            && PreviewAddress.display(URL(string: "https://t-p7m4q2hx9z.yak.example.test")) == "/")
    #expect(PreviewAddress.copyable(nil) == nil)
}

@Test func pageProblems() {
    #expect(PreviewPageProblem.make(.notConnectedToInternet) == .offline)
    #expect(PreviewPageProblem.make(.networkConnectionLost) == .offline)
    #expect(PreviewPageProblem.make(.timedOut) == .timedOut)
    #expect(PreviewPageProblem.make(.serverCertificateUntrusted) == .untrustedCertificate)
    #expect(PreviewPageProblem.make(.cannotFindHost) == .cannotReach)
    #expect(PreviewPageProblem.make(.cancelled) == nil)
    #expect(PreviewPageProblem.make(.badServerResponse) == .other)
}

@Test func dataStoreIDsAreStableAndDistinct() {
    let first = UUID()
    let second = UUID()
    #expect(PreviewDataStoreID.for(instanceID: first) == PreviewDataStoreID.for(instanceID: first))
    #expect(PreviewDataStoreID.for(instanceID: first) != PreviewDataStoreID.for(instanceID: second))
    #expect(PreviewDataStoreID.for(instanceID: first) != first)
    let bytes = PreviewDataStoreID.for(instanceID: first).uuid
    #expect(bytes.6 >> 4 == 8 && bytes.8 >> 6 == 0b10)
}

@Test func storesToErase() {
    let a = UUID()
    let b = UUID()
    let c = UUID()
    let d = UUID()
    let e = UUID()
    let storeA = PreviewDataStoreID.for(instanceID: a)
    let storeB = PreviewDataStoreID.for(instanceID: b)
    let storeC = PreviewDataStoreID.for(instanceID: c)
    let unknownToKeep = UUID()
    let foreign = UUID()
    let result = PreviewDataStoreID.toErase(
        existing: [storeA, storeB, storeC, unknownToKeep, foreign],
        known: [storeA, storeB, storeC, unknownToKeep], keep: [a, c], pending: [c])
    #expect(result == [storeB, storeC, unknownToKeep])
    #expect(!result.contains(foreign))
    #expect(
        PreviewDataStoreID.toErase(
            existing: [storeA], known: [storeA, PreviewDataStoreID.for(instanceID: e)], keep: [a], pending: [d])
            == [])
    #expect(PreviewDataStoreID.toErase(existing: [], known: [storeA], keep: [], pending: []) == [])
    #expect(PreviewDataStoreID.toErase(existing: [storeB, storeB], known: [storeB], keep: [], pending: []) == [storeB])
}

@Test func theLengthCutNeverEndsInsideAMultiByteCharacter() {
    let cut = PreviewAddress.normalize("/" + String(repeating: "é", count: 1500))
    #expect(cut.count <= 2048)
    #expect(!cut.hasSuffix("%C3"))
    #expect(cut.hasSuffix("%C3%A9"))
    #expect(PreviewAddress.normalize("/" + String(repeating: "a", count: 3000)).count == 2048)
}

@Test func aboutBlankWithAFragmentOrQueryIsStillBlank() {
    #expect(decide("about:blank#x", main: true) == .allow)
    #expect(decide("about:blank#x", main: false) == .allow)
    #expect(decide("about:srcdoc?a=b", main: false) == .allow)
    #expect(decide("about:config", main: false) == .cancel)
}

@Test func aTrailingSlashOrOtherCaseOnTheAuthPathNeverShowsTheTicket() {
    let slash = URL(string: "https://t-p7m4q2hx9z.yak.example.test/_yak/auth/?ticket=s&to=%2Fp")!
    #expect(PreviewAddress.display(slash) == "/p")
    #expect(PreviewAddress.copyable(slash)?.absoluteString == "https://t-p7m4q2hx9z.yak.example.test/p")
    let upper = URL(string: "https://t-p7m4q2hx9z.yak.example.test/_YAK/Auth?ticket=s&to=%2Fp")!
    #expect(PreviewAddress.display(upper) == "/p")
}
