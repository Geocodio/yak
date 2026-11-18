import Foundation
import Testing

@testable import YakStub

@Suite struct StubWalkthroughVideoTests {
    private let length = 100

    @Test(
        arguments: [
            (nil, ByteRange.whole), ("bytes=0-1", .partial(0...1)), ("bytes=10-", .partial(10...99)),
            ("bytes=-5", .partial(95...99)), ("bytes=0-999999", .partial(0...99)), ("bytes=5-2", .unsatisfiable),
            ("bytes=-0", .unsatisfiable), ("bytes=100-", .unsatisfiable),
            ("bytes=99999999999999999999-", .unsatisfiable), ("bytes=0-99999999999999999999", .partial(0...99)),
            ("bytes=0-1,4-5", .whole), ("items=0-1", .whole), ("bytes=a-b", .whole), ("bytes= 0-1", .whole),
            ("bytes=-", .whole), (String(repeating: "x", count: 201), .whole),
        ] as [(String?, ByteRange)])
    func parsesRanges(header: String?, expected: ByteRange) {
        #expect(ByteRange.parse(header, length: length) == expected)
    }

    private func context(_ scenario: String) throws -> StubContext {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
        let fixtures = try StubFixtures(
            directory: directory.appendingPathComponent("fixtures"),
            scenariosDirectory: directory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios"))
        return StubContext(
            scenario: scenario, baseURL: "http://127.0.0.1:1", fixtures: fixtures, threads: StubThreads(),
            core: { .drop })
    }

    private func get(
        _ feature: StubWalkthroughVideo, _ file: String, headers: [String: String] = [:]
    ) -> (status: Int, body: Data, reply: StubReply) {
        let request = StubRequest(
            method: "GET", path: "/__stub/walkthrough-video/\(file)", headers: headers)
        let reply = feature.control(request)!
        guard case .reply(let status, _, let body) = reply.answer else { return (0, Data(), reply) }
        return (status, body, reply)
    }

    private func task(_ feature: StubWalkthroughVideo, _ scenario: String) throws -> [String: Any] {
        let reply = feature.answer(
            route: "GET tasks/k3m9p2qa", request: StubRequest(method: "GET", path: "x"),
            context: try context(scenario))
        guard case .reply(_, _, let data)? = reply?.answer else { throw URLError(.badServerResponse) }
        let object = try #require(try JSONSerialization.jsonObject(with: data) as? [String: Any])
        return try #require(object["walkthrough"] as? [String: Any])
    }

    @Test func aRangeRequestGets206() {
        let result = get(StubWalkthroughVideo(), "sample.mp4", headers: ["range": "bytes=0-1"])
        #expect(result.status == 206)
        #expect(result.body.count == 2)
        #expect(result.reply.headers["Content-Range"] == "bytes 0-1/\(StubWalkthroughMedia.video.count)")
        #expect(result.reply.headers["Accept-Ranges"] == "bytes")
    }

    @Test func aPlainRequestGetsTheWholeFile() {
        let result = get(StubWalkthroughVideo(), "sample.mp4")
        #expect(result.status == 200)
        #expect(result.body == StubWalkthroughMedia.video)
    }

    @Test func anUnsatisfiableRangeIs416() {
        let result = get(StubWalkthroughVideo(), "sample.mp4", headers: ["range": "bytes=999999-"])
        #expect(result.status == 416)
        #expect(result.reply.headers["Content-Range"] == "bytes */\(StubWalkthroughMedia.video.count)")
    }

    @Test func theTaskScenarioPointsAtTheStub() throws {
        let walkthrough = try task(StubWalkthroughVideo(), "task-walkthrough")
        #expect(walkthrough["videoUrl"] as? String == "http://127.0.0.1:1/__stub/walkthrough-video/sample.mp4")
        #expect(walkthrough["posterUrl"] as? String == "http://127.0.0.1:1/__stub/walkthrough-video/poster.png")
    }

    @Test func theExpiredScenarioPointsAtExpiredAndIs403() throws {
        let feature = StubWalkthroughVideo()
        let walkthrough = try task(feature, "task-walkthrough-expired")
        #expect((walkthrough["videoUrl"] as? String)?.hasSuffix("/__stub/walkthrough-video/expired.mp4") == true)
        #expect(get(feature, "expired.mp4").status == 403)
    }

    @Test func theSlowScenarioDelaysTheVideoOnly() throws {
        let feature = StubWalkthroughVideo()
        feature.played(scenario: "task-walkthrough-slow")
        #expect(get(feature, "sample.mp4").reply.delay == .seconds(12))
        let reply = feature.answer(
            route: "GET tasks/k3m9p2qa", request: StubRequest(method: "GET", path: "x"),
            context: try context("task-walkthrough-slow"))
        #expect(reply?.delay == .zero)
        feature.played(scenario: "task-walkthrough")
        #expect(get(feature, "sample.mp4").reply.delay == .zero)
    }

    @Test func otherScenariosAndRoutesAreNotAnswered() throws {
        let feature = StubWalkthroughVideo()
        #expect(
            feature.answer(
                route: "GET tasks/k3m9p2qa", request: StubRequest(method: "GET", path: "x"),
                context: try context("changes")) == nil)
        #expect(get(feature, "other").status == 404)
    }

    @Test func theSampleIsSmall() {
        #expect(StubWalkthroughMedia.video.count <= 65_536)
        #expect(StubWalkthroughMedia.video.count > 1_000)
    }

    @Test func theThreeScenarioNamesAreUnique() {
        let names = StubFeatures.all.flatMap(\.scenarios)
        #expect(Set(names).count == names.count)
    }

    private func logEntries(_ feature: StubWalkthroughVideo) throws -> [WalkthroughVideoRequest] {
        try JSONDecoder().decode([WalkthroughVideoRequest].self, from: get(feature, "log").body)
    }

    @Test func theLogRecordsRangeAndAuthorizationOnly() throws {
        let feature = StubWalkthroughVideo()
        let request = StubRequest(
            method: "GET", path: "/__stub/walkthrough-video/sample.mp4", query: ["signature": "ab"],
            headers: ["range": "bytes=0-1", "authorization": "Bearer test-token"])
        _ = feature.control(request)
        let entries = try logEntries(feature)
        #expect(
            entries == [
                WalkthroughVideoRequest(
                    method: "GET", path: "/__stub/walkthrough-video/sample.mp4", hasRange: true,
                    hasAuthorization: true)
            ])
        let text = String(decoding: get(feature, "log").body, as: UTF8.self)
        #expect(!text.contains("test-token") && !text.contains("bytes=0-1") && !text.contains("signature"))
    }

    @Test func theLogKeepsTheNewestFifty() throws {
        let feature = StubWalkthroughVideo()
        for index in 0..<60 {
            let headers = index == 10 ? ["range": "bytes=0-1"] : [:]
            _ = get(feature, "sample.mp4", headers: headers)
        }
        let entries = try logEntries(feature)
        #expect(entries.count == 50)
        #expect(entries.first?.hasRange == true)
    }

    @Test func playingAScenarioEmptiesTheLogAndPosterAndTaskAreNotLogged() throws {
        let feature = StubWalkthroughVideo()
        _ = get(feature, "poster.png")
        _ = try task(feature, "task-walkthrough")
        #expect(try logEntries(feature).isEmpty)
        _ = get(feature, "sample.mp4")
        feature.reset()
        #expect(try logEntries(feature).isEmpty)
    }
}
