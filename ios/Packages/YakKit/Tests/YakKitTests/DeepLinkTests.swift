import Foundation
import Testing

@testable import YakKit

private let instance = URL(string: "https://yak.example.com")!
private let base = "instance=https%3A%2F%2Fyak.example.com"

@Test func parsesEveryLinkForm() {
    #expect(
        DeepLink(url: URL(string: "yak://thread?\(base)&id=d0e515ef")!) == .thread(instance: instance, id: "d0e515ef"))
    #expect(DeepLink(url: URL(string: "YAK://Task?\(base)&id=k3m9p2qa")!) == .task(instance: instance, id: "k3m9p2qa"))
    #expect(DeepLink(url: URL(string: "yak://task?\(base)&id=881")!) == .task(instance: instance, id: "881"))
    #expect(
        DeepLink(url: URL(string: "yak://new?\(base)&repo=example/api&text=Fix%20the%20import")!)
            == .newThread(instance: instance, repo: "example/api", text: "Fix the import"))
    #expect(DeepLink(url: URL(string: "yak://new?\(base)")!) == .newThread(instance: instance, repo: nil, text: nil))
    let pair = DeepLink(url: URL(string: "yak://pair?url=https%3A%2F%2Fyak.example.com&code=abc123")!)
    #expect(pair == .pair(PairingLink(instance: instance, code: "abc123")))
}

@Test(arguments: [
    "yak://thread?instance=https%3A%2F%2Fyak.example.com&id=short", "yak://thread?id=d0e515ef",
    "yak://thread?instance=http%3A%2F%2Fyak.example.com&id=d0e515ef", "https://yak.example.com/threads/d0e515ef",
    "yak://thread?instance=https%3A%2F%2Fyak.example.com&id=d0e5%2F5ef", "yak://unknown?instance=x",
    "yak://task?instance=https%3A%2F%2Fyak.example.com&id=",
    "yak://task?instance=https%3A%2F%2Fyak.example.com&id=12a%2F4",
    "yak://task?instance=https%3A%2F%2Fyak.example.com&id=123456789012345678901",
    "yak://thread?instance=https%3A%2F%2Fyak.example.com&id=d0e515e%C3%A9",
])
func refusesMalformedLinks(text: String) { #expect(DeepLink(url: URL(string: text)!) == nil) }

@Test func aRelaxedNormalizerIsUsedWhenPassed() {
    let local = URL(string: "http://localhost:8080")!
    let link = DeepLink(url: URL(string: "yak://thread?instance=http%3A%2F%2Flocalhost%3A8080&id=d0e515ef")!) { _ in
        local
    }
    #expect(link == .thread(instance: local, id: "d0e515ef"))
}

@Test func pairLinksUseTheInjectedParser() {
    let url = URL(string: "yak://pair?url=http%3A%2F%2F127.0.0.1%3A1&code=abc")!
    #expect(DeepLink(url: url) == nil)
    let stub = PairingLink(instance: URL(string: "http://127.0.0.1:1")!, code: "abc")
    #expect(DeepLink(url: url, parsePair: { _ in stub }) == .pair(stub))
}

@Test func newThreadLinkDropsABadRepoAndCutsLongText() {
    #expect(
        DeepLink(url: URL(string: "yak://new?\(base)&repo=not%20a%20repo")!)
            == .newThread(instance: instance, repo: nil, text: nil))
    #expect(
        DeepLink(url: URL(string: "yak://new?\(base)&repo=a/b/c")!)
            == .newThread(instance: instance, repo: nil, text: nil))
    #expect(
        DeepLink(url: URL(string: "yak://new?\(base)&repo=../..")!)
            == .newThread(instance: instance, repo: nil, text: nil))
    let long = String(repeating: "a", count: 5000)
    let link = DeepLink(url: URL(string: "yak://new?\(base)&text=\(long)")!)
    #expect(link == .newThread(instance: instance, repo: nil, text: String(repeating: "a", count: 4000)))
    #expect(
        DeepLink(url: URL(string: "yak://new?\(base)&text=%20%20%20")!)
            == .newThread(instance: instance, repo: nil, text: nil))
}

@Test func aThreadLinkCarriesItsFocusTarget() {
    let link = DeepLink(url: URL(string: "yak://thread?\(base)&id=d0e515ef&fragment=card-question")!)
    #expect(link == .thread(instance: instance, id: "d0e515ef", fragment: "card-question"))
}

@Test(arguments: ["", "a%20b", "a%2Fb", "%23x", String(repeating: "a", count: 65)])
func aTargetThePageWouldRefuseIsDroppedAndTheThreadStillOpens(fragment: String) {
    let link = DeepLink(url: URL(string: "yak://thread?\(base)&id=d0e515ef&fragment=\(fragment)")!)
    #expect(link == .thread(instance: instance, id: "d0e515ef"))
}

@Test func aTaskLinkHasNoFocusTarget() {
    let link = DeepLink(url: URL(string: "yak://task?\(base)&id=881&fragment=card-question")!)
    #expect(link == .task(instance: instance, id: "881"))
}
