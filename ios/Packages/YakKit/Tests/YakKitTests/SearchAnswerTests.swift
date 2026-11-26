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

    @Test func showsAnUnknownKindAsAPlainResultAndSkipsOneWithoutAnIdOrAType() throws {
        let json = """
            {"results":[
              {"type":"thread","id":"a","title":"One","subtitle":"x","threadId":"a","path":"/threads/a","url":null},
              {"type":"comment","id":"c","title":"New kind"},
              {"type":"repository","title":"No id"},
              {"id":"n","title":"No type"},
              {"type":"repository","id":"r","title":"Two"},
              "not an object"
            ]}
            """
        let answer = try JSONDecoder().decode(SearchAnswer.self, from: Data(json.utf8))
        #expect(answer.results.map(\.id) == ["a", "c", "r"])
        #expect(answer.results[1].kind == .other && answer.results[1].title == "New kind")
        #expect(answer.results[2].subtitle == "" && answer.results[2].url == nil)
        #expect(answer.groups.map(\.kind) == [.thread, .repository, .other])
    }

    @Test func refusesAnUnsafeLinkAndAPathOffTheInstance() throws {
        let json = """
            {"results":[{"type":"pull_request","id":"p","title":"T","path":"//evil.example","url":"http://example.com/a"}]}
            """
        let result = try #require(try JSONDecoder().decode(SearchAnswer.self, from: Data(json.utf8)).results.first)
        #expect(result.path == nil && result.url == nil)
    }

    @Test func aGroupAtTheServersLimitIsMarked() {
        let many = (0..<SearchResult.limitPerKind).map {
            SearchResult(kind: .thread, id: "\($0)", title: "T", subtitle: "")
        }
        #expect(SearchGroup.groups(of: many).first?.isAtLimit == true)
        #expect(SearchGroup.groups(of: Array(many.dropLast())).first?.isAtLimit == false)
    }

    @Test func cutsTheTextAtOneHundredCodePointsNotCharactersOrUnits() {
        let text = String(repeating: "\u{1F600}", count: 150)
        let query = Endpoint<SearchAnswer>.search(text).query[0].value ?? ""
        #expect(query.unicodeScalars.count == 100 && query.allSatisfy { $0 == "\u{1F600}" })
        let family = String(repeating: "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}", count: 60)
        #expect((Endpoint<SearchAnswer>.search(family).query[0].value ?? "").unicodeScalars.count == 100)
    }

    @Test func anAnswerWithoutResultsIsEmpty() throws {
        #expect(try JSONDecoder().decode(SearchAnswer.self, from: Data("{}".utf8)).results.isEmpty)
    }

    @Test func buildsTheRequest() {
        let endpoint = Endpoint<SearchAnswer>.search("  pars  ")
        #expect(endpoint.method == .get && endpoint.path == "search")
        #expect(endpoint.query == [URLQueryItem(name: "q", value: "pars")])
        #expect(!endpoint.retriesTransientFailures)
        #expect(Endpoint<SearchAnswer>.search(String(repeating: "a", count: 150)).query[0].value?.count == 100)
    }
}
