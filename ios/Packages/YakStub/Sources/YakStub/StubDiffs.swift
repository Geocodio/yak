import Foundation

/// The thread routes of the Files pane and the pull request card, answered for the thread `d0e515ef` in every
/// scenario of this feature. The stub replays the scenario data; it decides nothing about Yak beyond these tables.
extension StubChanges {
    static let changesThreadID = "d0e515ef"
    /// Seconds between asking Yak to open the pull request and the show saying it is open.
    static let openingDuration: TimeInterval = 2

    func threadReply(
        _ method: String, _ segments: [String], _ request: StubRequest, _ context: StubContext
    ) -> StubReply? {
        guard segments.count >= 2, segments[1] == Self.changesThreadID else { return nil }
        let rest = Array(segments.dropFirst(2))
        var reply: StubReply?
        switch (method, rest) {
        case ("GET", []): reply = show(context)
        case ("GET", ["diff"]): reply = diff(context)
        case ("GET", let tail) where tail.count == 2 && tail[0] == "diff" && tail[1] == "files": return nil
        case ("GET", let tail) where tail.count == 3 && tail[0] == "diff" && tail[1] == "files":
            reply = diffFile(Int(tail[2]), context)
        case ("POST", ["pull-request"]): reply = openPullRequest(request, context)
        case ("GET", ["pull-requests"]):
            reply = json(
                context.fixtures.scenario(isOpen(context) ? "scenario-pull-requests" : "scenario-pull-requests-none"))
        case ("PUT", ["pull-requests", "order"]): reply = status("updated")
        case ("POST", let tail) where tail.count == 3 && tail[0] == "pull-requests" && tail[2] == "fix":
            reply =
                tail[1] == "78"
                ? status("sent")
                : StubReply(answer: Scenario.error(409, "ci_not_failing", "CI is not failing on this pull request."))
        default: return nil
        }
        if context.scenario == "changes-slow", var slowed = reply, method == "GET", rest.first == "diff" {
            slowed.delay = .seconds(14)
            return slowed
        }
        return reply
    }

    private func isOpen(_ context: StubContext) -> Bool {
        if context.scenario == "pull-request-open" { return true }
        guard let started = state.get().openingStartedAt, !state.get().openingRefused else { return false }
        return context.scenario != "pull-request-held" && Date().timeIntervalSince(started) >= Self.openingDuration
    }

    private func isOpening(_ context: StubContext) -> Bool {
        guard let started = state.get().openingStartedAt else { return false }
        return !isOpen(context) && context.scenario != "pull-request-held"
            && Date().timeIntervalSince(started) < Self.openingDuration
    }

    // MARK: Show

    private func show(_ context: StubContext) -> StubReply {
        var show = context.fixtures.scenario("scenario-thread-with-changes")
        if case .reply(_, _, let data) = context.core(),
            let core = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any], core["thread"] != nil
        {
            show = core
            let extra = context.fixtures.scenario("scenario-thread-with-changes")
            for key in ["canOpenPullRequest", "pullRequest", "publishWarning", "diffTotals"] { show[key] = extra[key] }
        }
        var thread = show["thread"] as? [String: Any] ?? [:]
        if isOpen(context) {
            let opened = context.fixtures.scenario("scenario-thread-with-pull-request")
            for key in ["canOpenPullRequest", "pullRequest", "publishWarning", "diffTotals"] { show[key] = opened[key] }
            thread["pullRequestOpening"] = NSNull()
        }
        switch context.scenario {
        case "changes-none":
            show["diffTotals"] = NSNull()
            show["canOpenPullRequest"] = false
        case "changes-private":
            show["publishWarning"] = true
            thread["visibility"] = "private"
        case "pull-request-failed":
            thread["pullRequestOpening"] = [
                "state": "failed", "error": "GitHub refused the branch: protected.", "at": NSNull(),
            ]
            show["canOpenPullRequest"] = true
        default: break
        }
        if isOpening(context) {
            thread["pullRequestOpening"] = ["state": "opening", "error": NSNull(), "at": NSNull()]
        } else if context.scenario == "pull-request-held", state.get().openingStartedAt != nil {
            thread["pullRequestOpening"] = ["state": "held", "error": NSNull(), "at": NSNull()]
        }
        show["thread"] = thread
        return json(show)
    }

    // MARK: Diff

    private func diffBody(_ context: StubContext) -> [String: Any] {
        if context.scenario == "changes-none" { return context.fixtures.scenario("scenario-thread-diff-none") }
        var body = context.fixtures.scenario("scenario-thread-diff")
        var diff = body["diff"] as? [String: Any] ?? [:]
        var files = diff["files"] as? [[String: Any]] ?? []
        let recaptured = state.get().recaptured
        if recaptured, context.scenario == "changes-gone" {
            files = files.filter { ($0["path"] as? String) != "src/uploads.ts" }
        }
        if recaptured {
            files = files.map { file in
                var file = file
                file["id"] = (file["id"] as? Int ?? 0) + 100
                return file
            }
        }
        diff["files"] = files
        diff["filesCount"] = files.count
        if context.scenario == "changes-working" { diff["isTurnRunning"] = true }
        body["diff"] = diff
        return body
    }

    private func diff(_ context: StubContext) -> StubReply { json(diffBody(context)) }

    private func diffFile(_ id: Int?, _ context: StubContext) -> StubReply {
        let missing = StubReply(answer: Scenario.error(404, "not_found", "This file is not in the changes."))
        if context.scenario == "changes-replaced", id == 501, !state.get().recaptured {
            state.mutate { $0.recaptured = true }
            return missing
        }
        let diff = diffBody(context)["diff"] as? [String: Any]
        let files = diff?["files"] as? [[String: Any]] ?? []
        guard let id, let file = files.first(where: { $0["id"] as? Int == id }) else { return missing }
        let original = id % 100 + 500
        var body: [String: Any]
        switch original {
        case 502: body = context.fixtures.scenario("scenario-diff-file-binary")
        case 504: body = context.fixtures.scenario("scenario-diff-file-too-large")
        default: body = context.fixtures.scenario("scenario-diff-file")
        }
        body["path"] = file["path"]
        body["oldPath"] = file["oldPath"]
        body["status"] = file["status"]
        return json(body)
    }

    // MARK: Pull request

    private func openPullRequest(_ request: StubRequest, _ context: StubContext) -> StubReply {
        let body = String(decoding: request.body, as: UTF8.self)
        state.mutate { $0.lastPullRequestBody = body }
        if context.scenario == "pull-request-refused" {
            return StubReply(
                answer: Scenario.error(409, "nothing_to_publish", "This thread has no changes to publish yet."))
        }
        let object = (try? JSONSerialization.jsonObject(with: request.body)) as? [String: Any] ?? [:]
        if context.scenario == "changes-private", object["acknowledged_private_publish"] as? Bool != true {
            return StubReply(
                answer: Scenario.error(
                    422, "validation_failed", "Confirm that you want to publish a private thread.",
                    details: ["errors": ["acknowledged_private_publish": ["Confirm the publish."]]]))
        }
        state.mutate { $0.openingStartedAt = Date() }
        if context.scenario == "pull-request-held" {
            return json(context.fixtures.scenario("scenario-pull-request-held"))
        }
        var reply = json(context.fixtures.scenario("scenario-pull-request-opening"))
        if case .reply(_, let type, let data) = reply.answer {
            reply.answer = .reply(status: 202, contentType: type, body: data)
        }
        return reply
    }
}
