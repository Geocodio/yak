import Foundation
import Testing

@testable import YakKit

/// Decodes the server's contract fixtures that the changes feature reads. A missing fixture returns early; the
/// merge gate checks the log to confirm none did.
@Suite struct ChangesFixtureDecodingTests {
    @Test func decodesTheDiffFixtures() throws {
        if Fixtures.exists("thread-diff") {
            let diff = try #require(
                try JSONDecoder().decode(ThreadDiffResponse.self, from: Fixtures.data("thread-diff")).diff)
            #expect(!diff.files.isEmpty && !diff.repositories.isEmpty)
        }
        if Fixtures.exists("thread-diff-none") {
            let none = try JSONDecoder().decode(ThreadDiffResponse.self, from: Fixtures.data("thread-diff-none"))
            #expect(none.diff == nil)
        }
        for name in ["diff-file", "diff-file-binary", "diff-file-too-large"] where Fixtures.exists(name) {
            _ = try JSONDecoder().decode(DiffFileContent.self, from: Fixtures.data(name))
        }
    }

    @Test func decodesThePullRequestFixtures() throws {
        for name in ["pull-requests", "pull-requests-none"] where Fixtures.exists(name) {
            _ = try JSONDecoder().decode(PullRequestsPanel.self, from: Fixtures.data(name))
        }
        for name in [
            "pull-request-opening", "pull-request-open", "pull-request-held", "pull-requests-order",
            "pull-request-fix",
        ] where Fixtures.exists(name) {
            _ = try JSONDecoder().decode(StatusResponse.self, from: Fixtures.data(name))
        }
    }

    @Test func decodesTheErrorFixtures() throws {
        for name in ["error-private-publish", "error-nothing-to-publish", "error-ci-not-failing"]
        where Fixtures.exists(name) {
            let status = name == "error-private-publish" ? 422 : 409
            let body = try Fixtures.data(name)
            #expect(APIError.parse(status: status, body: body) != nil, "\(name)")
        }
    }

    @Test func theDiffUpdatedEventListDecodes() throws {
        guard Fixtures.exists("events/diff-updated") else { return }
        let list = try EventList.loadFixture("events/diff-updated")
        let last = try #require(list.events.last)
        #expect(last.payload == .diffUpdated)
    }
}
