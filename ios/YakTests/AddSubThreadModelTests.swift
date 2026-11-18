import Foundation
import Testing
import YakKit

@testable import Yak

private var scenarios: URL {
    var directory = URL(fileURLWithPath: #filePath)
    while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
    return directory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios")
}

private func scenario(_ name: String) -> String {
    (try? String(contentsOf: scenarios.appendingPathComponent("scenario-overview-\(name).json"), encoding: .utf8))
        ?? "{}"
}

/// Serves a stack and answers each add with the next scripted answer.
private final class AddTransport: HTTPTransport, @unchecked Sendable {
    enum Answer {
        case json(Int, String)
        case timeout
    }

    private let lock = NSLock()
    private var answers: [Answer] = []
    private(set) var addBodies: [String] = []
    var stackName = "busy-stack"
    var isOffline = false

    func queue(_ answer: Answer) { lock.withLock { answers.append(answer) } }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let url = request.url!
        if isOffline { throw URLError(.notConnectedToInternet) }
        var status = 200
        var body = ""
        if url.path.hasSuffix("/stack/children") {
            let answer = lock.withLock { () -> Answer in
                addBodies.append(request.httpBody.map { String(decoding: $0, as: UTF8.self) } ?? "")
                return answers.isEmpty ? .timeout : answers.removeFirst()
            }
            switch answer {
            case .timeout: throw URLError(.timedOut)
            case .json(let code, let text): (status, body) = (code, text)
            }
        } else if url.path.hasSuffix("/stack") {
            body = scenario(stackName)
        } else {
            throw URLError(.cancelled)
        }
        let response = HTTPURLResponse(
            url: url, statusCode: status, httpVersion: nil,
            headerFields: ["Content-Type": "application/json", "X-Yak-Protocol": "1"])!
        return (Data(body.utf8), response)
    }
}

private struct FastClock: YakClock {
    func now() -> Date { Date() }
    func sleep(for duration: Duration) async throws { try await Task.sleep(for: duration / 100) }
}

