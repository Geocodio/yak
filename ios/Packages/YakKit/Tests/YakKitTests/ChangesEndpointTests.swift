import Foundation
import Testing

@testable import YakKit

private func jsonBody<R>(_ endpoint: Endpoint<R>) throws -> NSDictionary {
    let body = try #require(endpoint.body)
    let data = try JSONEncoder().encode(body)
    return try #require(try JSONSerialization.jsonObject(with: data) as? NSDictionary)
}

@Suite struct ChangesEndpointTests {
    @Test func diffEndpointsHaveTheContractPaths() throws {
        #expect(Endpoint.threadDiff("d0e515ef").path == "threads/d0e515ef/diff")
        #expect(Endpoint.diffFile("d0e515ef", fileID: 501).path == "threads/d0e515ef/diff/files/501")
        #expect(Endpoint.pullRequests("d0e515ef").path == "threads/d0e515ef/pull-requests")
        #expect(Endpoint.threadDiff("../me").path == "threads/..%2Fme/diff")
        let fix = Endpoint.askToFix("d0e515ef", rowID: 78)
        #expect(fix.method == .post && fix.path == "threads/d0e515ef/pull-requests/78/fix" && fix.body == nil)
        let reorder = Endpoint.reorderPullRequests("d0e515ef", order: [78, 77])
        #expect(reorder.method == .put && reorder.path == "threads/d0e515ef/pull-requests/order")
        let reorderBody = try jsonBody(reorder)
        #expect(reorderBody == ["order": [78, 77]])
    }

    @Test func openSendsTheAcknowledgementOnlyWhenGiven() throws {
        let acknowledged = Endpoint.openPullRequest("d0e515ef", acknowledgedPrivatePublish: true)
        #expect(acknowledged.method == .post && acknowledged.path == "threads/d0e515ef/pull-request")
        let acknowledgedBody = try jsonBody(acknowledged)
        #expect(acknowledgedBody == ["acknowledged_private_publish": true])
        let plainBody = try jsonBody(Endpoint.openPullRequest("d0e515ef", acknowledgedPrivatePublish: false))
        #expect(plainBody == [:])
    }

    @Test func everyEndpointKeepsTheDefaultTimeout() {
        #expect(Endpoint.threadDiff("a").timeout == .seconds(15))
        #expect(Endpoint.openPullRequest("a", acknowledgedPrivatePublish: false).timeout == .seconds(15))
    }

    @Test func theDiffScenarioDecodes() throws {
        let diff = try #require(try decoded(ThreadDiffResponse.self, "scenario-thread-diff").diff)
        #expect(diff.files.map(\.id) == [501, 502, 503, 504] && diff.isTruncated)
        #expect(diff.files[2].key == DiffFileKey(repo: "example/web", path: "src/uploads.ts"))
        #expect(diff.files[2].oldPath == "src/upload.ts" && diff.files[2].status == .renamed)
        #expect(diff.files[1].additions == nil && diff.files[1].isBinary)
        #expect(diff.files[3].isTooLarge)
        #expect(diff.repositories.map(\.role) == [.primary, .changes])
        #expect(try decoded(ThreadDiffResponse.self, "scenario-thread-diff-none").diff == nil)
    }

    @Test func theFileScenariosDecode() throws {
        let file = try decoded(DiffFileContent.self, "scenario-diff-file")
        #expect(file.patch?.hasPrefix("diff --git") == true && file.status == .modified)
        let binary = try decoded(DiffFileContent.self, "scenario-diff-file-binary")
        #expect(binary.isBinary && binary.patch == nil)
        let large = try decoded(DiffFileContent.self, "scenario-diff-file-too-large")
        #expect(large.isTooLarge && large.patch == nil)
    }

    @Test func thePanelScenariosDecode() throws {
        let panel = try decoded(PullRequestsPanel.self, "scenario-pull-requests")
        #expect(panel.state == .blocked && panel.pullRequests.map(\.rowId) == [77, 78])
        #expect(panel.pullRequests[1].canAskToFix)
        #expect(panel.pullRequests[1].mergeAfter == MergeAfter(repo: "example/api", number: 41, reason: nil))
        #expect(panel.pullRequests[1].ci?.checks.first?.excerpt == "src/uploads.ts:3 unused import")
        let none = try decoded(PullRequestsPanel.self, "scenario-pull-requests-none")
        #expect(none.state == .none && none.pullRequests.isEmpty && none.withoutPullRequest.count == 1)
    }

    @Test func theOpeningAnswersDecode() throws {
        #expect(try decoded(StatusResponse.self, "scenario-pull-request-opening").status == "opening")
        #expect(try decoded(StatusResponse.self, "scenario-pull-request-open").status == "open")
        #expect(try decoded(StatusResponse.self, "scenario-pull-request-held").status == "held")
    }

    @Test func aPanelWithoutABannerStillDecodes() throws {
        let data = try Scenarios.data("scenario-pull-requests", setting: ["banner"], toJSON: "null")
        #expect(try JSONDecoder().decode(PullRequestsPanel.self, from: data).banner == nil)
    }

    @Test func unknownEnumValuesAreKept() throws {
        let status = try JSONDecoder().decode(DiffFileStatus.self, from: Data("\"copied\"".utf8))
        let role = try JSONDecoder().decode(RepositoryRole.self, from: Data("\"mirror\"".utf8))
        let check = try JSONDecoder().decode(CheckState.self, from: Data("\"skipped\"".utf8))
        #expect(status.rawValue == "copied" && role.rawValue == "mirror" && check.rawValue == "skipped")
    }
}
