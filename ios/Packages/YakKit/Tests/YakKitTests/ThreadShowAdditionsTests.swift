import Foundation
import Testing

@testable import YakKit

@Test func aShowWithoutTheNewFieldsDecodesWithDefaults() throws {
    let show = try decoded(ThreadShow.self, "scenario-thread-show")
    #expect(show.canOpenPullRequest == false && show.diffTotals == nil && show.pullRequest == nil)
    #expect(show.publishWarning == false && show.thread.pullRequestOpening == nil)
}

@Test func aShowWithChangesDecodesThem() throws {
    let show = try decoded(ThreadShow.self, "scenario-thread-with-changes")
    #expect(show.canOpenPullRequest && show.diffTotals == DiffTotals(additions: 11, deletions: 4, filesCount: 4))
    let open = try decoded(ThreadShow.self, "scenario-thread-with-pull-request")
    #expect(open.pullRequest?.number == 41 && open.thread.pullRequestCount == 1 && !open.canOpenPullRequest)
}

@Test func diffTotalsWithoutAFilesCountDecodeAsZero() throws {
    let totals = try JSONDecoder().decode(DiffTotals.self, from: Data(#"{"additions":2,"deletions":1}"#.utf8))
    #expect(totals == DiffTotals(additions: 2, deletions: 1, filesCount: 0))
}

@Test func anUnknownOpeningStateIsKept() throws {
    let data = try Scenarios.data(
        "scenario-thread-with-changes", setting: ["thread", "pullRequestOpening"],
        toJSON: #"{"state":"queued","error":null,"at":null}"#)
    let show = try JSONDecoder().decode(ThreadShow.self, from: data)
    #expect(show.thread.pullRequestOpening?.state.rawValue == "queued")
    let failed = try Scenarios.data(
        "scenario-thread-with-changes", setting: ["thread", "pullRequestOpening"],
        toJSON: #"{"state":"failed","error":"Yak could not reach GitHub.","at":"2026-11-15T09:00:00Z"}"#)
    let failedShow = try JSONDecoder().decode(ThreadShow.self, from: failed)
    #expect(failedShow.thread.pullRequestOpening?.state == .failed)
    #expect(failedShow.thread.pullRequestOpening?.error == "Yak could not reach GitHub.")
}

@Test func aWronglyTypedNewFieldFailsTheDecode() throws {
    let data = try Scenarios.data(
        "scenario-thread-with-changes", setting: ["canOpenPullRequest"], toJSON: #""yes""#)
    #expect(throws: DecodingError.self) { try JSONDecoder().decode(ThreadShow.self, from: data) }
}

@Test func theNewFieldsSurviveTheCache() async throws {
    let cache = Cache(instance: UUID(), files: InMemoryFileStore(), clock: ManualClock())
    let show = try decoded(ThreadShow.self, "scenario-thread-with-pull-request")
    await cache.saveThread(
        "d0e515ef", CachedThread(show: show, events: [], lastSequence: 14, firstLoadedAfter: 0))
    let restored = try #require(await cache.thread("d0e515ef"))
    #expect(restored.show == show)
    #expect(restored.show.diffTotals?.filesCount == 4 && restored.show.pullRequest?.number == 41)
}

/// The hand-written thread show with top-level keys replaced; values are JSON text.
func workShowData(_ keys: [String: String] = [:], messages: String? = nil) throws -> Data {
    var object = try #require(
        JSONSerialization.jsonObject(with: Scenarios.data("scenario-thread-show")) as? [String: Any])
    for (key, json) in keys {
        object[key] = try JSONSerialization.jsonObject(with: Data(json.utf8), options: .fragmentsAllowed)
    }
    if let messages {
        object["messages"] = try JSONSerialization.jsonObject(with: Data(messages.utf8))
    }
    return try JSONSerialization.data(withJSONObject: object)
}

func workShow(_ keys: [String: String] = [:], messages: String? = nil) throws -> ThreadShow {
    try JSONDecoder().decode(ThreadShow.self, from: workShowData(keys, messages: messages))
}

@Test func aShowWithoutTheWorkFieldsDecodesWithDefaults() throws {
    let show = try decoded(ThreadShow.self, "scenario-thread-show")
    #expect(show.request == nil && show.progress == nil && show.card == nil && show.stopped == nil)
    #expect(show.milestones.isEmpty && show.legacyAnswers.isEmpty && !show.isLegacy)
    #expect(show.actions == .none && show.thread.hasRepository)
    #expect(show.repositoryQuestion == nil && show.earlyWarning == nil && show.coOwner == nil)
    let message = try #require(show.messages.first)
    #expect(message.via == nil && !message.isReply && !message.isRetry && message.retryNumber == nil)
    #expect(message.canEditText)
}

@Test func anUnknownCardIsKept() throws {
    let show = try workShow(["card": #""hologram""#])
    #expect(show.card == ThreadCard(rawValue: "hologram"))
    #expect(try workShow(["card": #""repository_question""#]).card == .repositoryQuestion)
}

@Test func progressStepsAreFoundByKeyNotPosition() throws {
    func steps(_ keys: [String]) -> String {
        let rows = keys.enumerated().map { index, key in
            let state = index == 2 ? "current" : (index < 2 ? "done" : "upcoming")
            return #"{"key":"\#(key)","label":"L \#(key)","tooltip":"T","state":"\#(state)"}"#
        }
        return #"{"steps":[\#(rows.joined(separator: ","))]}"#
    }
    let seven = try workShow([
        "progress": steps(["received", "picked_up", "working", "pushed", "ci", "pull_request", "done"])
    ])
    let eight = try workShow([
        "progress": steps(["received", "picked_up", "working", "pushed", "ci", "co_owner", "pull_request", "done"])
    ])
    #expect(seven.progress?.steps.count == 7 && eight.progress?.steps.count == 8)
    #expect(seven.progress?.step(.coOwner) == nil && eight.progress?.step(.coOwner)?.label == "L co_owner")
    #expect(seven.progress?.step(.pullRequest)?.state == .upcoming)
    #expect(eight.progress?.step(.pullRequest)?.state == .upcoming)
    #expect(eight.progress?.attentionStep?.key == .working)
}

@Test func aFailedStepWinsOverTheCurrentOne() throws {
    let show = try workShow([
        "progress":
            #"{"steps":[{"key":"working","label":"Working","tooltip":"","state":"current"},{"key":"ci","label":"CI failed","tooltip":"","state":"failed"}]}"#
    ])
    #expect(show.progress?.attentionStep?.label == "CI failed")
}

@Test func milestonesDecodeWithAStringRunID() throws {
    let show = try workShow([
        "milestones":
            #"[{"key":"pushed","label":"Pushed","tone":"ok","url":null,"runId":"42","afterSequence":7},{"key":"pull_request_opened","label":"Pull request opened","tone":"ok","url":"https://example.com/pull/1","runId":"legacy-task-id","afterSequence":0},{"key":"new_kind","label":"New","tone":"sparkly","url":null,"runId":"1","afterSequence":1}]"#
    ])
    #expect(show.milestones.map(\.runId) == ["42", "legacy-task-id", "1"])
    #expect(show.milestones[0].key == .pushed && show.milestones[0].afterSequence == 7)
    #expect(show.milestones[1].url == "https://example.com/pull/1")
    #expect(show.milestones[2].key.rawValue == "new_kind" && show.milestones[2].tone.rawValue == "sparkly")
}

@Test func aLegacyShowDecodesItsAnswers() throws {
    let show = try workShow([
        "isLegacy": "true",
        "legacyAnswers": #"[{"runTaskId":"old-1","html":"<p>Done</p>","at":"2026-11-10T09:00:00Z"}]"#,
        "composer": try composerJSON(state: "legacy"),
    ])
    #expect(
        show.isLegacy
            && show.legacyAnswers == [LegacyAnswer(runTaskId: "old-1", html: "<p>Done</p>", at: "2026-11-10T09:00:00Z")]
    )
    #expect(show.composer.state == .legacy)
}

private func composerJSON(state: String) throws -> String {
    var object = try #require(
        JSONSerialization.jsonObject(with: Scenarios.data("scenario-thread-show")) as? [String: Any])
    var composer = try #require(object["composer"] as? [String: Any])
    composer["state"] = state
    object["composer"] = composer
    return String(decoding: try JSONSerialization.data(withJSONObject: composer), as: UTF8.self)
}

@Test func aMovedNoticeCarriesTheNextThread() throws {
    let show = try workShow([
        "notice":
            #"{"kind":"moved","title":"Moved to example/web","body":"This request continues in a new thread.","canContinue":false,"repo":"example/web","threadId":"abc123","threadTitle":"Next"}"#
    ])
    let notice = try #require(show.notice)
    #expect(notice.kind == "moved" && notice.repo == "example/web" && notice.threadId == "abc123")
    #expect(notice.threadTitle == "Next" && !notice.canContinue)
    let hidden = try workShow([
        "notice":
            #"{"kind":"moved","title":"Moved","body":"B","canContinue":false,"repo":null,"threadId":null,"threadTitle":null}"#
    ])
    #expect(hidden.notice?.threadId == nil)
}

@Test func aRetryMessageHasNoText() throws {
    let show = try workShow(
        messages:
            #"[{"id":"turn-message-9","uuid":null,"text":"","authorName":"Yak","authorInitials":"Y","state":"read","sentAt":"2026-11-15T09:00:00Z","readAfterSequence":null,"bodyHtml":"","attachments":[],"isRetry":true,"retryNumber":2,"canEditText":false}]"#
    )
    let message = try #require(show.messages.first)
    #expect(message.isRetry && message.retryNumber == 2 && message.text.isEmpty && !message.canEditText)
}

@Test func aChannelMessageCarriesVia() throws {
    let show = try workShow(
        messages:
            ##"[{"id":"m1","uuid":"u1","text":"Hi","authorName":"Test person","authorInitials":"TP","state":"read","sentAt":"2026-11-15T09:00:00Z","readAfterSequence":1,"bodyHtml":"<p>Hi</p>","via":{"key":"slack","label":"Slack","reference":"#general","url":"https://example.com/archives/1"},"isReply":true}]"##
    )
    let message = try #require(show.messages.first)
    #expect(message.via?.key == .slack && message.via?.reference == "#general" && message.isReply)
}

@Test func anUnknownStopKindIsKept() throws {
    let show = try workShow([
        "card": #""stopped""#,
        "stopped": #"{"kind":"on_fire","summary":"S","detail":null,"nextStep":"N","isWaitingForSetup":true}"#,
    ])
    #expect(
        show.stopped?.kind.rawValue == "on_fire" && show.stopped?.detail == nil
            && show.stopped?.isWaitingForSetup == true)
}

@Test func theRequestAndTheOtherCardsDecode() throws {
    let show = try workShow([
        "request":
            #"{"source":{"key":"linear","label":"Linear","reference":"ENG-1","url":null},"starter":{"key":"yak","name":"Yak","avatarUrl":null},"responsibleName":null,"status":{"key":"working","label":"Working","tone":"info"}}"#,
        "repositoryQuestion":
            #"{"prompt":"Which repository?","options":["example/api","example/web"],"selected":null,"canAnswer":true}"#,
        "earlyWarning":
            #"{"areas":[{"name":"Middleware","risk":"high"}],"state":"shown","taggedName":null,"fallbackName":"Test person","repoName":"example/api","canPick":true}"#,
        "coOwner":
            #"{"id":3,"status":"pending","decidedBy":null,"feedback":null,"prUrl":null,"holdReasons":[],"asked":["Test person"],"stat":null,"areas":[{"name":"A","risk":"high","files":[{"path":"app/One.php","status":"modified"},{"path":"app/Two.php","status":"added"}]},{"name":"B","risk":"low"}],"viewer":{"isRequester":false,"isLinked":true,"login":null,"access":"write"},"connectUrl":"https://yak.example.com/connect","requesterName":"Test person","canDecide":true,"viewerIsAsked":true}"#,
        "actions":
            #"{"canStop":true,"canRetry":false,"canCancel":true,"canMove":true,"moveTargets":["example/web"],"canRequestReview":false,"canRetryRender":false}"#,
    ])
    #expect(show.request?.source.key == .linear && show.request?.status.tone == .info)
    #expect(show.request?.starter.name == "Yak" && show.request?.responsibleName == nil)
    #expect(show.repositoryQuestion?.options.count == 2 && show.repositoryQuestion?.canAnswer == true)
    #expect(show.earlyWarning?.areas == [EarlyWarningArea(name: "Middleware", risk: "high")])
    #expect(show.coOwner?.canDecide == true && show.coOwner?.viewerIsAsked == true)
    #expect(show.coOwner?.filePaths == ["app/One.php", "app/Two.php"])
    #expect(show.actions.canMove && show.actions.moveTargets == ["example/web"] && show.actions.canStop)
}

@Test func theThreadWithoutARepositoryIsMarked() throws {
    var object = try #require(
        JSONSerialization.jsonObject(with: Scenarios.data("scenario-thread-show")) as? [String: Any])
    var thread = try #require(object["thread"] as? [String: Any])
    thread["hasRepository"] = false
    thread["repo"] = "unknown"
    object["thread"] = thread
    let show = try JSONDecoder().decode(ThreadShow.self, from: JSONSerialization.data(withJSONObject: object))
    #expect(!show.thread.hasRepository)
}

@Test func aThreadEventCarriesAShowKeyOnlySometimes() throws {
    let withKey = try JSONDecoder().decode(
        ThreadStateChange.self,
        from: Data(#"{"status":"open","sandboxState":"ready","runnerState":"idle","showKey":"abcdef123456"}"#.utf8))
    #expect(withKey.showKey == "abcdef123456")
    let without = try JSONDecoder().decode(
        ThreadStateChange.self, from: Data(#"{"status":"open","sandboxState":"ready","runnerState":"idle"}"#.utf8))
    #expect(without.showKey == nil)
}

@Test func theSendAnswerCarriesANotice() throws {
    let answer = try JSONDecoder().decode(
        MessageSendResult.self,
        from: Data(#"{"status":"sent","uuid":null,"notice":"Trying again with your note."}"#.utf8))
    #expect(answer.uuid == nil && answer.notice == "Trying again with your note." && answer.status == .sent)
}

@Test(arguments: [
    ("card", "5"),
    ("actions", #"{"canRetry":"yes"}"#),
    ("milestones", #"[{"key":"pushed","label":"P","tone":"ok","runId":"1","afterSequence":"7"}]"#),
    ("isLegacy", #""no""#),
    ("progress", #"{"steps":[{"key":"working","label":"W","tooltip":"","state":5}]}"#),
])
func aWronglyTypedWorkFieldFailsTheDecode(key: String, replacement: String) throws {
    let data = try workShowData([key: replacement])
    #expect(throws: DecodingError.self) { try JSONDecoder().decode(ThreadShow.self, from: data) }
}

private let workScenarioNames = [
    "work-co-owner", "work-co-owner-approved", "work-drained-queued", "work-drained-taken", "work-early-warning",
    "work-early-warning-tagged", "work-expired", "work-finished", "work-legacy", "work-legacy-continued",
    "work-milestones", "work-moved", "work-moved-new", "work-needs-you-stopped", "work-reply-retried",
    "work-repository-answered", "work-repository-question", "work-stopped", "work-via", "work-working",
]

@Test(arguments: workScenarioNames)
func everyWorkScenarioDecodesAndPicksOneCard(name: String) throws {
    let show = try decoded(ThreadShow.self, "scenario-\(name)")
    #expect(show.thread.id.count == 8)
    let state = ThreadCardState.make(show: show, isForbidden: false)
    #expect(show.card == nil ? state == .none : state != .none)
}
