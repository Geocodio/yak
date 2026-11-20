import Foundation
import Testing

@testable import YakKit

private let instanceURL = URL(string: "https://yak.example.com")!

private func fixtureNames(prefix: String) throws -> [String] {
    var directory = URL(fileURLWithPath: #filePath).deletingLastPathComponent()
    while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
    let folder = directory.appendingPathComponent("fixtures/embed-bridge")
    return try FileManager.default.contentsOfDirectory(atPath: folder.path)
        .filter { $0.hasPrefix(prefix) && $0.hasSuffix(".json") }.map { String($0.dropLast(5)) }.sorted()
}

private func page(_ json: String) throws -> EmbedPageMessage {
    try EmbedPageMessage.decode(json: Data(json.utf8), instanceURL: instanceURL)
}

private func canonical(_ object: Any) throws -> String {
    String(decoding: try JSONSerialization.data(withJSONObject: object, options: [.sortedKeys]), as: UTF8.self)
}

// MARK: Fixtures

private func typeName(of message: EmbedPageMessage) -> String {
    switch message {
    case .ready: "ready"
    case .threadChanged: "threadChanged"
    case .link: "link"
    case .openPane: "openPane"
    case .quickLook: "quickLook"
    case .cardAnswered: "cardAnswered"
    case .unsentAction: "unsentAction"
    case .authExpired: "auth"
    case .error: "error"
    case .unknown(let type): "unknown:\(type)"
    }
}

/// Exact values for every page fixture; a fixture missing here is checked for its case only.
private let knownPageFixtures: [String: EmbedPageMessage] = [
    "page-ready": .ready(
        threadID: "d0e515ef", path: "/app/embed/threads/d0e515ef", latestSequence: 42,
        title: "Add a retry to the import job"),
    "page-threadChanged": .threadChanged(threadID: "a1b2c3d4", previousThreadID: "d0e515ef"),
    "page-link": .link(URL(string: "https://yak.example.com/threads/a1b2c3d4")!),
    "page-openPane": .openPane(.files(mode: .changed, path: "app/Jobs/ImportJob.php")),
    "page-openPane-files-everything": .openPane(.files(mode: .everything, path: "README.md")),
    "page-openPane-overview": .openPane(.overview(section: .subthreads)),
    "page-openPane-preview": .openPane(.preview(url: URL(string: "https://preview.example.com/")!)),
    "page-openPane-terminal": .openPane(.terminal),
    "page-quickLook": .quickLook(
        url: URL(string: "https://yak.example.com/shared-files/9/raw")!, name: "results.csv", mimeType: "text/csv"),
    "page-cardAnswered": .cardAnswered(card: .question, action: "answer", isOK: true),
    "page-unsentAction": .unsentAction(key: UUID(uuidString: "6f1c2f9e-3b1d-4c55-9a53-0d4b5c8f7a10")!, action: .retry),
    "page-auth": .authExpired,
    "page-error": .error(kind: .http, status: 503),
    "page-cardAnswered-failed": .cardAnswered(card: .question, action: "answer", isOK: false),
    "page-error-network": .error(kind: .network, status: nil),
    "page-error-script": .error(kind: .script, status: nil),
    "page-quickLook-without-mime": .quickLook(
        url: URL(string: "https://yak.example.com/threads/d0e515ef/attachments/4")!, name: "notes.zip", mimeType: nil),
    "page-openPane-files-without-path": .openPane(.files(mode: .everything, path: nil)),
    "page-openPane-overview-without-section": .openPane(.overview(section: nil)),
    "page-openPane-preview-without-url": .openPane(.preview(url: nil)),
]

@Test func everyPageFixtureInTheFolderDecodesToItsCase() throws {
    let names = try fixtureNames(prefix: "page-")
    #expect(!names.isEmpty)
    for name in names {
        let object = try Fixtures.object("embed-bridge/\(name)")
        let type = try #require((object as? [String: Any])?["type"] as? String, "\(name) has no type")
        let message = try EmbedPageMessage.decode(object, instanceURL: instanceURL)
        #expect(typeName(of: message) == type, "\(name) decoded to \(message)")
        if let expected = knownPageFixtures[name] { #expect(message == expected, "\(name)") }
    }
}

@Test func everyFixtureFileIsAPageOrAnAppMessage() throws {
    let all = try fixtureNames(prefix: "")
    #expect(all.allSatisfy { $0.hasPrefix("page-") || $0.hasPrefix("app-") }, "A fixture cannot be classified: \(all)")
}

private func appMessage(from object: Any) throws -> EmbedAppMessage {
    let fields = try #require(object as? [String: Any])
    let formatter = ISO8601DateFormatter()
    switch fields["type"] as? String {
    case "open":
        return .open(threadID: try #require(fields["threadId"] as? String), fragment: fields["fragment"] as? String)
    case "focus": return .focus(fragment: try #require(fields["fragment"] as? String))
    case "scrollToBottom": return .scrollToBottom
    case "refresh": return .refresh
    case "connectivity": return .connectivity(isOnline: try #require(fields["online"] as? Bool))
    case "outbox":
        let list = try #require(fields["items"] as? [[String: Any]])
        let items = try list.map { entry -> EmbedOutboxItem in
            let statusText = try #require(entry["status"] as? String)
            let createdText = try #require(entry["createdAt"] as? String)
            return EmbedOutboxItem(
                key: try #require(entry["key"] as? String), text: try #require(entry["text"] as? String),
                status: try #require(EmbedOutboxItem.Status(rawValue: statusText)),
                reason: entry["reason"] as? String,
                createdAt: try #require(formatter.date(from: createdText)),
                attachmentCount: try #require(entry["attachmentCount"] as? Int))
        }
        return .outbox(threadID: try #require(fields["threadId"] as? String), items: items)
    default:
        Issue.record("An app fixture has a type this test cannot build: \(String(describing: fields["type"]))")
        throw EmbedProjectionFailure()
    }
}

private struct EmbedProjectionFailure: Error {}

@Test func everyAppFixtureInTheFolderEqualsWhatTheEncoderProduces() throws {
    let names = try fixtureNames(prefix: "app-")
    #expect(!names.isEmpty)
    for name in names {
        let fixture = try Fixtures.object("embed-bridge/\(name)")
        let produced = try #require(try appMessage(from: fixture).dictionary(), "\(name)")
        #expect(try canonical(produced) == canonical(fixture), "\(name)")
    }
}

// MARK: Page messages

@Test func anUnknownTypeIsIgnored() throws {
    #expect(try page(#"{"v":1,"type":"somethingNew","x":1}"#) == .unknown(type: "somethingNew"))
}

@Test func anUnknownVariantOfAKnownTypeIsIgnored() throws {
    #expect(try page(#"{"v":1,"type":"openPane","pane":"map"}"#) == .unknown(type: "openPane"))
    #expect(
        try page(#"{"v":1,"type":"cardAnswered","card":"new_card","action":"x","ok":true}"#)
            == .unknown(type: "cardAnswered"))
    #expect(try page(#"{"v":1,"type":"auth","state":"locked"}"#) == .unknown(type: "auth"))
    #expect(try page(#"{"v":1,"type":"error","kind":"quota"}"#) == .unknown(type: "error"))
    #expect(
        try page(#"{"v":1,"type":"unsentAction","key":"6f1c2f9e-3b1d-4c55-9a53-0d4b5c8f7a10","action":"mute"}"#)
            == .unknown(type: "unsentAction"))
}

@Test func anExtraFieldIsIgnored() throws {
    #expect(try page(#"{"v":1,"type":"auth","state":"expired","later":[1]}"#) == .authExpired)
}

@Test(arguments: [
    #"{"type":"auth","state":"expired"}"#, #"{"v":2,"type":"auth","state":"expired"}"#,
    #"{"v":"1","type":"auth","state":"expired"}"#, #"{"v":1.5,"type":"auth","state":"expired"}"#,
])
func aWrongVersionIsRefused(json: String) {
    #expect(throws: EmbedMessageProblem.unsupportedVersion) { try page(json) }
}

@Test(arguments: [#"{"v":1}"#, #"{"v":1,"type":""}"#, #"{"v":1,"type":5}"#, #"{"v":1,"type":null}"#])
func aMissingTypeIsRefused(json: String) {
    #expect(throws: EmbedMessageProblem.missingType) { try page(json) }
}

@Test(arguments: ["[]", "5", "null", "\"text\"", "not json", ""])
func aValueThatIsNotAnObjectIsRefused(json: String) {
    #expect(throws: EmbedMessageProblem.notAnObject) { try page(json) }
}

@Test func aBodyThatIsNotADictionaryIsRefused() {
    #expect(throws: EmbedMessageProblem.notAnObject) {
        try EmbedPageMessage.decode(["v", 1], instanceURL: instanceURL)
    }
    #expect(throws: EmbedMessageProblem.notAnObject) {
        try EmbedPageMessage.decode(["v": Double.nan, "type": "ready"] as [String: Any], instanceURL: instanceURL)
    }
    #expect(throws: EmbedMessageProblem.notAnObject) { try EmbedPageMessage.decode("ready", instanceURL: instanceURL) }
}

@Test func aDictionaryFromTheWebViewDecodes() throws {
    let body: [String: Any] = ["v": 1, "type": "cardAnswered", "card": "co_owner", "action": "accept", "ok": false]
    #expect(
        try EmbedPageMessage.decode(body, instanceURL: instanceURL)
            == .cardAnswered(card: .coOwner, action: "accept", isOK: false))
}

@Test func aHugeMessageIsRefusedBeforeParsing() {
    let title = String(repeating: "x", count: EmbedPageMessage.maximumBytes)
    #expect(throws: EmbedMessageProblem.tooLarge) {
        try page(#"{"v":1,"type":"ready","threadId":"d0e515ef","url":"/a","latestSequence":1,"title":"\#(title)"}"#)
    }
}

@Test(arguments: [
    #"{"v":1,"type":"ready","threadId":"d0e515ef","url":"/a","latestSequence":"1","title":"t"}"#,
    #"{"v":1,"type":"ready","threadId":"d0e515ef","url":"/a","latestSequence":-1,"title":"t"}"#,
    #"{"v":1,"type":"ready","threadId":"d0e515ef","url":"/a","title":"t"}"#,
    #"{"v":1,"type":"ready","threadId":"short","url":"/a","latestSequence":1,"title":"t"}"#,
    #"{"v":1,"type":"ready","threadId":"d0e515ef","url":"https://evil.test/","latestSequence":1,"title":"t"}"#,
    #"{"v":1,"type":"ready","threadId":"d0e515ef","url":"//evil.test/","latestSequence":1,"title":"t"}"#,
    #"{"v":1,"type":"threadChanged","threadId":"../../ab"}"#,
    #"{"v":1,"type":"threadChanged","threadId":"d0e515ef","previousThreadId":"bad"}"#,
    #"{"v":1,"type":"link"}"#, #"{"v":1,"type":"link","url":5}"#,
    #"{"v":1,"type":"openPane"}"#,
    #"{"v":1,"type":"openPane","pane":"files"}"#,
    #"{"v":1,"type":"openPane","pane":"files","mode":"all"}"#,
    #"{"v":1,"type":"openPane","pane":"files","mode":"changed","path":""}"#,
    #"{"v":1,"type":"openPane","pane":"preview","url":"javascript:alert(1)"}"#,
    #"{"v":1,"type":"openPane","pane":"preview","url":"http://preview.example.com/"}"#,
    #"{"v":1,"type":"openPane","pane":"overview","section":"nope"}"#,
    #"{"v":1,"type":"quickLook","url":"https://yak.example.com/f"}"#,
    #"{"v":1,"type":"quickLook","url":"https://yak.example.com/f","name":""}"#,
    #"{"v":1,"type":"quickLook","url":"https://yak.example.com/f","name":".."}"#,
    #"{"v":1,"type":"cardAnswered","card":"question","action":"","ok":true}"#,
    #"{"v":1,"type":"cardAnswered","card":"question","action":"answer","ok":"yes"}"#,
    #"{"v":1,"type":"cardAnswered","card":"question","action":"answer","ok":1}"#,
    #"{"v":1,"type":"unsentAction","key":"not-a-uuid","action":"retry"}"#,
    #"{"v":1,"type":"auth"}"#,
    #"{"v":1,"type":"error","kind":"http","status":99}"#,
    #"{"v":1,"type":"error","kind":"http","status":"503"}"#,
])
func aMalformedPayloadIsRefused(json: String) throws {
    let type = try #require(
        (try JSONSerialization.jsonObject(with: Data(json.utf8)) as? [String: Any])?["type"] as? String)
    #expect(throws: EmbedMessageProblem.malformed(type: type)) { try page(json) }
}

@Test(arguments: [
    "http://yak.example.com/a", "javascript:alert(1)", "file:///etc/passwd", "https://user:pw@yak.example.com/",
    "/relative", "data:text/html,x", "",
])
func aLinkGoesThroughTheLinkRule(url: String) {
    let escaped = url.replacingOccurrences(of: "\"", with: "\\\"")
    #expect(throws: EmbedMessageProblem.malformed(type: "link")) {
        try page(#"{"v":1,"type":"link","url":"\#(escaped)"}"#)
    }
}

@Test func aLinkLongerThanTheLimitIsRefused() {
    let url = "https://yak.example.com/" + String(repeating: "a", count: EmbedPageMessage.maximumURLLength)
    #expect(throws: EmbedMessageProblem.malformed(type: "link")) { try page(#"{"v":1,"type":"link","url":"\#(url)"}"#) }
}

@Test(arguments: [
    "https://other.example.com/f", "https://yak.example.com.evil.test/f", "https://yak.example.com:8443/f",
    "http://yak.example.com/f", "https://user@yak.example.com/f", "/f", "blob:https://yak.example.com/x",
])
func aQuickLookOffTheInstanceOriginIsRefused(url: String) {
    #expect(throws: EmbedMessageProblem.malformed(type: "quickLook")) {
        try page(#"{"v":1,"type":"quickLook","url":"\#(url)","name":"a.csv"}"#)
    }
}

@Test func aQuickLookNameIsMadeSafeAsAFileName() throws {
    let message = try page(#"{"v":1,"type":"quickLook","url":"https://yak.example.com/f","name":"../a\/b.csv"}"#)
    #expect(message == .quickLook(url: URL(string: "https://yak.example.com/f")!, name: ".._a_b.csv", mimeType: nil))
}

private func quickLookName(_ name: String) throws -> String {
    let message = try page(#"{"v":1,"type":"quickLook","url":"https://yak.example.com/f","name":"\#(name)"}"#)
    guard case .quickLook(_, let safe, _) = message else { throw EmbedMessageProblem.malformed(type: "quickLook") }
    return safe
}

@Test func aQuickLookNameOverTheLimitOfThePageIsRefused() {
    let name = String(repeating: "n", count: 501)
    #expect(throws: EmbedMessageProblem.malformed(type: "quickLook")) { try quickLookName(name) }
}

@Test func aLongNameIsShortenedToTheByteLimitAtACharacterBoundaryKeepingItsExtension() throws {
    let cjk = String(repeating: "日本語", count: 100) + ".pdf"
    let safe = try quickLookName(cjk)
    #expect(safe.utf8.count <= EmbedPageMessage.maximumNameLength && safe.hasSuffix(".pdf"))
    #expect(safe.hasPrefix("日本語日本語") && safe.dropLast(4).allSatisfy { "日本語".contains($0) })
    let plain = try quickLookName(String(repeating: "n", count: 400))
    #expect(plain.utf8.count == EmbedPageMessage.maximumNameLength)
}

@Test func aRightToLeftOverrideInANameBecomesAnUnderscore() throws {
    #expect(try quickLookName("report\u{202E}fdp.exe") == "report_fdp.exe")
    #expect(try quickLookName("a\u{200B}b") == "a_b")
}

@Test func aBadMimeTypeIsDroppedButTheMessageStays() throws {
    let message = try page(
        #"{"v":1,"type":"quickLook","url":"https://yak.example.com/f","name":"a","mime":"text csv"}"#)
    #expect(message == .quickLook(url: URL(string: "https://yak.example.com/f")!, name: "a", mimeType: nil))
    let upper = try page(#"{"v":1,"type":"quickLook","url":"https://yak.example.com/f","name":"a","mime":"Text/CSV"}"#)
    #expect(upper == .quickLook(url: URL(string: "https://yak.example.com/f")!, name: "a", mimeType: "text/csv"))
}

@Test func loopbackIsAcceptedOnlyWhenTheInstanceIsLoopbackAndAllowed() throws {
    let local = URL(string: "http://127.0.0.1:8080")!
    let json = Data(#"{"v":1,"type":"quickLook","url":"http://127.0.0.1:8080/f","name":"a"}"#.utf8)
    #expect(throws: EmbedMessageProblem.malformed(type: "quickLook")) {
        try EmbedPageMessage.decode(json: json, instanceURL: local)
    }
    guard case .quickLook = try EmbedPageMessage.decode(json: json, instanceURL: local, allowsLoopbackHTTP: true) else {
        Issue.record("loopback was refused")
        return
    }
}

@Test func aLongTitleIsCutAndTheNewestSequenceKept() throws {
    let title = String(repeating: "t", count: 1000)
    let message = try page(
        #"{"v":1,"type":"ready","threadId":"d0e515ef","url":"/app/embed/threads/d0e515ef","latestSequence":7,"title":"\#(title)"}"#
    )
    guard case .ready(_, _, let sequence, let cut) = message else {
        Issue.record("not ready")
        return
    }
    #expect(sequence == 7 && cut.count == EmbedPageMessage.maximumTitleLength)
}

@Test func aNullPreviousThreadIsAccepted() throws {
    #expect(
        try page(#"{"v":1,"type":"threadChanged","threadId":"d0e515ef","previousThreadId":null}"#)
            == .threadChanged(threadID: "d0e515ef", previousThreadID: nil))
}

@Test func aPreviewPaneWithoutAnAddressIsFine() throws {
    #expect(try page(#"{"v":1,"type":"openPane","pane":"preview"}"#) == .openPane(.preview(url: nil)))
    #expect(try page(#"{"v":1,"type":"openPane","pane":"overview"}"#) == .openPane(.overview(section: nil)))
}

// MARK: App messages

@Test func anOpenMessageForABadThreadOrFragmentIsNotEncoded() {
    #expect(EmbedAppMessage.open(threadID: "d0e515ef", fragment: nil).dictionary() != nil)
    #expect(EmbedAppMessage.open(threadID: "../x", fragment: nil).dictionary() == nil)
    #expect(EmbedAppMessage.open(threadID: "d0e515ef", fragment: "a b").dictionary() == nil)
    #expect(EmbedAppMessage.open(threadID: "d0e515ef", fragment: "x\"; alert(1)").dictionary() == nil)
    #expect(EmbedAppMessage.focus(fragment: "").dictionary() == nil)
    #expect(EmbedAppMessage.focus(fragment: String(repeating: "a", count: 65)).dictionary() == nil)
    #expect(EmbedAppMessage.outbox(threadID: "x", items: []).dictionary() == nil)
}

@Test func anOpenMessageWithoutAFragmentHasNoFragmentKey() throws {
    let dictionary = try #require(EmbedAppMessage.open(threadID: "d0e515ef", fragment: nil).dictionary())
    #expect(dictionary["fragment"] == nil && dictionary["v"] as? Int == 1)
}

@Test func theEncodedDictionaryIsJSONSafe() throws {
    let item = EmbedOutboxItem(
        key: "k", text: "Line\n\"quoted\" </script>", status: .queued, reason: nil,
        createdAt: Date(timeIntervalSince1970: 0),
        attachmentCount: 2)
    let dictionary = try #require(EmbedAppMessage.outbox(threadID: "d0e515ef", items: [item]).dictionary())
    #expect(JSONSerialization.isValidJSONObject(dictionary))
}

@Test func optionalFieldsMayBeAbsent() throws {
    let url = URL(string: "https://yak.example.com/f")!
    #expect(
        try page(#"{"v":1,"type":"quickLook","url":"https://yak.example.com/f","name":"a"}"#)
            == .quickLook(url: url, name: "a", mimeType: nil))
    #expect(try page(#"{"v":1,"type":"error","kind":"network"}"#) == .error(kind: .network, status: nil))
    #expect(try page(#"{"v":1,"type":"error","kind":"script"}"#) == .error(kind: .script, status: nil))
    #expect(
        try page(#"{"v":1,"type":"openPane","pane":"files","mode":"changed"}"#)
            == .openPane(.files(mode: .changed, path: nil)))
    #expect(
        try page(#"{"v":1,"type":"threadChanged","threadId":"d0e515ef"}"#)
            == .threadChanged(threadID: "d0e515ef", previousThreadID: nil))
    #expect(
        try page(#"{"v":1,"type":"cardAnswered","card":"stopped","action":"restart","ok":false}"#)
            == .cardAnswered(card: .stopped, action: "restart", isOK: false))
}

private func outboxItems(_ message: EmbedAppMessage) throws -> [[String: Any]] {
    let dictionary = try #require(message.dictionary())
    return try #require(dictionary["items"] as? [[String: Any]])
}

private func outboxItem(key: String = "k", text: String = "t", reason: String? = nil) -> EmbedOutboxItem {
    EmbedOutboxItem(
        key: key, text: text, status: .refused, reason: reason, createdAt: Date(timeIntervalSince1970: 0),
        attachmentCount: 0)
}

@Test func anOutboxOverTheCountIsCutToTheFirstHundred() throws {
    let items = (0..<150).map { outboxItem(key: "k\($0)") }
    let sent = try outboxItems(.outbox(threadID: "d0e515ef", items: items))
    #expect(sent.count == 100 && sent.first?["key"] as? String == "k0" && sent.last?["key"] as? String == "k99")
}

@Test func outboxTextAndReasonAreCutAtACharacterBoundary() throws {
    let family = "👨‍👩‍👧"
    let text = String(repeating: "a", count: 99_999) + family
    let reason = String(repeating: "b", count: 999) + family
    let sent = try outboxItems(.outbox(threadID: "d0e515ef", items: [outboxItem(text: text, reason: reason)]))
    let sentText = try #require(sent[0]["text"] as? String)
    let sentReason = try #require(sent[0]["reason"] as? String)
    #expect(sentText == String(repeating: "a", count: 99_999) && sentText.utf16.count <= 100_000)
    #expect(sentReason == String(repeating: "b", count: 999) && sentReason.utf16.count <= 1_000)
    let exact = String(repeating: "c", count: 100_000)
    #expect(
        try outboxItems(.outbox(threadID: "d0e515ef", items: [outboxItem(text: exact)]))[0]["text"] as? String == exact)
}

@Test func anOutboxItemWithAKeyOverTheCapIsDropped() throws {
    let long = String(repeating: "k", count: 101)
    let exact = String(repeating: "k", count: 100)
    let sent = try outboxItems(
        .outbox(threadID: "d0e515ef", items: [outboxItem(key: long), outboxItem(key: exact), outboxItem(key: "")]))
    #expect(sent.count == 1 && sent[0]["key"] as? String == exact)
}
