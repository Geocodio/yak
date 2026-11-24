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
    @Test func onlyTheNamedCardCounts() throws {
        func kind(_ card: String) throws -> String {
            switch ThreadCardState.make(show: try show(["card": "\"\(card)\""]), isForbidden: false) {
            case .none: "none"
            case .question: "question"
            case .card(let named, _): named.rawValue
            case .unknown: "unknown"
            }
        }
        #expect(try kind("repository_question") == "repository_question")
        #expect(try kind("early_warning") == "early_warning")
        #expect(try kind("co_owner") == "co_owner")
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
        #expect(!state.hasNoButtons && !state.needsYou)
    }

    @Test func aReaderSeesNoButtons() throws {
        for card in ["repository_question", "early_warning", "co_owner", "stopped"] {
            let reader = try show(["card": "\"\(card)\""])
            var readOnly = reader
            readOnly.canDrive = false
            let state = ThreadCardState.make(show: readOnly, isForbidden: false)
            #expect(state != .none && state.hasNoButtons, "\(card)")
            // The engine's forbidden flag does the same for a person whose page still says they can drive.
            #expect(ThreadCardState.make(show: reader, isForbidden: true).hasNoButtons, "\(card) forbidden")
            #expect(!ThreadCardState.make(show: reader, isForbidden: false).hasNoButtons, "\(card) driver")
        }
    }

    @Test func theRepositoryQuestionFollowsTheServersCanAnswer() throws {
        let closedToTheServerAnswer = try show([
            "card": #""repository_question""#,
            "repositoryQuestion": #"{"prompt":"P","options":["a"],"selected":null,"canAnswer":false}"#,
        ])
        #expect(ThreadCardState.make(show: closedToTheServerAnswer, isForbidden: false).hasNoButtons)
    }

    @Test func theEarlyWarningFollowsTheServersCanPick() throws {
        let tagged = try show([
            "card": #""early_warning""#,
            "earlyWarning":
                #"{"areas":[],"state":"tagged","taggedName":"Second person","fallbackName":null,"repoName":"example/api","canPick":false}"#,
        ])
        #expect(ThreadCardState.make(show: tagged, isForbidden: false).hasNoButtons)
        #expect(ThreadCardState.make(show: try show(["card": #""early_warning""#]), isForbidden: false).needsYou)
    }

    @Test func theCoOwnerCardFollowsCanDecide() throws {
        #expect(ThreadCardState.make(show: try show(["card": #""co_owner""#]), isForbidden: false).needsYou)
        let cannotDecide = try show([
            "card": #""co_owner""#,
            "coOwner": panelJSON.replacingOccurrences(of: #""canDecide":true"#, with: #""canDecide":false"#),
        ])
        #expect(ThreadCardState.make(show: cannotDecide, isForbidden: false).hasNoButtons)
        let decided = try show([
            "card": #""co_owner""#, "coOwner": panelJSON.replacingOccurrences(of: #""pending""#, with: #""approved""#),
        ])
        #expect(ThreadCardState.make(show: decided, isForbidden: false).hasNoButtons)
    }

    @Test func theStoppedCardNeedsThePersonOnlyWhenTheFlagsAllowAnAction() throws {
        #expect(ThreadCardState.make(show: try show(["card": #""stopped""#]), isForbidden: false).needsYou)
        let expired = try show([
            "card": #""stopped""#,
            "actions":
                #"{"canStop":false,"canRetry":false,"canCancel":false,"canMove":false,"moveTargets":[],"canRequestReview":false,"canRetryRender":false}"#,
        ])
        #expect(ThreadCardState.make(show: expired, isForbidden: false).hasNoButtons)
    }

    @Test func moveNeedsBothTheFlagAndATarget() throws {
        let noRetryNoTargets = try show([
            "card": #""stopped""#,
            "actions": actionsJSON.replacingOccurrences(
                of: #""moveTargets":["example/web"]"#, with: #""moveTargets":[]"#
            ).replacingOccurrences(of: #""canRetry":true"#, with: #""canRetry":false"#),
        ])
        #expect(ThreadCardState.make(show: noRetryNoTargets, isForbidden: false).hasNoButtons)
    }
}

@Suite struct ThreadCardNeedsYouTests {
    @Test func aCardWithButtonsNeedsThePersonAndAReaderDoesNot() throws {
        let stopped = ThreadCardState.make(show: try show(["card": #""stopped""#]), isForbidden: false)
        #expect(stopped.needsYou)
        let reader = ThreadCardState.make(show: try show(["card": #""stopped""#]), isForbidden: true)
        #expect(!reader.needsYou)
        #expect(!ThreadCardState.none.needsYou)
    }

    @Test func theActivityLineNamesACardThatNeedsThePerson() throws {
        let snapshot = ConversationSnapshot(show: try show(["card": #""repository_question""#]))
        #expect(ActivityLine.make(snapshot) == .needsYou)
    }
}
