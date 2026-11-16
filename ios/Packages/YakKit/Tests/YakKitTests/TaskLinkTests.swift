import Foundation
import Testing

@testable import YakKit

@Suite struct TaskLinkTests {
    @Test func aTaskWebURLGivesItsID() throws {
        for text in [
            "https://yak.example.com/tasks/k3m9p2qa", "https://yak.example.com/tasks/k3m9p2qa/",
            "https://yak.example.com/yak/tasks/k3m9p2qa",
        ] {
            #expect(TaskLink.id(from: try #require(URL(string: text))) == "k3m9p2qa")
        }
    }

    @Test(arguments: [
        "https://yak.example.com/tasks/k3m9p2q", "https://yak.example.com/tasks/k3m9p2qa1",
        "https://yak.example.com/tasks/k3m9-2qa", "https://yak.example.com/tasks/",
        "https://yak.example.com/threads/k3m9p2qa", "https://yak.example.com/tasks/k3m9p2qa/edit",
    ])
    func otherURLsGiveNil(_ text: String) throws {
        #expect(TaskLink.id(from: try #require(URL(string: text))) == nil)
    }
}
