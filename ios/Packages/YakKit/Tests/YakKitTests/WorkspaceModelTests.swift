import Foundation
import Testing

@testable import YakKit

private func apiError(_ name: String) throws -> APIError {
    try #require(APIError.parse(status: 409, body: Scenarios.data(name)))
}

private func jsonBody<R>(_ endpoint: Endpoint<R>) throws -> [String: Any] {
    let body = try #require(endpoint.body)
    return try #require(try JSONSerialization.jsonObject(with: JSONEncoder().encode(body)) as? [String: Any])
}

@Test func readsATerminalSession() throws {
    let answer = try decoded(TerminalSessionAnswer.self, "scenario-terminal-session")
    guard case .ready(let session) = answer else { throw URLError(.cannotParseResponse) }
    #expect(session.token == "t3rm7k3n")
    #expect(session.url == "wss://yak.example.com/terminal/ws?token=t3rm7k3n")
    #expect(session.expiresAt == "2026-11-16T10:15:00+00:00")
}

@Test func aWaitingAnswerCarriesItsLimit() throws {
    #expect(
        try decoded(TerminalSessionAnswer.self, "scenario-terminal-waiting")
            == .waiting(limit: .personShare, restoring: false))
    #expect(
        try decoded(TerminalSessionAnswer.self, "scenario-terminal-restoring")
            == .waiting(limit: nil, restoring: true))
    let withoutRestoring = Data(#"{"state": "waiting", "limit": null}"#.utf8)
    #expect(
        try JSONDecoder().decode(TerminalSessionAnswer.self, from: withoutRestoring)
            == .waiting(limit: nil, restoring: false))
    let unknown = Data(#"{"state": "waiting", "limit": "new_limit"}"#.utf8)
    let answer = try JSONDecoder().decode(TerminalSessionAnswer.self, from: unknown)
    #expect(answer == .waiting(limit: TerminalWaitLimit(rawValue: "new_limit"), restoring: false))
}

