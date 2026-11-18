import Foundation
import Testing

@testable import YakKit

private let panelJSON =
    #"{"id":3,"status":"pending","decidedBy":null,"feedback":null,"prUrl":null,"holdReasons":[],"asked":["Second person"],"stat":null,"viewer":{"isRequester":false,"isLinked":true,"login":null,"access":"write"},"connectUrl":"https://yak.example.com/connect","requesterName":"Test person","canDecide":true,"viewerIsAsked":true}"#
private let warningJSON =
    #"{"areas":[{"name":"Middleware","risk":"high"}],"state":"shown","taggedName":null,"fallbackName":"Test person","repoName":"example/api","canPick":true}"#
private let repositoryJSON =
    #"{"prompt":"Which repository?","options":["example/api","example/web"],"selected":null,"canAnswer":true}"#
private let stoppedJSON =
    #"{"kind":"yak_stopped","summary":"Tests kept failing.","detail":null,"nextStep":"Press Retry and Yak will try again.","isWaitingForSetup":false}"#
private let actionsJSON =
    #"{"canStop":false,"canRetry":true,"canCancel":false,"canMove":true,"moveTargets":["example/web"],"canRequestReview":false,"canRetryRender":false}"#

/// A show that holds every card's data at once, so only `card` can decide which one is drawn.
private func show(_ extra: [String: String] = [:]) throws -> ThreadShow {
    var keys = [
        "coOwner": panelJSON, "earlyWarning": warningJSON, "repositoryQuestion": repositoryJSON,
        "stopped": stoppedJSON, "actions": actionsJSON,
    ]
    for (key, value) in extra { keys[key] = value }
    return try workShow(keys)
}

@Suite struct ThreadCardStateTests {
    @Test func onlyTheNamedCardShows() throws {
        func kind(_ card: String) throws -> String {
            switch ThreadCardState.make(show: try show(["card": "\"\(card)\""]), isForbidden: false) {
            case .none: "none"
            case .question: "question"
            case .repositoryQuestion: "repositoryQuestion"
            case .earlyWarning: "earlyWarning"
            case .coOwner: "coOwner"
            case .stopped: "stopped"
            case .unknown: "unknown"
            }
        }
        #expect(try kind("repository_question") == "repositoryQuestion")
        #expect(try kind("early_warning") == "earlyWarning")
        #expect(try kind("co_owner") == "coOwner")
        #expect(try kind("stopped") == "stopped")
        // The question card needs the question itself.
        #expect(try kind("question") == "none")
        #expect(ThreadCardState.make(show: try show(["card": "null"]), isForbidden: false) == .none)
    }

