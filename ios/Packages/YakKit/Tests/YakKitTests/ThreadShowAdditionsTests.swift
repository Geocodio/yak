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
