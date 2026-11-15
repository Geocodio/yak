import Foundation
import Testing

@testable import YakKit

private let instance = URL(string: "https://yak.example.com")!
private let base = "instance=https%3A%2F%2Fyak.example.com"

@Test func parsesEveryLinkForm() {
    #expect(
        DeepLink(url: URL(string: "yak://thread?\(base)&id=d0e515ef")!) == .thread(instance: instance, id: "d0e515ef"))
    #expect(DeepLink(url: URL(string: "YAK://Task?\(base)&id=k3m9p2qa")!) == .task(instance: instance, id: "k3m9p2qa"))
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

@Test func newThreadLinkDropsABadRepoAndCutsLongText() {
    #expect(
        DeepLink(url: URL(string: "yak://new?\(base)&repo=not%20a%20repo")!)
            == .newThread(instance: instance, repo: nil, text: nil))
    #expect(
        DeepLink(url: URL(string: "yak://new?\(base)&repo=a/b/c")!)
            == .newThread(instance: instance, repo: nil, text: nil))
    let long = String(repeating: "a", count: 5000)
    let link = DeepLink(url: URL(string: "yak://new?\(base)&text=\(long)")!)
    #expect(link == .newThread(instance: instance, repo: nil, text: String(repeating: "a", count: 4000)))
    #expect(
        DeepLink(url: URL(string: "yak://new?\(base)&text=%20%20%20")!)
            == .newThread(instance: instance, repo: nil, text: nil))
}