private func refusal(_ status: Int, _ code: String, _ message: String, details: String = "{}") -> AddTransport.Answer {
    .json(status, #"{"error":"\#(code)","message":"\#(message)","details":\#(details)}"#)
}

private let created = AddTransport.Answer.json(201, #"{"id":"n3wch11d","name":"check-the-cache"}"#)

@MainActor private struct Rig {
    let transport = AddTransport()
    let files = InMemoryFileStore()
    let instance = UUID()
    let actions: OverviewActions

    init(stackName: String = "busy-stack") {
        transport.stackName = stackName
        let client = APIClient(
            baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
            appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
        actions = OverviewActions(
            client: client, cache: Cache(instance: instance, files: files, clock: SystemClock()), clock: FastClock(),
            makeID: { UUID() })
    }

    func model() -> AddSubThreadModel {
        AddSubThreadModel(parentID: "s7ackr00", actions: actions, clock: FastClock())
    }

    func fill(_ model: AddSubThreadModel) {
        model.draft.name = "check-the-cache"
        model.draft.brief = "Look at the cache keys."
    }
}

@MainActor @Test func typedTextSurvivesClosingTheSheet() async {
    let rig = Rig()
    let first = rig.model()
    await first.start()
    rig.fill(first)
    first.draft.kind = .research
    await first.flush()
    let second = rig.model()
    await second.start()
    #expect(second.draft.name == "check-the-cache")
    #expect(second.draft.brief == "Look at the cache keys.")
    #expect(second.draft.kind == .research)
}

@MainActor @Test func aLateLoadDoesNotOverwriteTyping() async {
    let rig = Rig()
    await rig.actions.saveSubThreadDraft(SubThreadDraft(name: "old-name", brief: "Old brief."), parentID: "s7ackr00")
    let model = rig.model()
    model.draft.name = "typed-first"
    await model.start()
    #expect(model.draft.name == "typed-first")
}

@MainActor @Test func addClosesOnlyOnSuccess() async {
    let rig = Rig()
    rig.transport.queue(refusal(409, "stack_full", "This thread has started 12 of 12 sub-threads."))
    rig.transport.queue(created)
    let model = rig.model()
    await model.start()
    rig.fill(model)
    #expect(await model.add() == false)
    #expect(model.problem == "This thread has started 12 of 12 sub-threads.")
    #expect(model.draft.name == "check-the-cache" && model.draft.sendKey == nil)
    #expect(await model.add() == true)
    #expect(await rig.actions.subThreadDraft(parentID: "s7ackr00") == nil)
    #expect(rig.transport.addBodies.count == 2)
}

@MainActor @Test func anUnsureAddLocksTheFields() async {
    let rig = Rig()
    rig.transport.queue(.timeout)
    rig.transport.queue(created)
    let model = rig.model()
    await model.start()
    rig.fill(model)
    #expect(await model.add() == false)
    #expect(model.isUnsure)
    #expect(model.problem != nil)
    let relaunched = rig.model()
    await relaunched.start()
    #expect(relaunched.isUnsure)
    #expect(await relaunched.add() == true)
    #expect(rig.transport.addBodies.count == 2)
}

@MainActor @Test func fieldErrorsGoUnderTheirField() async {
    let rig = Rig()
    rig.transport.queue(
        refusal(
            422, "validation_failed", "Invalid.", details: #"{"errors":{"name":["The name has already been taken."]}}"#
        ))
    let model = rig.model()
    await model.start()
    rig.fill(model)
    #expect(await model.add() == false)
    #expect(model.fieldErrors["name"] == "The name has already been taken.")
    #expect(model.problem == nil)
}

@MainActor @Test func aBlankBriefIsCaughtBeforeSending() async {
    let rig = Rig()
    let model = rig.model()
    await model.start()
    model.draft.name = "check-the-cache"
    #expect(await model.add() == false)
    #expect(model.fieldErrors["brief"] == "Describe what it should do.")
    #expect(rig.transport.addBodies.isEmpty)
}

@MainActor @Test func aSubThreadParentOffersTheParent() async {
    let rig = Rig()
    rig.transport.queue(
        refusal(
            409, "sub_thread_cannot_spawn", "A sub-thread cannot start sub-threads.",
            details: #"{"parentId":"s7ackr00"}"#))
    let model = rig.model()
    await model.start()
    rig.fill(model)
    #expect(await model.add() == false)
    #expect(model.refusedParentID == "s7ackr00")
    #expect(model.parentToOpen == "s7ackr00")
    #expect(model.offersParent)
    #expect(model.problem == "A sub-thread cannot start sub-threads.")
}

@MainActor @Test func aServerNamedParentWorksWhenTheStackDidNotLoad() async {
    let rig = Rig(stackName: "not-a-scenario")
    rig.transport.queue(
        refusal(409, "sub_thread_cannot_spawn", "No.", details: #"{"parentId":"s7ackr00"}"#))
    let model = rig.model()
    await model.start()
    #expect(model.stack == nil)
    #expect(model.offersParent == false)
    rig.fill(model)
    #expect(await model.add() == false)
    #expect(model.offersParent)
    #expect(model.parentToOpen == "s7ackr00")
}

@MainActor @Test func aSubThreadsStackOffersTheParentWithoutAnAdd() async {
    let rig = Rig(stackName: "child-view")
    let model = rig.model()
    await model.start()
    #expect(model.isAddRefused)
    #expect(model.offersParent)
    #expect(model.parentToOpen == "s7ackr00")
    #expect(model.refusalSentence != nil)
}

private func sendKeys(_ bodies: [String]) -> [String] {
    bodies.compactMap { body in
        (try? JSONSerialization.jsonObject(with: Data(body.utf8)) as? [String: Any])?["send_key"] as? String
    }
}

@MainActor @Test func aSendKeyRefusalIsRetriedWithANewKey() async {
    let rig = Rig()
    let sendKeyRefusal = refusal(
        422, "validation_failed", "The send key has already been used.",
        details: #"{"errors":{"send_key":["The send key has already been used."]}}"#)
    rig.transport.queue(sendKeyRefusal)
    rig.transport.queue(created)
    let model = rig.model()
    await model.start()
    rig.fill(model)
    #expect(await model.add() == true)
    let keys = sendKeys(rig.transport.addBodies)
    #expect(keys.count == 2)
    #expect(Set(keys).count == 2)
}

@MainActor @Test func twoSendKeyRefusalsShowTheMessageWithoutAField() async {
    let rig = Rig()
    let sendKeyRefusal = refusal(
        422, "validation_failed", "The send key has already been used.",
        details: #"{"errors":{"send_key":["The send key has already been used."]}}"#)
    rig.transport.queue(sendKeyRefusal)
    rig.transport.queue(sendKeyRefusal)
    let model = rig.model()
    await model.start()
    rig.fill(model)
    #expect(await model.add() == false)
    #expect(model.problem == "The send key has already been used.")
    #expect(model.fieldErrors["send_key"] == nil)
    #expect(rig.transport.addBodies.count == 2)
}

@MainActor @Test func aDoubleTapAddsOnce() async {
    let rig = Rig()
    rig.transport.queue(created)
    let model = rig.model()
    await model.start()
    rig.fill(model)
    async let first = model.add()
    async let second = model.add()
    let results = await [first, second]
    #expect(results.filter { $0 }.count == 1)
    #expect(rig.transport.addBodies.count == 1)
}

@MainActor @Test func aCannotSpawnRefusalKeepsItsCode() async {
    let rig = Rig()
    rig.transport.queue(refusal(409, "sub_thread_cannot_spawn", "A sub-thread cannot start sub-threads."))
    let model = rig.model()
    await model.start()
    rig.fill(model)
    #expect(await model.add() == false)
    #expect(model.refusedCode == "sub_thread_cannot_spawn")
}

@MainActor @Test func aRefusedStackDisablesAdd() async {
    let rig = Rig(stackName: "stopped-stack")
    let model = rig.model()
    await model.start()
    #expect(model.isAddRefused)
    #expect(model.canAdd == false)
    #expect(model.refusalSentence != nil)
}

@MainActor @Test func offlineLeavesAddEnabledAndTheLoadOffline() async {
    let rig = Rig()
    rig.transport.isOffline = true
    let model = rig.model()
    await model.start()
    #expect(model.load == .failed(.offline))
    #expect(model.canAdd)
}
