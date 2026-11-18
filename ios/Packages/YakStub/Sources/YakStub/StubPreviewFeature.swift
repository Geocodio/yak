import Foundation

/// The preview scenarios: wires `StubPreview` (the preview API answers) and `StubPreviewPages` (the preview host)
/// into the stub. Each `preview-*` scenario plays a core scenario underneath and edits the thread's show.
final class StubPreviewFeature: StubFeature, @unchecked Sendable {
    let name = "preview"
    let scenarios = StubPreviewPlay.allCases.map(\.rawValue)
    let fixturePairs = [
        StubFixturePair(server: "thread-preview-status", scenario: "scenario-preview-ready"),
        StubFixturePair(server: "thread-preview-status-starting", scenario: "scenario-preview-starting"),
        StubFixturePair(server: "thread-preview-status-failing", scenario: "scenario-preview-fixing"),
        StubFixturePair(server: "thread-preview-ticket", scenario: "scenario-preview-ticket"),
        StubFixturePair(server: "thread-preview-restore", scenario: "scenario-preview-restore"),
    ]

    let scenarioDataNames = [
        "scenario-preview-waking", "scenario-preview-waiting-for-slot", "scenario-preview-failing",
        "scenario-preview-no-manifest", "scenario-preview-invalid-manifest", "scenario-preview-destroyed",
        "scenario-preview-closed", "scenario-preview-local-machine",
    ]

    private static let threadID = "d0e515ef"
    private let fixtures = Locked<StubFixtures?>(nil)
    private let preview: StubPreview
    private let pages: StubPreviewPages

    init() {
        let fixtures = fixtures
        let preview = StubPreview { name in fixtures.get()?.scenarioFiles[name] }
        self.preview = preview
        pages = StubPreviewPages(preview: preview, apiBaseURL: "http://127.0.0.1:0")
    }

    func start() async throws { _ = try await pages.start() }

    func played(scenario: String) { preview.begin(StubPreviewPlay(rawValue: scenario)) }

    func setOnline(_ isOnline: Bool) { pages.setOnline(isOnline) }

    /// Only a scenario that plays a core scenario other than `paired` names one.
    func coreScenario(for scenario: String) -> String? {
        switch StubPreviewPlay(rawValue: scenario) {
        case .working: "one-thread-held"
        case .question: "question-open"
        default: nil
        }
    }

    func control(_ request: StubRequest) -> StubReply? {
        guard request.path == "/__stub/preview/log" else { return nil }
        return StubReply(
            answer: .reply(status: 200, contentType: "text/plain", body: Data(preview.log.joined(separator: "\n").utf8))
        )
    }

    func answer(route: String, request: StubRequest, context: StubContext) -> StubReply? {
        fixtures.set(context.fixtures)
        pages.setAPIBaseURL(context.baseURL)
        let isPreviewScenario = StubPreviewPlay(rawValue: context.scenario) != nil
        if route.hasPrefix("POST threads/"), route.hasSuffix("/messages") {
            let body = String(decoding: request.body, as: UTF8.self)
            let intent = body.contains("fix_preview") ? "fix_preview" : nil
            let id = request.path.dropFirst(Scenario.apiPrefix.count).split(separator: "/").dropFirst().first
            preview.messageArrived(threadID: id.map(String.init) ?? Self.threadID, intent: intent)
            return nil
        }
        if context.scenario == "server-fixtures" { return serverFixtureReply(route: route, context: context) }
        guard isPreviewScenario else { return nil }
        if route == "GET threads/\(Self.threadID)" { return editedShow(context: context) }
        return preview.reply(to: request)
    }

    // MARK: Show

    private func editedShow(context: StubContext) -> StubReply? {
        guard case .reply(let status, let contentType, let body) = context.core(), status == 200,
            var show = (try? JSONSerialization.jsonObject(with: body)) as? [String: Any]
        else { return nil }
        var panes = show["panes"] as? [String: Any] ?? [:]
        panes["preview"] = true
        if StubPreviewPlay(rawValue: context.scenario) == .failingWithFiles { panes["files"] = true }
        show["panes"] = panes
        var thread = show["thread"] as? [String: Any] ?? [:]
        var composer = show["composer"] as? [String: Any] ?? [:]
        switch StubPreviewPlay(rawValue: context.scenario) {
        case .restoreFailed:
            thread["blockedReason"] = "restore_failed"
            thread["sandboxState"] = "running"
            show["notice"] = [
                "kind": "restore_failed", "title": "Rebuilding the sandbox stopped",
                "body": "Retry to rebuild it.", "resetsAt": NSNull(), "canContinue": false,
            ]
        case .readOnly:
            show["canDrive"] = false
            composer["state"] = "readonly"
        case .destroyed:
            thread["sandboxState"] = "destroyed"
        case .closed:
            thread["status"] = "closed"
            composer["state"] = "disabled_closed"
        case .localMachine:
            thread["ownerSide"] = "device"
            composer["state"] = "disabled_computer"
        default:
            break
        }
        show["thread"] = thread
        show["composer"] = composer
        guard let edited = try? JSONSerialization.data(withJSONObject: show) else { return nil }
        return StubReply(answer: .reply(status: status, contentType: contentType, body: edited))
    }

    // MARK: Server fixtures

    /// The `server-fixtures` scenario answers the preview routes with the server's own fixtures.
    private func serverFixtureReply(route: String, context: StubContext) -> StubReply? {
        let base = "\(Self.threadID)/preview/"
        func json(_ name: String, status: Int = 200) -> StubReply {
            var object = context.fixtures.serverFirst(name, baseURL: context.baseURL)
            if object["url"] is String { object["url"] = preview.pagesBaseURL + "/" }
            let data = (try? JSONSerialization.data(withJSONObject: object)) ?? Data("{}".utf8)
            return StubReply(answer: .reply(status: status, contentType: "application/json", body: data))
        }
        switch route {
        case "GET threads/\(base)status": return json("thread-preview-status")
        case "POST threads/\(base)ticket": return json("thread-preview-ticket")
        case "POST threads/\(base)restore": return json("thread-preview-restore", status: 202)
        case "POST threads/\(base)warm", "POST threads/\(base)heartbeat":
            return StubReply(answer: .reply(status: 204, contentType: "application/json", body: Data()))
        default: return nil
        }
    }
}
