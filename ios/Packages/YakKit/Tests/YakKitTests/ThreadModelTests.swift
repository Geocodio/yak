import Foundation
import Testing

@testable import YakKit

private func mutated(_ name: String, _ change: (inout [String: Any]) -> Void) throws -> Data {
    var object = try #require(JSONSerialization.jsonObject(with: Scenarios.data(name)) as? [String: Any])
    change(&object)
    return try JSONSerialization.data(withJSONObject: object)
}

@Test func decodesTheThreadList() throws {
    let list = try JSONDecoder().decode(ThreadList.self, from: Scenarios.data("scenario-thread-list"))
    #expect(list.needsYouCount == 2 && list.hasAnyThreads)
    guard case .thread(let row) = list.needsYou[0], case .task(let task) = list.needsYou[1] else {
        Issue.record("needsYou kinds")
        return
    }
    #expect(row.needsYouReason == .question && row.group == .today)
    #expect(task.taskId.value == "k3m9p2qa" && task.needsYouReason == .coOwner)
    #expect(list.threads[0].isPrivate && list.threads[0].children[0].id == "e5f6g7h8")
}

@Test func decodesThreadShowAndTheQuestionVariant() throws {
    let show = try JSONDecoder().decode(ThreadShow.self, from: Scenarios.data("scenario-thread-show"))
    #expect(show.latestSequence == 14 && show.question == nil && show.composer.state == .idle && !show.panes.preview)
    #expect(show.messages[0].uuid == "00000000-0000-4000-8000-000000000001" && show.messages[0].readAfterSequence == 2)
    let asked = try JSONDecoder().decode(ThreadShow.self, from: Scenarios.data("scenario-thread-question"))
    #expect(asked.question?.roundKey == "k3m9p2qa:on_failure,notify")
    #expect(asked.question?.questions[1].multiSelect == true)
}

@Test func unknownValuesSurviveDecoding() throws {
    let data = try mutated("scenario-thread-show") { object in
        var composer = object["composer"] as? [String: Any] ?? [:]
        composer["state"] = "hibernating"
        object["composer"] = composer
        var thread = object["thread"] as? [String: Any] ?? [:]
        thread["status"] = "archived"
        object["thread"] = thread
        object["somethingNew"] = ["a": 1]
    }
    let show = try JSONDecoder().decode(ThreadShow.self, from: data)
    #expect(show.composer.state.rawValue == "hibernating")
    #expect(show.thread.status != .open && show.thread.status != .closed)
}

@Test func aNeedsYouItemOfAnUnknownTypeIsKeptAsUnknown() throws {
    let data = try mutated("scenario-thread-list") { object in
        var items = object["needsYou"] as? [[String: Any]] ?? []
        items[1] = ["type": "observation", "anything": 1]
        var plain = items[0]
        plain.removeValue(forKey: "type")
        items.append(plain)
        object["needsYou"] = items
    }
    let list = try JSONDecoder().decode(ThreadList.self, from: data)
    #expect(list.needsYou[1] == .unknown(type: "observation"))
    guard case .thread = list.needsYou[2] else {
        Issue.record("a row without type is a thread")
        return
    }
}

@Test func aListWithoutStarredDecodes() throws {
    let data = try mutated("scenario-thread-list") { $0.removeValue(forKey: "starred") }
    #expect(try JSONDecoder().decode(ThreadList.self, from: data).starred.isEmpty)
}

@Test(arguments: [
    ("scenario-thread-show", ["latestSequence"], #""14""#), ("scenario-thread-show", ["canDrive"], "1"),
    ("scenario-thread-list", ["needsYouCount"], "null"), ("scenario-message-sent", ["uuid"], "7"),
])
func rejectsAWronglyTypedThreadField(name: String, path: [String], replacement: String) throws {
    let data = try Scenarios.data(name, setting: path, toJSON: replacement)
    #expect(throws: DecodingError.self) {
        switch name {
        case "scenario-thread-show": _ = try JSONDecoder().decode(ThreadShow.self, from: data)
        case "scenario-thread-list": _ = try JSONDecoder().decode(ThreadList.self, from: data)
        default: _ = try JSONDecoder().decode(MessageSendResult.self, from: data)
        }
    }
}

@Test func decodesTheSmallResponses() throws {
    #expect(try decoded(ThreadCreated.self, "scenario-thread-store").id == "k7m2q9rt")
    #expect(try decoded(MessageSendResult.self, "scenario-message-queued").status == .queued)
    let clear = try decoded(MessageSendResult.self, "scenario-message-clear")
    #expect(clear.status == .sent && clear.uuid == nil && clear.threadId == "n3w7hr3d")
    #expect(try decoded(NewThreadOptions.self, "scenario-thread-new").picker.accounts[0].id.value == "3")
}

@Test func modelsRoundTripThroughJSON() throws {
    let show = try decoded(ThreadShow.self, "scenario-thread-question")
    #expect(try JSONDecoder().decode(ThreadShow.self, from: JSONEncoder().encode(show)) == show)
    let list = try decoded(ThreadList.self, "scenario-thread-list")
    #expect(try JSONDecoder().decode(ThreadList.self, from: JSONEncoder().encode(list)) == list)
    let options = try decoded(NewThreadOptions.self, "scenario-thread-new")
    #expect(try JSONDecoder().decode(NewThreadOptions.self, from: JSONEncoder().encode(options)) == options)
}

@Test func turnStateKnowsWhichStatesAreActive() {
    for state in [TurnState.running, .pending, .waitingForAccount, .waitingForSandbox] { #expect(state.isActive) }
    for state in [TurnState.completed, .failed, .interrupted, .stoppedByGuard, TurnState(rawValue: "new_state")] {
        #expect(!state.isActive)
    }
}
