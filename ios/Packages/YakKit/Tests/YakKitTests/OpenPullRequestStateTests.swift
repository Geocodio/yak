import Foundation
import Testing

@testable import YakKit

struct OpenPullRequestCase: Sendable, CustomTestStringConvertible {
    let name: String
    let edits: [(path: [String], json: String)]
    let isSending: Bool
    let expected: OpenPullRequestState
    var testDescription: String { name }

    init(
        _ name: String, _ edits: [(path: [String], json: String)] = [], sending: Bool = false,
        _ expected: OpenPullRequestState
    ) {
        self.name = name
        self.edits = edits
        self.isSending = sending
        self.expected = expected
    }

    /// The scenario thread with changes and the edits applied one after the other.
    func show() throws -> ThreadShow {
        var object = try #require(
            JSONSerialization.jsonObject(with: Scenarios.data("scenario-thread-with-changes")) as? [String: Any])
        for edit in edits {
            let value = try JSONSerialization.jsonObject(with: Data(edit.json.utf8), options: .fragmentsAllowed)
            if edit.path.count == 2 {
                var inner = try #require(object[edit.path[0]] as? [String: Any])
                inner[edit.path[1]] = value
                object[edit.path[0]] = inner
            } else {
                object[edit.path[0]] = value
            }
        }
        return try JSONDecoder().decode(ThreadShow.self, from: JSONSerialization.data(withJSONObject: object))
    }

    static let all: [OpenPullRequestCase] = [
        .init("a pull request is open", [(["pullRequest"], #"{"number":41,"url":"https://example.com/pull/41"}"#)],
            .open(number: 41, url: "https://example.com/pull/41")),
        .init("an open one wins over sending", [(["pullRequest"], #"{"number":41,"url":"https://example.com/pull/41"}"#)],
            sending: true, .open(number: 41, url: "https://example.com/pull/41")),
        .init("sending", [], sending: true, .sending),
        .init("the server is opening it", [(["thread", "pullRequestOpening"], #"{"state":"opening"}"#)], .opening),
        .init("held for a co-owner", [(["thread", "pullRequestOpening"], #"{"state":"held"}"#)], .held),
        .init("a failed opening with a reason",
            [(["thread", "pullRequestOpening"], #"{"state":"failed","error":"Yak could not reach GitHub."}"#)],
            .failed(message: "Yak could not reach GitHub.")),
        .init("a failed opening without a reason",
            [(["thread", "pullRequestOpening"], #"{"state":"failed"}"#)],
            .failed(message: OpenPullRequestState.defaultFailureMessage)),
        .init("a failed opening the server no longer allows",
            [(["thread", "pullRequestOpening"], #"{"state":"failed"}"#), (["canOpenPullRequest"], "false")],
            .unavailable(.notReady)),
        .init("allowed", [], .available(needsConfirmation: false)),
        .init("allowed on a private thread", [(["publishWarning"], "true")], .available(needsConfirmation: true)),
        .init("read only", [(["canOpenPullRequest"], "false"), (["canDrive"], "false")], .unavailable(.readOnly)),
        .init("closed", [(["canOpenPullRequest"], "false"), (["thread", "status"], #""closed""#)],
            .unavailable(.closed)),
        .init("no changes",
            [(["canOpenPullRequest"], "false"), (["diffTotals"], #"{"additions":0,"deletions":0,"filesCount":0}"#)],
            .unavailable(.noChanges)),
        .init("no totals", [(["canOpenPullRequest"], "false"), (["diffTotals"], "null")], .unavailable(.noChanges)),
        .init("not ready", [(["canOpenPullRequest"], "false")], .unavailable(.notReady)),
        .init("an unknown opening state",
            [(["thread", "pullRequestOpening"], #"{"state":"queued"}"#), (["canOpenPullRequest"], "false")],
            .unavailable(.notReady)),
    ]
}

@Test(arguments: OpenPullRequestCase.all)
func theButtonFollowsTheServer(_ row: OpenPullRequestCase) throws {
    #expect(OpenPullRequestState.make(show: try row.show(), isSending: row.isSending) == row.expected)
}

@Test func withoutAShowTheButtonIsLoading() {
    #expect(OpenPullRequestState.make(show: nil, isSending: false) == .unavailable(.loading))
    #expect(OpenPullRequestState.make(show: nil, isSending: true) == .unavailable(.loading))
}

@Test func aPrivateThreadNeedsConfirmation() throws {
    let show = try decoded(ThreadShow.self, "scenario-thread-with-changes")
    #expect(OpenPullRequestState.make(show: show, isSending: false) == .available(needsConfirmation: false))
    let privateShow = try JSONDecoder().decode(
        ThreadShow.self, from: Scenarios.data("scenario-thread-with-changes", setting: ["publishWarning"], toJSON: "true"))
    #expect(OpenPullRequestState.make(show: privateShow, isSending: false) == .available(needsConfirmation: true))
}