@Test func aBodyThatIsNeitherReadyNorWaitingFailsToDecode() {
    #expect(throws: (any Error).self) {
        try JSONDecoder().decode(TerminalSessionAnswer.self, from: Data(#"{"state": "other"}"#.utf8))
    }
}

@Test func readsTheTreeAndItsMarks() throws {
    let root = try decoded(FileTreeFolder.self, "scenario-file-tree-root")
    #expect(root.root == "/workspace")
    #expect(root.entries.map(\.name) == ["empty", "src", "tests", "README.md"])
    #expect(root.entries.map(\.isDirectory) == [true, true, true, false])
    #expect(root.entries.last?.size == 1532 && root.entries.first?.size == nil)
    #expect(root.changedPaths == ["src/Importer.php", "tests/ImporterTest.php"])
    #expect(root.addedPaths == ["tests/ImporterTest.php"])
    #expect(!root.truncated)
    let source = try decoded(FileTreeFolder.self, "scenario-file-tree-src")
    #expect(source.truncated && source.entries.count == 4)
    #expect(source.entries.first?.id == "src/Importer.php")
}

@Test func aTreeWithoutTruncatedReadsFalse() throws {
    let data = Data(#"{"root": "/workspace", "entries": []}"#.utf8)
    let folder = try JSONDecoder().decode(FileTreeFolder.self, from: data)
    #expect(!folder.truncated && folder.changedPaths.isEmpty && folder.addedPaths.isEmpty)
}

@Test func readsTextBinaryAndTooLargeFiles() throws {
    let text = try decoded(FileContent.self, "scenario-file-text")
    #expect(text.path == "src/Importer.php" && text.canEdit && text.language.rawValue == "php")
    #expect(text.sha256 == String(repeating: "0a1b2c3d", count: 8))
    #expect(text.content != nil && text.headContent != nil && text.content != text.headContent)
    #expect(text.body.sha256 == text.sha256 && text.body.content == text.content)
    let binary = try decoded(FileContent.self, "scenario-file-binary")
    #expect(binary.isBinary && !binary.canEdit && binary.content == nil && binary.language == .text)
    let large = try decoded(FileContent.self, "scenario-file-too-large")
    #expect(large.isTooLarge && large.size == 3_000_000 && large.content == nil && !large.canEdit)
}

@Test func readsASavedFile() throws {
    let saved = try decoded(FileSaveResult.self, "scenario-file-saved")
    #expect(saved.working && saved.file.sha256 == String(repeating: "4d5e6f70", count: 8))
}

@Test func sandboxUnavailableReadsItsDetails() throws {
    let waking = try #require(SandboxUnavailable(apiError("scenario-files-waking")))
    #expect(waking.state == .waking && waking.message == "The sandbox is waking up.")
    #expect(!waking.canRestore && !waking.waitingForSlot && !waking.restoring)
    let destroyed = try #require(SandboxUnavailable(apiError("scenario-files-destroyed")))
    #expect(destroyed.state == .destroyed && destroyed.canRestore)
    let asleep = try #require(SandboxUnavailable(apiError("scenario-files-asleep")))
    #expect(asleep.state == .asleep)
    #expect(asleep.message.hasPrefix("The sandbox is asleep."))
    let bare = APIError(
        status: 409, code: "sandbox_unavailable", message: "x",
        details: ["state": .string("later_state"), "canRestore": .bool(true)])
    let read = try #require(SandboxUnavailable(bare))
    #expect(read.state.rawValue == "later_state" && read.canRestore && !read.restoring)
    #expect(SandboxUnavailable(APIError(status: 403, code: "forbidden", message: "", details: [:])) == nil)
}

@Test func aConflictCarriesTheFileOnTheServer() throws {
    let conflict = try #require(FileConflict(apiError("scenario-file-conflict")))
    #expect(conflict.message == "The file changed since you opened it.")
    #expect(conflict.file?.sha256 == String(repeating: "7a8b9c0d", count: 8))
    #expect(conflict.file?.content?.contains("// Changed by Yak.") == true)
    #expect(FileConflict(APIError(status: 403, code: "forbidden", message: "", details: [:])) == nil)
}

@Test func aConflictWithoutAFileIsADeletion() throws {
    let error = APIError(
        status: 409, code: "file_conflict", message: "Gone.",
        details: ["state": .string("conflict"), "file": .null])
    #expect(try #require(FileConflict(error)).file == nil)
}

@Test func originDropsPathAndDefaultPort() throws {
    func origin(_ text: String) -> String? { TerminalOrigin.make(instance: URL(string: text)!) }
    #expect(origin("https://yak.example.com/sub/") == "https://yak.example.com")
    #expect(origin("https://Yak.Example.com:443") == "https://yak.example.com")
    #expect(origin("https://yak.example.com:8443") == "https://yak.example.com:8443")
    #expect(origin("http://127.0.0.1:8080") == "http://127.0.0.1:8080")
    #expect(origin("http://yak.example.com:80") == "http://yak.example.com")
    #expect(origin("file:///tmp/x") == nil)
}

@Test func endpointsCarryTheContract() throws {
    let open = Endpoint<TerminalSessionAnswer>.openTerminalSession("a/b", origin: "https://yak.example.com")
    #expect(open.method == .post && open.path == "threads/a%2Fb/terminal-sessions")
    #expect(open.headers == ["Origin": "https://yak.example.com"] && open.timeout == .seconds(60))
    #expect(try jsonBody(open).isEmpty)

    let tree = Endpoint<FileTreeFolder>.fileTree("t1", scope: .repo, path: "src")
    #expect(tree.method == .get && tree.path == "threads/t1/files" && tree.timeout == .seconds(60))
    #expect(tree.query == [URLQueryItem(name: "scope", value: "repo"), URLQueryItem(name: "path", value: "src")])
    #expect(!tree.retriesTransientFailures)
    let root = Endpoint<FileTreeFolder>.fileTree("t1", scope: .sandbox, path: "")
    #expect(root.query == [URLQueryItem(name: "scope", value: "sandbox")])

    let content = Endpoint<FileContent>.fileContent("t1", scope: .repo, path: "README.md")
    #expect(content.path == "threads/t1/files/content" && content.timeout == .seconds(60))
    #expect(content.query.map(\.name) == ["scope", "path"] && !content.retriesTransientFailures)

    let save = Endpoint<FileSaveResult>.saveFile(
        "t1", scope: .repo, path: "README.md", content: "text", baseSHA256: "abc", overwrite: false)
    #expect(save.method == .put && save.path == "threads/t1/files/content" && save.timeout == .seconds(60))
    #expect(save.retriesTransientFailures)
    let body = try jsonBody(save)
    #expect(Set(body.keys) == ["scope", "path", "content", "base_sha256", "overwrite"])
    #expect(body["scope"] as? String == "repo" && body["base_sha256"] as? String == "abc")
    #expect(body["overwrite"] as? Bool == false)
}

@Test func threadDetailReadsOwnerSideAndPreviewHints() throws {
    let show = try decoded(ThreadShow.self, "scenario-thread-show")
    #expect(show.thread.ownerSide == "yak" && show.thread.preview == nil)
    let hints = try JSONDecoder().decode(
        ThreadShow.self,
        from: Scenarios.data(
            "scenario-thread-show", setting: ["thread", "preview"],
            toJSON: #"{"port": 3000, "personAwakeCap": 4, "host": null}"#))
    #expect(hints.thread.preview == ThreadPreviewHints(port: 3000, personAwakeCap: 4))
    let without = try JSONDecoder().decode(
        ThreadShow.self,
        from: Scenarios.data("scenario-thread-show", setting: ["thread", "ownerSide"], toJSON: "null"))
    #expect(without.thread.ownerSide == nil)
}
