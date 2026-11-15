import Foundation
import Testing

@testable import YakKit

/// Server fixtures are read as they are. A case whose fixture is not there is recorded as an issue, never passed silently.
@Test(
    arguments: [
        "stack", "stack-child", "stack-empty", "stack-stopped", "stack-child-created", "stack-stop",
        "stack-limit-updated", "stack-take-over", "stack-split", "stack-drop", "error-stack-full",
        "thread-environment", "thread-repository-retry", "thread-share", "thread-share-updated", "threads", "thread",
    ])
func decodesEveryOverviewFixture(name: String) throws {
    guard Fixtures.exists(name) else {
        Issue.record("The server fixture \(name) is missing, so nothing was decoded.")
        return
    }
    let data = try Fixtures.data(name)
    let decoder = JSONDecoder()
    switch name {
    case "stack", "stack-child", "stack-empty", "stack-stopped":
        let stack = try decoder.decode(ThreadStack.self, from: data)
        if name == "stack" { #expect(stack.children.count == 3) }
        if name == "stack-empty" { #expect(stack.children.isEmpty) }
        if name == "stack-stopped" { #expect(stack.stoppedAt != nil && stack.addChild.error == "stack_stopped") }
        if name == "stack-child" { #expect(!stack.isRoot) }
    case "stack-child-created":
        _ = try decoder.decode(SubThreadCreated.self, from: data)
    case "stack-limit-updated":
        #expect(try decoder.decode(AwakeLimitUpdated.self, from: data).status == "updated")
    case "stack-stop", "stack-take-over", "stack-split", "stack-drop", "thread-repository-retry":
        let expected = [
            "stack-stop": "stopping", "stack-take-over": "taken_over", "stack-split": "split_requested",
            "stack-drop": "dropped", "thread-repository-retry": "retrying",
        ]
        #expect(try decoder.decode(StatusResponse.self, from: data).status == expected[name])
    case "error-stack-full":
        let body = try #require(try JSONSerialization.jsonObject(with: data) as? [String: Any])
        #expect(body["error"] as? String == "stack_full")
        let details = try #require(body["details"] as? [String: Any])
        #expect(details["count"] is NSNumber && details["max"] is NSNumber)
    case "thread-environment": _ = try decoder.decode(ThreadEnvironment.self, from: data)
    case "thread-share": _ = try decoder.decode(ThreadShare.self, from: data)
    case "thread-share-updated": _ = try decoder.decode(ThreadShareUpdated.self, from: data)
    case "threads": _ = try decoder.decode(ThreadList.self, from: data)
    default: _ = try decoder.decode(ThreadShow.self, from: data)
    }
}
