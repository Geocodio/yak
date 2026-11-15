import Foundation
import Testing

@testable import YakStub

@Suite struct WorkspaceRoutesTests {
    private static let origin = "https://stub.example.test"

    private func workspace(_ variant: WorkspaceVariant = .workspace) -> StubWorkspace {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "Packages" { directory.deleteLastPathComponent() }
        let scenarios = directory.appendingPathComponent("YakKit/Tests/YakKitTests/Scenarios")
        let stub = StubWorkspace(
            load: { try? Data(contentsOf: scenarios.appendingPathComponent("\($0).json")) }, ownOrigin: Self.origin)
        stub.reset(variant, bridgePort: 4040)
        return stub
    }

    private func request(
        _ method: String, _ path: String, query: [String: String] = [:], origin: String? = Self.origin,
        body: [String: Any]? = nil
    ) -> WorkspaceRequest {
        WorkspaceRequest(
            method: method, path: path, query: query, headers: origin.map { ["origin": $0] } ?? [:],
            body: body.flatMap { try? JSONSerialization.data(withJSONObject: $0) })
    }

    private func json(_ answer: WorkspaceAnswer?) -> [String: Any] {
        (try? JSONSerialization.jsonObject(with: answer?.body ?? Data())) as? [String: Any] ?? [:]
    }

    private func code(_ answer: WorkspaceAnswer?) -> String? { json(answer)["error"] as? String }

    private func terminal(_ stub: StubWorkspace, _ id: String = "d0e515ef") -> WorkspaceAnswer? {
        stub.answer(request("POST", "threads/\(id)/terminal-sessions"), canDrive: true)
    }

    private func files(
        _ stub: StubWorkspace, _ id: String = "d0e515ef", _ query: [String: String] = ["scope": "repo"],
        canDrive: Bool = true
    ) -> WorkspaceAnswer? {
        stub.answer(request("GET", "threads/\(id)/files", query: query), canDrive: canDrive)
    }

    private func put(_ stub: StubWorkspace, _ text: String, canDrive: Bool = true) -> WorkspaceAnswer? {
        stub.answer(
            request(
                "PUT", "threads/d0e515ef/files/content",
                body: ["scope": "repo", "path": "src/Importer.php", "content": text, "base_sha256": "x"]),
            canDrive: canDrive)
    }

    private func read(_ stub: StubWorkspace, _ path: String) -> WorkspaceAnswer? {
        stub.answer(
            request("GET", "threads/d0e515ef/files/content", query: ["scope": "repo", "path": path]), canDrive: true)
    }

    @Test func terminalNeedsTheOrigin() {
        let stub = workspace()
        let refused = stub.answer(request("POST", "threads/d0e515ef/terminal-sessions", origin: nil), canDrive: true)
        #expect(refused?.status == 403)
        #expect(code(refused) == "origin_not_allowed")
        let opened = terminal(stub)
        #expect(opened?.status == 200)
        #expect((json(opened)["url"] as? String)?.hasPrefix("ws://127.0.0.1:4040/terminal/ws?token=") == true)
    }

    @Test func tokensCount() {
        let stub = workspace()
        #expect(json(terminal(stub))["token"] as? String == "stub-terminal-1")
        #expect(json(terminal(stub))["token"] as? String == "stub-terminal-2")
        #expect(stub.admit(token: "stub-terminal-1") == .open)
        #expect(stub.admit(token: "stub-terminal-1") == .refuse)
        #expect(stub.admit(token: "nope") == .refuse)
    }

    @Test(arguments: [
        ("term0rgn", 403, "origin_not_allowed"), ("termf0rb", 403, "forbidden"), ("termc10s", 409, "thread_closed"),
        ("termdev1", 409, "thread_owned_by_device"), ("termg0ne", 409, "sandbox_destroyed"),
        ("termn0sb", 409, "no_sandbox"), ("termb1kd", 409, "thread_blocked"),
    ])
    func everyTerminalStateHasItsAnswer(id: String, status: Int, errorCode: String) {
        let answer = terminal(workspace(.terminalStates), id)
        #expect(answer?.status == status)
        #expect(code(answer) == errorCode)
    }

    @Test func aBlockedThreadSaysWhy() {
        let answer = terminal(workspace(.terminalStates), "termb1kd")
        #expect((json(answer)["details"] as? [String: Any])?["reason"] as? String == "restore_failed")
        #expect(json(answer)["message"] as? String == "The sandbox could not be restored.")
    }

    @Test func tooManyShellsAdmitsAsTooMany() {
        let stub = workspace(.terminalStates)
        let token = json(terminal(stub, "term4429"))["token"] as? String ?? ""
        #expect(stub.admit(token: token) == .tooMany)
    }

    @Test func aRemovedSandboxRestores() {
        let stub = workspace(.terminalStates)
        #expect(terminal(stub, "termg0ne")?.status == 409)
        stub.restoreAsked(thread: "termg0ne")
        for _ in 0..<2 {
            let waiting = terminal(stub, "termg0ne")
            #expect(waiting?.status == 202)
            #expect(json(waiting)["restoring"] as? Bool == true)
        }
        #expect(terminal(stub, "termg0ne")?.status == 200)
    }

