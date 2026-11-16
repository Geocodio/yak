import Foundation
import Testing

@testable import YakKit

extension ThreadList {
    fileprivate func with(needsYou: [NeedsYouItem]) -> ThreadList {
        ThreadList(
            starred: starred, needsYou: needsYou, threads: threads, needsYouCount: needsYou.count,
            hasAnyThreads: hasAnyThreads)
    }
    fileprivate func keepingNeedsYou(first count: Int) -> ThreadList { with(needsYou: Array(needsYou.prefix(count))) }
    fileprivate func droppingNeedsYouThreads() -> ThreadList {
        with(needsYou: needsYou.filter { if case .thread = $0 { false } else { true } })
    }
    fileprivate func withoutNeedsYou() -> ThreadList { with(needsYou: []) }
}

@Test func coldStartOpensTheOneThreadThatNeedsYou() throws {
    let list = try decoded(ThreadList.self, "scenario-thread-list").keepingNeedsYou(first: 1)
    #expect(ColdStart.destination(mine: list, restoredThreadID: "a1b2c3d4", link: nil) == .thread("d0e515ef"))
}

@Test func coldStartOpensTheDrawerForSeveralItemsOrOneTask() throws {
    let list = try decoded(ThreadList.self, "scenario-thread-list")
    #expect(ColdStart.destination(mine: list, restoredThreadID: nil, link: nil) == .drawer(.mine))
    let oneTask = list.droppingNeedsYouThreads()
    #expect(oneTask.needsYou.count == 1)
    #expect(ColdStart.destination(mine: oneTask, restoredThreadID: "a1b2c3d4", link: nil) == .drawer(.mine))
}

@Test func anUnknownItemCountsAsAnItem() throws {
    let list = try decoded(ThreadList.self, "scenario-thread-list").with(needsYou: [.unknown(type: "future")])
    #expect(ColdStart.destination(mine: list, restoredThreadID: "a1b2c3d4", link: nil) == .drawer(.mine))
}

@Test func coldStartFallsBackInOrder() throws {
    let quiet = try decoded(ThreadList.self, "scenario-thread-list").withoutNeedsYou()
    #expect(ColdStart.destination(mine: quiet, restoredThreadID: "a1b2c3d4", link: nil) == .thread("a1b2c3d4"))
    #expect(ColdStart.destination(mine: quiet, restoredThreadID: nil, link: nil) == .drawer(.mine))
    let teamOnly = ThreadList(starred: [], needsYou: [], threads: [], needsYouCount: 0, hasAnyThreads: true)
    #expect(ColdStart.destination(mine: teamOnly, restoredThreadID: nil, link: nil) == .drawer(.everyone))
    let nobody = ThreadList(starred: [], needsYou: [], threads: [], needsYouCount: 0, hasAnyThreads: false)
    #expect(ColdStart.destination(mine: nobody, restoredThreadID: nil, link: nil) == .newThread)
}

@Test func aLinkWinsOverColdStart() throws {
    let list = try decoded(ThreadList.self, "scenario-thread-list").keepingNeedsYou(first: 1)
    let thread = DeepLink(url: URL(string: "yak://thread?instance=https%3A%2F%2Fyak.example.com&id=e5f6g7h8")!)
    #expect(ColdStart.destination(mine: list, restoredThreadID: nil, link: thread) == .thread("e5f6g7h8"))
    let new = DeepLink(url: URL(string: "yak://new?instance=https%3A%2F%2Fyak.example.com")!)
    #expect(ColdStart.destination(mine: list, restoredThreadID: nil, link: new) == .newThread)
    let task = DeepLink(url: URL(string: "yak://task?instance=https%3A%2F%2Fyak.example.com&id=k3m9p2qa")!)
    #expect(ColdStart.destination(mine: list, restoredThreadID: nil, link: task) == .thread("d0e515ef"))
}
