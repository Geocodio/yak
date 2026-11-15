import Foundation
import Testing

@testable import YakKit

private func row(status: String, pr: Bool = false) throws -> TaskRow {
    var object = try #require(
        try JSONSerialization.jsonObject(with: Scenarios.data("scenario-tasks")) as? [String: Any])
    var first = try #require((object["tasks"] as? [[String: Any]])?[0])
    first["status"] = status
    first["pr"] = pr ? first["pr"] : NSNull()
    object["tasks"] = [first]
    return try JSONDecoder().decode(TaskListPage.self, from: JSONSerialization.data(withJSONObject: object)).tasks[0]
}

@Suite struct TaskRowTextTests {
    @Test func subtitlesComeFromTheStatus() throws {
        let cases: [(String, Bool, TaskSubtitle)] = [
            ("awaiting_co_owner", true, .waitsForCoOwner), ("awaiting_clarification", false, .waitsForAnswer),
            ("running", false, .running(source: "Linear")), ("awaiting_ci", true, .checkingCI(pr: 41)),
            ("awaiting_ci", false, .checkingCI(pr: nil)), ("retrying", false, .retrying),
            ("success", true, .pullRequest(number: 41, state: "open")), ("success", false, .done(source: "Linear")),
            ("failed", false, .failed), ("expired", false, .expired), ("cancelled", false, .cancelled),
            ("pending", false, .pending), ("brand_new", false, .other(status: "brand_new")),
        ]
        for (status, hasPullRequest, expected) in cases {
            #expect(TaskRowText.subtitle(try row(status: status, pr: hasPullRequest)) == expected, "\(status)")
        }
    }

    @Test func onlyTheStatusesThatWaitNeedYou() throws {
        #expect(TaskRowText.needsYou(try row(status: "awaiting_co_owner")))
        #expect(TaskRowText.needsYou(try row(status: "awaiting_clarification")))
        #expect(!TaskRowText.needsYou(try row(status: "running")))
        #expect(!TaskRowText.needsYou(try row(status: "failed")))
    }
}