    @Test func aCardWithoutItsDataShowsNothing() throws {
        let bare = try workShow(["card": #""co_owner""#])
        #expect(ThreadCardState.make(show: bare, isForbidden: false) == .none)
        #expect(ThreadCardState.make(show: nil, isForbidden: false) == .none)
    }

    @Test func anOlderServerFallsBackToTheQuestion() throws {
        let withQuestion = try decoded(ThreadShow.self, "scenario-thread-question")
        #expect(withQuestion.card == nil)
        #expect(ThreadCardState.make(show: withQuestion, isForbidden: false) == .question)
        let named = try workShow(["card": #""question""#])
        #expect(ThreadCardState.make(show: named, isForbidden: false) == .none)
        let plain = try decoded(ThreadShow.self, "scenario-thread-show")
        #expect(ThreadCardState.make(show: plain, isForbidden: false) == .none)
    }

    @Test func anUnknownCardShowsNothing() throws {
        let state = ThreadCardState.make(show: try show(["card": #""hologram""#]), isForbidden: false)
        #expect(state == .unknown("hologram"))
        #expect(!state.hasNoButtons)
    }

    @Test func aReaderSeesNoButtons() throws {
        for card in ["repository_question", "early_warning", "co_owner", "stopped"] {
            let reader = try show(["card": "\"\(card)\""])
            var readOnly = reader
            readOnly.canDrive = false
            let state = ThreadCardState.make(show: readOnly, isForbidden: false)
            #expect(state != .none && state.hasNoButtons, "\(card)")
            // The engine's forbidden flag does the same for a person whose page still says they can drive.
            let forbidden = ThreadCardState.make(show: reader, isForbidden: true)
            #expect(forbidden.hasNoButtons, "\(card) forbidden")
            #expect(!ThreadCardState.make(show: reader, isForbidden: false).hasNoButtons, "\(card) driver")
        }
    }

    @Test func theRepositoryQuestionKeepsItsOptions() throws {
        let state = ThreadCardState.make(show: try show(["card": #""repository_question""#]), isForbidden: false)
        guard case .repositoryQuestion(let question, let canAnswer) = state else {
            Issue.record("not the repository question")
            return
        }
        #expect(question.options == ["example/api", "example/web"] && canAnswer)
        let closedToTheServerAnswer = try show([
            "card": #""repository_question""#,
            "repositoryQuestion": #"{"prompt":"P","options":["a"],"selected":null,"canAnswer":false}"#,
        ])
        #expect(ThreadCardState.make(show: closedToTheServerAnswer, isForbidden: false).hasNoButtons)
    }

    @Test func theEarlyWarningFollowsTheServersCanPick() throws {
        let tagged = try show([
            "card": #""early_warning""#,
            "earlyWarning": #"{"areas":[],"state":"tagged","taggedName":"Second person","fallbackName":null,"repoName":"example/api","canPick":false}"#,
        ])
        guard case .earlyWarning(let row, _) = ThreadCardState.make(show: tagged, isForbidden: false) else {
            Issue.record("not the early warning")
            return
        }
        #expect(row == .asked(name: "Second person"))
        guard case .earlyWarning(let pick, let notice) = ThreadCardState.make(
            show: try show(["card": #""early_warning""#]), isForbidden: false)
        else {
            Issue.record("not the early warning")
            return
        }
        #expect(pick == .pick(fallbackName: "Test person") && notice.areas.count == 1)
    }

    @Test func theCoOwnerCardFollowsCanDecide() throws {
        let decide = ThreadCardState.make(show: try show(["card": #""co_owner""#]), isForbidden: false)
        guard case .coOwner(let decision, let panel) = decide else {
            Issue.record("not the co-owner card")
            return
        }
        #expect(decision == .decide && panel.viewerIsAsked == true)
        let cannotDecide = try show([
            "card": #""co_owner""#, "coOwner": panelJSON.replacingOccurrences(of: #""canDecide":true"#, with: #""canDecide":false"#),
        ])
        guard case .coOwner(let blocked, _) = ThreadCardState.make(show: cannotDecide, isForbidden: false) else {
            Issue.record("not the co-owner card")
            return
        }
        #expect(blocked == .none)
        let decided = try show([
            "card": #""co_owner""#, "coOwner": panelJSON.replacingOccurrences(of: #""pending""#, with: #""approved""#),
        ])
        guard case .coOwner(let afterwards, _) = ThreadCardState.make(show: decided, isForbidden: false) else {
            Issue.record("not the co-owner card")
            return
        }
        #expect(afterwards == .decided(status: "approved", by: nil))
    }

    @Test func theStoppedCardOffersWhatTheFlagsAllow() throws {
        let state = ThreadCardState.make(show: try show(["card": #""stopped""#]), isForbidden: false)
        guard case .stopped(let stop, let canRetry, let canSetUp, let targets) = state else {
            Issue.record("not the stopped card")
            return
        }
        #expect(stop.summary == "Tests kept failing." && canRetry && !canSetUp && targets == ["example/web"])
        let waiting = try show([
            "card": #""stopped""#,
            "stopped": stoppedJSON.replacingOccurrences(of: #""isWaitingForSetup":false"#, with: #""isWaitingForSetup":true"#),
        ])
        guard case .stopped(_, let retryWhileWaiting, let setUp, _) = ThreadCardState.make(show: waiting, isForbidden: false)
        else {
            Issue.record("not the stopped card")
            return
        }
        #expect(!retryWhileWaiting && setUp)
    }

    @Test func theExpiredCoOwnerStopOffersNoRetry() throws {
        let expired = try show([
            "card": #""stopped""#,
            "stopped": #"{"kind":"needs_you","summary":"Nobody decided within 7 days, so Yak did not open the pull request.","detail":null,"nextStep":"Send a message to continue this work.","isWaitingForSetup":false}"#,
            "actions": #"{"canStop":false,"canRetry":false,"canCancel":false,"canMove":false,"moveTargets":[],"canRequestReview":false,"canRetryRender":false}"#,
        ])
        guard case .stopped(let stop, let canRetry, let canSetUp, let targets) = ThreadCardState.make(show: expired, isForbidden: false)
        else {
            Issue.record("not the stopped card")
            return
        }
        #expect(stop.kind == .needsYou && !canRetry && !canSetUp && targets.isEmpty)
    }

    @Test func moveNeedsBothTheFlagAndATarget() throws {
        let noTargets = try show([
            "card": #""stopped""#,
            "actions": actionsJSON.replacingOccurrences(of: #""moveTargets":["example/web"]"#, with: #""moveTargets":[]"#),
        ])
        guard case .stopped(_, _, _, let targets) = ThreadCardState.make(show: noTargets, isForbidden: false) else {
            Issue.record("not the stopped card")
            return
        }
        #expect(targets.isEmpty)
    }
}
