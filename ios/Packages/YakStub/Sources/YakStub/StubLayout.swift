import Foundation

/// The `two-panes` scenario: the paired scenario with every pane of the main thread turned on, so the layout tests
/// have a second pane to show. Each pane plays whatever its own feature answers.
final class StubLayout: StubFeature, @unchecked Sendable {
    static let scenarioName = "two-panes"
    static let threadID = "d0e515ef"

    let name = "layout"
    let scenarios = [StubLayout.scenarioName]

    func answer(route: String, request: StubRequest, context: StubContext) -> StubReply? {
        guard context.scenario == Self.scenarioName, route == "GET threads/\(Self.threadID)",
            case .reply(let status, let contentType, let body) = context.core(), status == 200,
            var show = (try? JSONSerialization.jsonObject(with: body)) as? [String: Any]
        else { return nil }
        var panes = show["panes"] as? [String: Any] ?? [:]
        for key in ["chat", "preview", "terminal", "changes", "files"] { panes[key] = true }
        show["panes"] = panes
        guard let edited = try? JSONSerialization.data(withJSONObject: show) else { return nil }
        return StubReply(answer: .reply(status: status, contentType: contentType, body: edited))
    }
}
