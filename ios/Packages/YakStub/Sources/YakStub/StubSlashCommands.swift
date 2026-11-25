import Foundation

/// The composer's slash menu: the core thread with a list of commands (`slash-commands`, ten of them, so the menu
/// scrolls), with none (`slash-none`), or with no `slashCommands` key at all, as an older server answers
/// (`slash-older`). Every message the app posts is counted, so a test can prove a tap on a command sent nothing.
final class StubSlashCommands: StubFeature, @unchecked Sendable {
    let name = "slash"
    let scenarios = ["slash-commands", "slash-none", "slash-older"]

    private let lock = NSLock()
    private var messages: [String] = []

    private static func commands() -> [[String: Any]] {
        [
            command("write-tests", "Add tests for the change on this branch", kind: "skill"),
            command("review-pr", "Review a pull request and leave comments", hint: "[number]", kind: "skill"),
            command("refactor", "Tidy the code without changing what it does", hint: "[file]", kind: "skill"),
            command("compact", "Summarise the conversation to free context", kind: "command"),
            command("init", "Write a CLAUDE.md for this repository", kind: "command"),
            command("context", "Show what fills the context window", kind: "command"),
            command("cost", "Show the cost of this session", kind: "command"),
            command("review", "Review the diff", hint: "[focus]", kind: "command"),
            command("memory", "Edit the memory files", kind: "command"),
            command("clear", "Start a fresh conversation on a new thread, linked back to this one", kind: "yak"),
        ]
    }

    private static func command(_ name: String, _ description: String, hint: String? = nil, kind: String) -> [String:
        Any]
    {
        ["name": name, "description": description, "argumentHint": hint ?? NSNull(), "kind": kind]
    }

    func reset() { lock.withLock { messages = [] } }

    func control(_ request: StubRequest) -> StubReply? {
        guard request.path == "/__stub/slash/messages" else { return nil }
        let text = lock.withLock { messages }.joined(separator: "\n")
        return StubReply(answer: .reply(status: 200, contentType: "text/plain", body: Data(text.utf8)))
    }

    func answer(route: String, request: StubRequest, context: StubContext) -> StubReply? {
        guard scenarios.contains(context.scenario) else { return nil }
        let parts = route.split(separator: " ", maxSplits: 1).map(String.init)
        let path = parts.count == 2 ? parts[1].split(separator: "/").map(String.init) : []
        guard path.count >= 2, path[0] == "threads" else { return nil }
        switch (parts[0], Array(path.dropFirst(2))) {
        case ("GET", []):
            guard case .reply(200, let contentType, let body) = context.core(),
                var show = (try? JSONSerialization.jsonObject(with: body)) as? [String: Any]
            else { return nil }
            switch context.scenario {
            case "slash-commands":
                show["slashCommands"] = ["commands": Self.commands(), "capturedAt": "2026-11-15T12:00:00+00:00"]
            case "slash-none":
                show["slashCommands"] = ["commands": [[String: Any]](), "capturedAt": NSNull()]
            default:
                show["slashCommands"] = nil
            }
            let edited = (try? JSONSerialization.data(withJSONObject: show)) ?? body
            return StubReply(answer: .reply(status: 200, contentType: contentType, body: edited))
        case ("POST", ["messages"]):
            let object = (try? JSONSerialization.jsonObject(with: request.body)) as? [String: Any] ?? [:]
            let text = (object["message"] ?? object["text"]) as? String ?? ""
            lock.withLock { messages.append(text) }
            return StubReply(answer: context.core())
        default:
            return nil
        }
    }
}
