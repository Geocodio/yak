import Foundation
import Testing

@testable import YakKit

private let instance = UUID(uuidString: "11111111-1111-4111-8111-111111111111")!
private let otherInstance = UUID(uuidString: "22222222-2222-4222-8222-222222222222")!

private func detail(title: String, visibility: String = "shared") -> ThreadDetail {
    ThreadDetail(
        id: "abcd1234", name: "example", title: title, repo: "example/web", kind: "task", status: .open,
        visibility: visibility, sandboxState: "ready", runnerState: "idle", pullRequestCount: 0, isOwner: true,
        isStarred: false)
}

@Test func aThreadRequestRoundTrips() throws {
    let request = WindowRequest.thread(instance: instance, id: "abcd1234")
    let data = try JSONEncoder().encode(request)
    #expect(try JSONDecoder().decode(WindowRequest.self, from: data) == request)
}

@Test func aBlankRequestRoundTrips() throws {
    let request = WindowRequest.blank(UUID(uuidString: "33333333-3333-4333-8333-333333333333")!)
    let data = try JSONEncoder().encode(request)
    #expect(try JSONDecoder().decode(WindowRequest.self, from: data) == request)
}

@Test(
    arguments: [
        #"{"kind":"thread","instance":"11111111-1111-4111-8111-111111111111","id":"short"}"#,
        #"{"kind":"thread","instance":"11111111-1111-4111-8111-111111111111","id":"ab/d1234"}"#,
        #"{"kind":"thread","instance":"11111111-1111-4111-8111-111111111111","id":"abcd12345"}"#,
        #"{"kind":"thread","instance":"11111111-1111-4111-8111-111111111111","id":"abcd123٣"}"#,
        #"{"kind":"thread","instance":"not-a-uuid","id":"abcd1234"}"#,
        #"{"kind":"window"}"#,
        #"{"kind":"blank","nonce":"nope"}"#,
        "[]",
        String(repeating: "[", count: 500) + String(repeating: "]", count: 500),
        "",
    ])
func hostileValuesFailToDecode(_ json: String) {
    #expect(throws: (any Error).self) {
        try JSONDecoder().decode(WindowRequest.self, from: Data(json.utf8))
    }
}

@Test func aRequestForAnotherInstanceOpensNoThread() {
    #expect(WindowRequest.thread(instance: instance, id: "abcd1234").threadToOpen(activeInstance: instance) == "abcd1234")
    #expect(WindowRequest.thread(instance: instance, id: "abcd1234").threadToOpen(activeInstance: otherInstance) == nil)
    #expect(WindowRequest.blank(instance).threadToOpen(activeInstance: instance) == nil)
}

@Test func restoredThreadsStayOnlyOnTheirInstance() {
    #expect(WindowRequest.keepsRestoredThread(storedInstance: instance, activeInstance: instance))
    #expect(!WindowRequest.keepsRestoredThread(storedInstance: otherInstance, activeInstance: instance))
    #expect(WindowRequest.keepsRestoredThread(storedInstance: nil, activeInstance: instance))
}

@Test func privateTitlesStayInside() {
    #expect(WindowTitle.make(detail(title: "Fix the form", visibility: "private")) == .privateThread)
    #expect(WindowTitle.make(detail(title: "Fix the form")) == .thread("Fix the form"))
    #expect(WindowTitle.make(detail(title: String(repeating: "a", count: 300))) == .thread(String(repeating: "a", count: 120)))
    #expect(WindowTitle.make(detail(title: "   ")) == .none)
    #expect(WindowTitle.make(nil) == .none)
}
