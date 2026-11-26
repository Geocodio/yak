import Foundation
import Testing

@testable import YakKit

@Suite struct SearchAnswerTests {
    @Test func decodesTheServersFixtureInThreeGroups() throws {
        let answer = try JSONDecoder().decode(SearchAnswer.self, from: try Fixtures.data("search"))
        #expect(answer.groups.map(\.kind) == [.thread, .repository, .pullRequest])
        let pullRequest = try #require(answer.results.last)
        #expect(pullRequest.kind == .pullRequest && pullRequest.threadID != nil)
        #expect(pullRequest.url?.absoluteString == "https://github.com/example/web/pull/12")
        #expect(answer.results[1].threadID == nil && answer.results[1].subtitle == "example/uploads")
    }

    @Test func skipsAnUnknownKindAndAResultWithoutAnId() throws {
        let json = """
            {"results":[
              {"type":"thread","id":"a","title":"One","subtitle":"x","threadId":"a","path":"/threads/a","url":null},
              {"type":"comment","id":"c","title":"New kind"},
              {"type":"repository","title":"No id"},
              {"type":"repository","id":"r","title":"Two"},
              "not an object"
            ]}
            """
        let answer = try JSONDecoder().decode(SearchAnswer.self, from: Data(json.utf8))
        #expect(answer.results.map(\.id) == ["a", "r"])
        #expect(answer.results[1].subtitle == "" && answer.results[1].url == nil)
    }

    @Test func refusesAnUnsafeLinkAndAPathOffTheInstance() throws {
        let json = """
            {"results":[{"type":"pull_request","id":"p","title":"T","path":"//evil.example","url":"http://example.com/a"}]}
            """
        let result = try #require(try JSONDecoder().decode(SearchAnswer.self, from: Data(json.utf8)).results.first)
        #expect(result.path == nil && result.url == nil)
    }

    @Test func anAnswerWithoutResultsIsEmpty() throws {
        #expect(try JSONDecoder().decode(SearchAnswer.self, from: Data("{}".utf8)).results.isEmpty)
    }

    @Test func buildsTheRequest() {
        let endpoint = Endpoint<SearchAnswer>.search("  geocod  ")
        #expect(endpoint.method == .get && endpoint.path == "search")
        #expect(endpoint.query == [URLQueryItem(name: "q", value: "geocod")])
        #expect(!endpoint.retriesTransientFailures)
        #expect(Endpoint<SearchAnswer>.search(String(repeating: "a", count: 150)).query[0].value?.count == 100)
    }
}
