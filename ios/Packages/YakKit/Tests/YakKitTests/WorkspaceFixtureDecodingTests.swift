import Foundation
import Testing

@testable import YakKit

@Test(arguments: [
    "thread-terminal-session", "thread-terminal-session-waiting", "error-sandbox-destroyed", "thread-files",
    "thread-file", "thread-file-binary", "thread-file-saved", "error-file-conflict",
    "error-sandbox-unavailable",
])
func decodesEveryWorkspaceFixture(name: String) throws {
    try #require(Fixtures.exists(name), "The server fixture \(name) is missing.")
    let data = try Fixtures.data(name)
    switch name {
    case "thread-terminal-session":
        guard case .ready = try JSONDecoder().decode(TerminalSessionAnswer.self, from: data) else {
            Issue.record("expected a ready answer")
            return
        }
    case "thread-terminal-session-waiting":
        guard case .waiting = try JSONDecoder().decode(TerminalSessionAnswer.self, from: data) else {
            Issue.record("expected a waiting answer")
            return
        }
    case "error-sandbox-destroyed":
        #expect(try #require(APIError.parse(status: 409, body: data)).code == "sandbox_destroyed")
    case "thread-files": _ = try JSONDecoder().decode(FileTreeFolder.self, from: data)
    case "thread-file", "thread-file-binary": _ = try JSONDecoder().decode(FileContent.self, from: data)
    case "thread-file-saved": _ = try JSONDecoder().decode(FileSaveResult.self, from: data)
    case "error-file-conflict":
        #expect(FileConflict(try #require(APIError.parse(status: 409, body: data))) != nil)
    default:
        #expect(SandboxUnavailable(try #require(APIError.parse(status: 409, body: data))) != nil)
    }
}

@Test func theThreadFixtureCarriesOwnerSide() throws {
    try #require(Fixtures.exists("thread"), "The server fixture thread is missing.")
    #expect(try JSONDecoder().decode(ThreadShow.self, from: Fixtures.data("thread")).thread.ownerSide != nil)
}
