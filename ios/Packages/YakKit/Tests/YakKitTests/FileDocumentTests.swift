import Foundation
import Testing

@testable import YakKit

private let threadID = "d0e515ef"
private let getRoute = "GET threads/\(threadID)/files/content"
private let putRoute = "PUT threads/\(threadID)/files/content"
private let mine = "<?php\n// mine\n"
private let hashOfMine = String(repeating: "7a8b9c0d", count: 8)
private let hashOfSaved = String(repeating: "4d5e6f70", count: 8)
private let hashOfLoaded = String(repeating: "0a1b2c3d", count: 8)

private func scenario(_ status: Int, _ name: String, setting path: [String] = [], to json: String = "null")
    -> FakeTransport.Result
{
    do {
        let data = path.isEmpty ? try Scenarios.data(name) : try Scenarios.data(name, setting: path, toJSON: json)
        return .json(status, String(decoding: data, as: UTF8.self))
    } catch {
        Issue.record("Missing scenario \(name)")
        return .json(500, "{}")
    }
}

private func jsonString(_ text: String) -> String {
    let data = (try? JSONEncoder().encode(text)) ?? Data()
    return String(decoding: data, as: UTF8.self)
}

private struct Rig {
    let document: FileDocument
    let transport: ScriptedTransport
    let cache: Cache
    let clock: ManualClock
    let files: InMemoryFileStore

    func current() async -> FileDocumentSnapshot {
        for await snapshot in await document.snapshots() { return snapshot }
        return FileDocumentSnapshot(path: "")
    }

    func puts() async -> [[String: Any]] {
        await transport.requests(on: putRoute).compactMap { request in
            request.httpBody.flatMap { try? JSONSerialization.jsonObject(with: $0) as? [String: Any] }
        }
    }

    func draft() async -> FileEditDraft? {
        await cache.fileEditDraft(thread: threadID, scope: .repo, path: "src/Importer.php")
    }

    /// Opens the document, loads it and starts editing with `text`.
    func startEditing(with text: String = mine) async {
        await document.load()
        _ = await document.beginEditing()
        await document.updateText(text)
    }
}

private func makeRig(
    get: [FakeTransport.Result] = [scenario(200, "scenario-file-text")], put: [FakeTransport.Result] = [],
    cache: Cache? = nil, files: InMemoryFileStore = InMemoryFileStore(), clock: ManualClock = ManualClock(),
    transport: ScriptedTransport? = nil, timings: FileTimings = .standard
) async -> Rig {
    let isReused = transport != nil
    let transport = transport ?? ScriptedTransport()
    if !isReused { await transport.on(getRoute, results: get) }
    if !put.isEmpty { await transport.on(putRoute, results: put) }
    let cache = cache ?? Cache(instance: UUID(), files: files, clock: clock)
    let client = APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
    let document = FileDocument(
        threadID: threadID, scope: .repo, path: "src/Importer.php", client: client, cache: cache, clock: clock,
        timings: timings)
    return Rig(document: document, transport: transport, cache: cache, clock: clock, files: files)
}

