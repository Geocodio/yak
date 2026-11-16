import Foundation
import Testing

@testable import YakKit

private let instanceID = UUID(uuidString: "11111111-1111-1111-1111-111111111111")!
private let key = UUID(uuidString: "22222222-2222-2222-2222-222222222222")!

private func options(_ context: ShareContext) -> NewThreadOptions { context.options! }

@Test func aSharedPageGoesToTheLastThreadWithinTheLimits() throws {
    let context = try decoded(ShareContext.self, "scenario-share-context")
    let images = (1...10).map { ShareInput.image(fileName: "IMG_\($0).jpg") }
    let draft = ShareDraft(
        typed: "  This overlaps on small screens  ",
        inputs: images + [
            .link(URL(string: "https://status.example.com/issue/1")!), .link(URL(string: "javascript:alert(1)")!),
            .text(String(repeating: "a", count: 30_000)),
        ],
        target: .lastThread, form: nil, model: "sonnet", context: context)
    #expect(draft.attachedImages.count == 8)
    #expect(draft.notices == [.tooManyFiles(maximum: 8), .textCut(limit: 20_000), .linksDropped])
    #expect(
        draft.text.hasPrefix("This overlaps on small screens\n\nhttps://status.example.com/issue/1\n\naaaa")
            && draft.text.count == 20_000)
    let item = draft.outboxDraft(sendKey: key) { "instances/\(instanceID)/outbox/files/\(key)/\($0)" }
    #expect(item?.target == .thread("d0e515ef") && item?.attachments.map(\.reference).first == "Image #4")
    #expect(item?.attachments.count == 8 && item?.delivery == .steer && item?.model == "sonnet" && item?.sendKey == key)
    #expect(item?.attachments.first?.path == "instances/\(instanceID)/outbox/files/\(key)/IMG_1.jpg")
}

@Test func moreThanFiveLinksKeepTheFirstFive() throws {
    let context = try decoded(ShareContext.self, "scenario-share-context")
    let links = (1...7).map { ShareInput.link(URL(string: "https://example.com/\($0)")!) }
    let draft = ShareDraft(typed: "", inputs: links, target: .lastThread, form: nil, model: nil, context: context)
    #expect(draft.text.split(separator: "\n").count == 5 && draft.notices == [.linksDropped])
}

@Test func aPrivateLastThreadNeverShowsItsTitle() throws {
    var show = try decoded(ThreadShow.self, "scenario-thread-show")
    #expect(ShareContextStore.lastThread(from: show).title == show.thread.title)
    let privateShow = try privateCopy(of: show)
    let last = ShareContextStore.lastThread(from: privateShow)
    #expect(last.title == nil && last.isPrivate && last.canSend)
    #expect(ShareDraft.lastThreadLabel(last) == "A private thread")
    show = try readOnlyCopy(of: privateShow)
    let readOnly = ShareContextStore.lastThread(from: show)
    #expect(!readOnly.canSend)
    var context = try decoded(ShareContext.self, "scenario-share-context")
    context.lastThread = readOnly
    let draft = ShareDraft(typed: "hi there", inputs: [], target: .lastThread, form: nil, model: nil, context: context)
    #expect(
        draft.problem == .cannotSendToLastThread(title: nil)
            && draft.outboxDraft(sendKey: key, attachmentPath: { $0 }) == nil)
}

private func changedShow(_ show: ThreadShow, _ change: (inout [String: Any]) -> Void) throws -> ThreadShow {
    var object = try #require(JSONSerialization.jsonObject(with: JSONEncoder().encode(show)) as? [String: Any])
    change(&object)
    return try JSONDecoder().decode(ThreadShow.self, from: JSONSerialization.data(withJSONObject: object))
}

private func privateCopy(of show: ThreadShow) throws -> ThreadShow {
    try changedShow(show) {
        var thread = $0["thread"] as? [String: Any] ?? [:]
        thread["visibility"] = "private"
        $0["thread"] = thread
    }
}

private func readOnlyCopy(of show: ThreadShow) throws -> ThreadShow {
    try changedShow(show) { $0["canDrive"] = false }
}

@Test func newThreadProblems() throws {
    let context = try decoded(ShareContext.self, "scenario-share-context")
    func draft(_ text: String, repo: String?, context: ShareContext?) -> ShareDraft {
        let form = NewThreadForm(options: context?.options, saved: nil, lastUsedRepo: repo, linkRepo: nil)
        return ShareDraft(typed: text, inputs: [], target: .newThread, form: form, model: nil, context: context)
    }
    #expect(draft("Fix it please", repo: "example/api", context: nil).problem == .noContext)
    var noRepository = context
    noRepository.options = NewThreadOptions(repos: [], picker: context.options!.picker, models: [])
    #expect(draft("Fix it please", repo: nil, context: noRepository).problem == .noRepository)
    let running = ShareDraft(
        typed: "Fix it please", inputs: [], target: .newThread,
        form: {
            var form = NewThreadForm(options: context.options, saved: nil, lastUsedRepo: nil, linkRepo: nil)
            form.selectRepository("example/web", options: context.options!)
            return form
        }(), model: nil, context: context)
    #expect(running.problem == .repositoryNotReady(slug: "example/web", step: "Installing dependencies"))
    #expect(draft("hi", repo: "example/api", context: context).problem == .textTooShort(minimum: 3))
    let ready = draft("Fix it please", repo: "example/api", context: context)
    #expect(ready.problem == nil)
    let item = ready.outboxDraft(sendKey: key) { $0 }
    if case .newThread(let thread)? = item?.target {
        #expect(thread.repo == "example/api")
    } else {
        Issue.record("no new thread")
    }
}

@Test func lastThreadTargetWithoutOneIsAProblem() throws {
    var context = try decoded(ShareContext.self, "scenario-share-context")
    context.lastThread = nil
    let draft = ShareDraft(typed: "hello", inputs: [], target: .lastThread, form: nil, model: nil, context: context)
    #expect(draft.problem == .noLastThread)
}

@Test func stagingFoldersExpireByName() {
    let now = Date(timeIntervalSince1970: 1_700_000_000)
    let id = UUID().uuidString
    let names = ["1700000000-\(id)", "abc", "-1-x", "99999999999999999999-\(id)"]
    #expect(
        ShareStaging.expired(names, now: now.addingTimeInterval(59 * 60)) == [
            "abc", "-1-x", "99999999999999999999-\(id)",
        ])
    #expect(ShareStaging.expired(names, now: now.addingTimeInterval(61 * 60)) == names)
    #expect(ShareStaging.folder(now: now, id: key) == "share-staging/1700000000-\(key.uuidString)")
}
