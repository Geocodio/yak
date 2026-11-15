import Foundation
import Testing

@testable import YakKit

/// Decodes the server's contract fixtures that the changes feature reads. A missing fixture returns early and
/// prints "MISSING FIXTURE: <name>"; the merge gate greps the log for that line to confirm none was skipped.
@Suite struct ChangesFixtureDecodingTests {
    /// True when the fixture is on disk; otherwise prints the line the merge gate looks for.
    private static func isPresent(_ name: String) -> Bool {
        if Fixtures.exists(name) { return true }
        print("MISSING FIXTURE: \(name)")
        return false
    }

    @Test(arguments: ["thread-diff", "thread-diff-none"])
    func decodesTheThreadDiffFixtures(name: String) throws {
        guard Self.isPresent(name) else { return }
        let response = try JSONDecoder().decode(ThreadDiffResponse.self, from: Fixtures.data(name))
        if name == "thread-diff" {
            let diff = try #require(response.diff)
            #expect(!diff.files.isEmpty && !diff.repositories.isEmpty)
        } else {
            #expect(response.diff == nil)
        }
    }

    @Test(arguments: ["diff-file", "diff-file-binary", "diff-file-too-large"])
    func decodesTheDiffFileFixtures(name: String) throws {
        guard Self.isPresent(name) else { return }
        _ = try JSONDecoder().decode(DiffFileContent.self, from: Fixtures.data(name))
    }

    @Test(arguments: ["pull-requests", "pull-requests-none"])
    func decodesThePullRequestPanelFixtures(name: String) throws {
        guard Self.isPresent(name) else { return }
        _ = try JSONDecoder().decode(PullRequestsPanel.self, from: Fixtures.data(name))
    }

    @Test(arguments: [
        "pull-request-opening", "pull-request-open", "pull-request-held", "pull-requests-order",
        "pull-request-fix",
    ])
    func decodesThePullRequestStatusFixtures(name: String) throws {
        guard Self.isPresent(name) else { return }
        _ = try JSONDecoder().decode(StatusResponse.self, from: Fixtures.data(name))
    }

    @Test(arguments: ["thread-with-changes", "thread-with-pull-request"])
    func decodesTheThreadShowFixtures(name: String) throws {
        guard Self.isPresent(name) else { return }
        let show = try JSONDecoder().decode(ThreadShow.self, from: Fixtures.data(name))
        if name == "thread-with-changes" {
            #expect(show.canOpenPullRequest)
            #expect(show.diffTotals?.filesCount == 3)
            #expect(!show.publishWarning)
        } else {
            #expect(show.pullRequest != nil)
            #expect(!show.canOpenPullRequest)
            #expect(show.thread.pullRequestCount == 1)
        }
    }

    @Test(arguments: ["error-private-publish", "error-nothing-to-publish", "error-ci-not-failing"])
    func decodesTheErrorFixtures(name: String) throws {
        guard Self.isPresent(name) else { return }
        let status = name == "error-private-publish" ? 422 : 409
        #expect(APIError.parse(status: status, body: try Fixtures.data(name)) != nil, "\(name)")
    }

    @Test func theDiffUpdatedEventListDecodes() throws {
        let name = "events/diff-updated"
        guard Self.isPresent(name) else { return }
        let list = try EventList.loadFixture(name)
        #expect(list.stored.last?.payload == .diffUpdated)
        let root = try #require(try Fixtures.object(name) as? [String: Any])
        let catchUp = try #require(root["catchUp"] as? [String: Any])
        let events = try #require(catchUp["events"] as? [[String: Any]])
        let last = try #require(events.last)
        #expect(last["type"] as? String == "diff.updated")
        // The data is an empty object, never an empty array.
        let data = try #require(last["data"] as? [String: Any])
        #expect(data.isEmpty)
    }
}