    @Test func aRestoreCanTakeForever() {
        let stub = workspace(.terminalRestoringForever)
        stub.restoreAsked(thread: "termg0ne")
        for _ in 0..<6 { #expect(terminal(stub, "termg0ne")?.status == 202) }
    }

    @Test func waitingAnswersFourTimesThenOpens() {
        let stub = workspace(.terminalWaiting)
        for _ in 0..<4 { #expect(terminal(stub)?.status == 202) }
        #expect(terminal(stub)?.status == 200)
        let forever = workspace(.terminalWaitingForever)
        for _ in 0..<6 { #expect(terminal(forever)?.status == 202) }
    }

    @Test func treeAnswersPerPath() {
        let stub = workspace()
        #expect(files(stub)?.status == 200)
        let root = json(files(stub))["entries"] as? [[String: Any]] ?? []
        #expect(root.contains { $0["name"] as? String == "README.md" })
        let source = json(files(stub, "d0e515ef", ["scope": "repo", "path": "src"]))
        #expect(source["truncated"] as? Bool == true)
        #expect((source["entries"] as? [[String: Any]])?.contains { $0["name"] as? String == "Gone.php" } == true)
        let empty = json(files(stub, "d0e515ef", ["scope": "repo", "path": "empty"]))
        #expect((empty["entries"] as? [Any])?.isEmpty == true)
        #expect(files(stub, "d0e515ef", ["scope": "repo", "path": "nowhere"])?.status == 404)
        #expect(files(stub, "d0e515ef", ["scope": "sandbox"], canDrive: false)?.status == 403)
        #expect(json(files(stub, "d0e515ef", ["scope": "sandbox"]))["root"] as? String == "/")
    }

    @Test func filesWakeThenServe() {
        let stub = workspace(.filesStates)
        #expect(files(stub, "fwak1ng0")?.status == 409)
        #expect(files(stub, "fwak1ng0")?.status == 409)
        #expect(files(stub, "fwak1ng0")?.status == 200)
    }

    @Test func everyFilesStateHasItsAnswer() {
        let stub = workspace(.filesStates)
        func details(_ id: String) -> [String: Any] { json(files(stub, id))["details"] as? [String: Any] ?? [:] }
        #expect(details("fs10t000")["waitingForSlot"] as? Bool == true)
        #expect(details("fas1eep0")["state"] as? String == "asleep")
        #expect(details("fg0ne000")["state"] as? String == "destroyed")
        #expect(details("fr3st0re")["restoring"] as? Bool == true)
        #expect(details("fc10sed0")["state"] as? String == "closed_destroyed")
        #expect(details("fdev1ce0")["state"] as? String == "on_device")
        stub.restoreAsked(thread: "fg0ne000")
        #expect(details("fg0ne000")["restoring"] as? Bool == true)
        #expect(details("fg0ne000")["restoring"] as? Bool == true)
        #expect(files(stub, "fg0ne000")?.status == 200)
    }

    @Test func aFailedCommandUsesTheContractSentence() {
        let answer = files(workspace(.filesStates), "fbr0ken0")
        #expect(answer?.status == 503)
        #expect(code(answer) == "unavailable")
        #expect(json(answer)["message"] as? String == "The sandbox did not answer. Try again in a moment.")
    }

    @Test func aSaveIsReadBack() {
        let stub = workspace()
        #expect(put(stub, "changed\n")?.status == 200)
        #expect(json(read(stub, "src/Importer.php"))["content"] as? String == "changed\n")
        #expect(stub.putCount == 1)
    }

    @Test func theLostSaveScenarioEchoesTheText() {
        let stub = workspace(.filesLostSave)
        #expect(put(stub, "mine\n")?.dropsConnection == true)
        let second = put(stub, "mine\n")
        #expect(second?.status == 409)
        let file = (json(second)["details"] as? [String: Any])?["file"] as? [String: Any]
        #expect(file?["content"] as? String == "mine\n")
        #expect(stub.putCount == 2)
    }

    @Test func theConflictScenarioConflictsOnce() {
        let stub = workspace(.filesConflict)
        #expect(code(put(stub, "a")) == "file_conflict")
        #expect(put(stub, "a")?.status == 200)
    }

    @Test func aGoneFileIsNotFound() {
        let stub = workspace()
        #expect(read(stub, "src/Gone.php")?.status == 404)
        #expect(read(stub, "src/logo.png")?.status == 200)
        #expect(json(read(stub, "src/logo.png"))["isBinary"] as? Bool == true)
        #expect(json(read(stub, "src/sample.csv"))["isTooLarge"] as? Bool == true)
    }

    @Test func aReadOnlyThreadCannotEdit() {
        let stub = workspace(.filesReadOnly)
        #expect(json(read(stub, "src/Importer.php"))["canEdit"] as? Bool == false)
        #expect(code(put(stub, "x", canDrive: false)) == "forbidden")
    }

    @Test func saveRefusalScenarios() {
        #expect(code(put(workspace(.filesSaveClosed), "x")) == "thread_closed")
        let asleep = put(workspace(.filesSaveAsleep), "x")
        #expect(code(asleep) == "sandbox_unavailable")
        #expect((json(asleep)["details"] as? [String: Any])?["state"] as? String == "asleep")
    }

    @Test func delaysAreCarried() {
        let waking = workspace(.terminalWaking)
        #expect(terminal(waking)?.delay == .seconds(3))
        #expect(files(waking)?.delay == .seconds(3))
        #expect(terminal(workspace(.terminalSlowWake))?.delay == .seconds(34))
        #expect(terminal(workspace())?.delay == .zero)
    }

    @Test func resetClearsTheState() {
        let stub = workspace()
        _ = terminal(stub)
        _ = put(stub, "x")
        stub.reset(.workspace, bridgePort: 4040)
        #expect(stub.putCount == 0)
        #expect(json(terminal(stub))["token"] as? String == "stub-terminal-1")
    }

    @Test func notAWorkspaceRoute() {
        let stub = workspace()
        #expect(stub.answer(request("GET", "threads/d0e515ef"), canDrive: true) == nil)
        #expect(stub.answer(request("GET", "me"), canDrive: true) == nil)
    }
}