@Suite struct FileDocumentTests {
    @Test func opensEachKindOfFile() async {
        let text = await makeRig()
        await text.document.load()
        guard case .text(let content) = await text.current().phase else {
            Issue.record("not text")
            return
        }
        #expect(content.path == "src/Importer.php")

        let binary = await makeRig(get: [scenario(200, "scenario-file-binary")])
        await binary.document.load()
        guard case .binary = await binary.current().phase else {
            Issue.record("not binary")
            return
        }

        let large = await makeRig(get: [scenario(200, "scenario-file-too-large")])
        await large.document.load()
        guard case .tooLarge = await large.current().phase else {
            Issue.record("not too large")
            return
        }

        let missing = await makeRig(get: [.json(404, #"{"error":"not_found","message":"Not found."}"#)])
        await missing.document.load()
        #expect(await missing.current().phase == .notFound)
    }

    @Test func aViewerNeverEdits() async {
        let viewer = await makeRig(get: [scenario(200, "scenario-file-text", setting: ["canEdit"], to: "false")])
        await viewer.document.load()
        #expect(await viewer.document.beginEditing() == false)
        let snapshot = await viewer.current()
        #expect(snapshot.canEdit == false)
        #expect(snapshot.edit == .notEditing)

        let refused = await makeRig(
            put: [.json(403, #"{"error":"forbidden","message":"You cannot change this thread."}"#)])
        await refused.startEditing()
        await refused.document.save()
        let after = await refused.current()
        #expect(after.edit == .saveFailed(.forbidden))
        #expect(after.text == mine)
    }

    @Test func savesAndTakesTheNewBase() async {
        let rig = await makeRig(put: [scenario(200, "scenario-file-saved")])
        await rig.startEditing()
        await rig.document.save()
        let saved = await rig.current()
        #expect(saved.edit == .saved(working: true))
        #expect(await rig.draft() == nil)
        guard case .text(let content) = saved.phase else {
            Issue.record("not text")
            return
        }
        #expect(content.sha256 == hashOfSaved)

        await rig.document.updateText(mine + "more\n")
        await rig.document.save()
        let puts = await rig.puts()
        #expect(puts.count == 2)
        #expect(puts[0]["base_sha256"] as? String == hashOfLoaded)
        #expect(puts[0]["overwrite"] as? Bool == false)
        #expect(puts[1]["base_sha256"] as? String == hashOfSaved)
        #expect(puts[1]["content"] as? String == mine + "more\n")
    }

    @Test func aRepeatedSaveAfterALostAnswerIsSaved() async {
        let own = scenario(
            409, "scenario-file-conflict", setting: ["details", "file", "content"], to: jsonString(mine))
        let rig = await makeRig(put: [.failure(.networkConnectionLost), own])
        await rig.startEditing()
        await rig.document.save()
        #expect(await rig.current().edit == .saveFailed(.offline))
        #expect(await rig.draft()?.text == mine)
        await rig.document.save()
        let snapshot = await rig.current()
        #expect(snapshot.edit == .saved(working: false))
        guard case .text(let content) = snapshot.phase else {
            Issue.record("not text")
            return
        }
        #expect(content.sha256 == hashOfMine)
        #expect(await rig.draft() == nil)
        #expect(await rig.puts().count == 2)
    }

    @Test func aRealConflictOpensTheCompare() async {
        let rig = await makeRig(put: [scenario(409, "scenario-file-conflict")])
        await rig.startEditing()
        await rig.document.save()
        let snapshot = await rig.current()
        guard case .conflict(let theirs?) = snapshot.edit else {
            Issue.record("no conflict")
            return
        }
        #expect(theirs.content?.contains("Changed by Yak.") == true)
        #expect(snapshot.text == mine)
    }

    @Test func keepMineOverwrites() async {
        let rig = await makeRig(put: [scenario(409, "scenario-file-conflict"), scenario(200, "scenario-file-saved")])
        await rig.startEditing()
        await rig.document.save()
        await rig.document.keepMine()
        let puts = await rig.puts()
        #expect(puts.count == 2)
        #expect(puts[1]["overwrite"] as? Bool == true)
        #expect(puts[1]["base_sha256"] as? String == hashOfMine)
        #expect(puts[1]["content"] as? String == mine)
        #expect(await rig.current().edit == .saved(working: true))
    }

    @Test func useTheirsDropsTheDraft() async {
        let rig = await makeRig(put: [scenario(409, "scenario-file-conflict")])
        await rig.startEditing()
        await rig.document.save()
        #expect(await rig.draft() != nil)
        await rig.document.useTheirs()
        let snapshot = await rig.current()
        #expect(snapshot.edit == .notEditing)
        #expect(snapshot.text == nil)
        guard case .text(let content) = snapshot.phase else {
            Issue.record("not text")
            return
        }
        #expect(content.sha256 == hashOfMine)
        #expect(await rig.draft() == nil)
    }

    @Test func aDeletedFileCanBeCreatedAgain() async {
        let deleted = scenario(409, "scenario-file-conflict", setting: ["details", "file"], to: "null")
        let rig = await makeRig(put: [deleted, scenario(200, "scenario-file-saved")])
        await rig.startEditing()
        await rig.document.save()
        #expect(await rig.current().edit == .conflict(theirs: nil))
        await rig.document.keepMine()
        let puts = await rig.puts()
        #expect(puts[1]["base_sha256"] as? String == "")
        #expect(puts[1]["overwrite"] as? Bool == true)
    }

    @Test func theEditorNeverLosesText() async {
        let rig = await makeRig(
            put: [
                .failure(.notConnectedToInternet), scenario(409, "scenario-files-asleep"),
                .json(409, #"{"error":"thread_closed","message":"This thread is closed."}"#),
            ])
        await rig.startEditing()
        await rig.document.save()
        #expect(await rig.current().edit == .saveFailed(.offline))
        #expect(await rig.current().text == mine)
        await rig.document.save()
        guard case .saveFailed(.unavailable(.asleep)) = await rig.current().edit else {
            Issue.record("not asleep")
            return
        }
        #expect(await rig.current().text == mine)
        await rig.document.save()
        #expect(await rig.current().edit == .saveFailed(.threadClosed))
        #expect(await rig.current().text == mine)
        await rig.document.close()

        let reopened = await makeRig(cache: rig.cache, clock: rig.clock, transport: rig.transport)
        await reopened.document.load()
        let snapshot = await reopened.current()
        #expect(snapshot.text == mine)
        #expect(snapshot.restoredDraft)
        #expect(snapshot.edit == .editing)
    }

    @Test func aStaleDraftOpensTheCompare() async {
        let rig = await makeRig(get: [scenario(200, "scenario-file-text", setting: ["sha256"], to: "\"\(hashOfMine)\"")]
        )
        await rig.cache.saveFileEditDraft(
            FileEditDraft(
                scope: .repo, path: "src/Importer.php", baseSHA256: hashOfLoaded, text: mine, savedAt: rig.clock.now()),
            thread: threadID)
        await rig.document.load()
        let snapshot = await rig.current()
        guard case .conflict(let theirs?) = snapshot.edit else {
            Issue.record("no conflict")
            return
        }
        #expect(theirs.sha256 == hashOfMine)
        #expect(snapshot.text == mine)
        #expect(snapshot.restoredDraft)
    }

    @Test func aDraftIsWrittenAfterTypingStops() async {
        let rig = await makeRig()
        await rig.document.load()
        _ = await rig.document.beginEditing()
        await rig.document.updateText("a")
        await rig.clock.waitForSleeper(endingAt: rig.clock.offset + .seconds(1))
        await rig.clock.advance(by: .milliseconds(500))
        await rig.document.updateText("ab")
        await rig.clock.waitForSleeper(endingAt: .milliseconds(1500))
        await rig.clock.advance(by: .milliseconds(400))
        #expect(await rig.draft() == nil)
        await rig.clock.advance(by: .milliseconds(600))
        for _ in 0..<1000 where await rig.draft() == nil { await Task.yield() }
        #expect(await rig.draft()?.text == "ab")
    }

    @Test func quietReloadNeverTouchesTheText() async {
        let changed = scenario(200, "scenario-file-text", setting: ["sha256"], to: "\"\(hashOfMine)\"")
        let editing = await makeRig(get: [scenario(200, "scenario-file-text"), changed])
        await editing.startEditing()
        await editing.document.reloadIfUnchanged()
        let snapshot = await editing.current()
        #expect(snapshot.changedOnServer)
        #expect(snapshot.text == mine)

        let viewing = await makeRig(get: [scenario(200, "scenario-file-text"), changed])
        await viewing.document.load()
        await viewing.document.reloadIfUnchanged()
        guard case .text(let content) = await viewing.current().phase else {
            Issue.record("not text")
            return
        }
        #expect(content.sha256 == hashOfMine)
    }

    @Test func lineEndingsAreKept() async {
        let rig = await makeRig(put: [scenario(200, "scenario-file-saved")])
        await rig.startEditing(with: "a\r\nb")
        await rig.document.save()
        #expect(await rig.puts().first?["content"] as? String == "a\r\nb")
    }

    @Test func anOversizedTextIsRefusedBeforeSending() async {
        let rig = await makeRig(put: [scenario(200, "scenario-file-saved")])
        let text = String(repeating: "é", count: 500_000) + "a"
        #expect(text.utf8.count == FileEditDraft.maximumBytes + 1)
        await rig.startEditing(with: text)
        await rig.document.save()
        let snapshot = await rig.current()
        #expect(snapshot.edit == .saveFailed(.tooLarge))
        #expect(snapshot.text == text)
        #expect(await rig.puts().isEmpty)
    }

    @Test func aContentValidationErrorIsTooLarge() async {
        let sentence = "The file is too large to save from the app."
        let content = await makeRig(
            put: [
                .json(
                    422,
                    #"{"error":"validation_failed","message":"Invalid.","details":{"errors":{"content":["\#(sentence)"]}}}"#
                )
            ])
        await content.startEditing()
        await content.document.save()
        #expect(await content.current().edit == .saveFailed(.tooLarge))

        let path = await makeRig(
            put: [
                .json(
                    422,
                    #"{"error":"validation_failed","message":"Invalid.","details":{"errors":{"path":["\#(sentence)"]}}}"#
                )
            ])
        await path.startEditing()
        await path.document.save()
        #expect(await path.current().edit == .saveFailed(.invalid(message: sentence)))
    }
}
