#if DEBUG
    import SwiftUI
    import UIKit
    import YakKit

    /// A Debug-only screen that draws the chat from canned snapshots, so states the stub cannot reach (an unsent
    /// message that was refused, a queued message, every kind of activity) can be looked at in light, dark and at
    /// the largest text size. Launch with `-yakChatGallery messages-a|messages-b|messages-c|messages-d|activity|activity-b|activity-open|notice|history-failed`.
    struct ChatGallery: View {
        let page: String
        @State private var thread: ThreadModel?

        var body: some View {
            NavigationStack {
                Group {
                    if let thread {
                        ChatView(thread: thread, openThread: { _ in })
                    } else {
                        ProgressView()
                    }
                }
                .toolbar(.hidden, for: .navigationBar)
            }
            .environment(\.chatStartsExpanded, page == "activity-open")
            .task { thread = await Self.makeThread(page: page) }
        }

        // MARK: Canned data

        private struct IdleTransport: HTTPTransport {
            func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
                throw URLError(.notConnectedToInternet)
            }
        }

        private struct NoSockets: WebSocketConnector {
            struct Refused: Error {}
            func connect(to url: URL) async throws -> any WebSocketConnection { throw Refused() }
        }

        private final class Defaults: KeyValueStore, @unchecked Sendable {
            func data(forKey key: String) -> Data? { nil }
            func set(_ value: Data?, forKey key: String) {}
        }

        private static let showJSON = """
            {"thread":{"id":"d0e515ef","name":"retry-failed-rows","title":"Retry failed rows when the import times out",
            "repo":"example/api","kind":"root","status":"open","visibility":"team","branch":null,"sandboxState":"running",
            "runnerState":"idle","blockedReason":null,"parentThreadId":null,"rootThreadId":null,
            "lastActivityAt":null,"pullRequestCount":0,"isOwner":true,"isStarred":false},
            "canDrive":true,"messages":[],"composer":{"state":"idle","model":"sonnet","accountName":"Test account",
            "nextAttachmentNumber":1,"models":[]},"notice":NOTICE,
            "panes":{"chat":true,"preview":false,"terminal":true,"changes":true,"files":true},"question":null,
            "latestSequence":14}
            """

        private static func iso(_ secondsAgo: TimeInterval) -> String {
            ISO8601DateFormatter().string(from: Date().addingTimeInterval(-secondsAgo))
        }

        private static func message(
            _ id: String, _ text: String, author: String? = nil, state: MessageState = .read, mode: MessageMode? = nil,
            ago: TimeInterval = 600
        ) -> ThreadMessage {
            ThreadMessage(
                id: id, uuid: id, text: text, authorName: author, authorInitials: "TP", state: state, mode: mode,
                sentAt: iso(ago), bodyHtml: "")
        }

        private static func text(_ key: String, _ text: String, streaming: Bool = false) -> ConversationItem {
            var item = ConversationItem(kind: .text, key: key, sequence: 1, text: text)
            item.isStreaming = streaming
            item.at = iso(300)
            return item
        }

        private static func step(
            _ id: String, _ category: ToolStep.Category, _ label: String, running: Bool = false, failed: Bool = false,
            output: String? = nil
        ) -> ToolStep {
            ToolStep(
                key: "tool:\(id)", category: category, tool: "Tool", label: label, isFailed: failed, isRunning: running,
                output: running ? nil : output)
        }

        private static func tools(_ key: String, _ summary: String, _ steps: [ToolStep]) -> ConversationItem {
            var item = ConversationItem(kind: .tools, key: key, sequence: 2, text: summary)
            item.steps = steps
            item.isRunning = steps.contains(where: \.isRunning)
            item.at = iso(42)
            return item
        }

        private static func pngData() -> Data {
            let renderer = UIGraphicsImageRenderer(size: CGSize(width: 240, height: 160))
            return renderer.pngData { context in
                UIColor.systemTeal.setFill()
                context.fill(CGRect(x: 0, y: 0, width: 240, height: 160))
                UIColor.white.setFill()
                context.fill(CGRect(x: 40, y: 60, width: 160, height: 40))
            }
        }

        private static func unsent(
            _ state: OutboxState, _ text: String, attachments: [OutboxAttachment] = [], ago: TimeInterval = 20
        ) -> ConversationEntry {
            .unsent(
                OutboxItem(
                    id: UUID(), target: .thread("d0e515ef"), text: text, model: nil, delivery: .steer,
                    attachments: attachments, state: state, createdAt: Date().addingTimeInterval(-ago)))
        }

        @MainActor private static func makeThread(page: String) async -> ThreadModel? {
            let files = InMemoryFileStore()
            let tokens = InMemoryTokenStore()
            let instances = InstanceStore(defaults: Defaults(), tokens: tokens, now: { Date() })
            guard
                let instance = try? await instances.add(
                    url: URL(string: "https://example.test")!, name: "Example Yak", userName: "Test Person",
                    token: "token")
            else { return nil }
            let session = InstanceSession(
                instance: instance, instances: instances, tokens: tokens,
                environment: SessionEnvironment(
                    http: IdleTransport(), webSockets: NoSockets(), files: files, clock: SystemClock(),
                    makeID: { UUID() }, appVersion: "1.0.0", systemDescription: "Gallery",
                    allowsInsecureRealtime: false))
            let imagePath = "gallery/photo.png"
            try? files.write(pngData(), to: imagePath, protection: .untilFirstUnlock)
            let image = OutboxAttachment(
                reference: "File #1", fileName: "photo.png", contentType: "image/png", path: imagePath)
            let document = OutboxAttachment(
                reference: "File #2", fileName: "import-errors.csv", contentType: "text/csv", path: "gallery/errors.csv"
            )
            let notice =
                page == "notice"
                ? #"{"kind":"guard_turns","title":"Claude stopped after 50 steps","body":"Continue to let Claude keep working.","resetsAt":null,"canContinue":true}"#
                : "null"
            guard
                let show = try? JSONDecoder().decode(
                    ThreadShow.self, from: Data(showJSON.replacingOccurrences(of: "NOTICE", with: notice).utf8))
            else { return nil }

            var waiting: WaitingRow?
            var turn: TurnChange?
            let messageEntries: [ConversationEntry]
            let activityEntries: [ConversationEntry]
            do {
                messageEntries = [
                    .person(
                        message("u1", "Retry failed rows when the import times out", ago: 30 * 3600), isPending: false),
                    .event(
                        text(
                            "m1:0", "I changed the importer to retry **three times** with a short wait.",
                            streaming: false)),
                    .person(
                        message("u2", "Can you also cover the boundary with a test?", author: "Test Person", ago: 400),
                        isPending: false),
                    .person(
                        message("u3", "Then update the changelog.", state: .pending, mode: .steer, ago: 120),
                        isPending: true),
                    .person(
                        message("u4", "After that, rename the helper.", state: .pending, mode: .queue, ago: 90),
                        isPending: true),
                    .delivered(
                        OutboxDelivery(
                            sendKey: UUID(), target: .thread("d0e515ef"), status: .queued, messageUUID: "u5",
                            threadID: "d0e515ef", text: "Also run the linter.", sentAt: Date().addingTimeInterval(-60))),
                    unsent(.queued, "Waiting its turn in the outbox."),
                    unsent(.sending, "This one is on its way."),
                    unsent(.slow, "This one takes a while."),
                    unsent(.waiting, "This one waits for the connection.", ago: 40),
                    unsent(
                        .refused(OutboxRefusal(code: "thread_closed", message: "This thread is closed.")),
                        "Please check the error list.", attachments: [document], ago: 10
                    ),
                    unsent(.sending, "Here is the photo.", attachments: [image], ago: 5),
                ]
                var running = tools(
                    "tools:m3:0", "Read 2 files, Ran 3 commands",
                    [
                        step("a", .read, "app/Importer.php", output: "<?php\nclass Importer {}"),
                        step(
                            "b", .command, "php artisan test --filter=Import", failed: true,
                            output: "Tests: 1 failed, 3 passed"),
                        step("c", .search, "RateLimit::forImporter"),
                        step("d", .fetch, "https://example.com/docs/rate-limits", running: true),
                    ])
                running.isRunning = true
                var edits = ConversationItem(kind: .edits, key: "edits:m4", sequence: 4)
                edits.files = ["app/Importer.php", "tests/ImporterTest.php"]
                edits.added = 24
                edits.removed = 6
                var failedResult = ConversationItem(
                    kind: .result, key: "result:9", sequence: 9,
                    text: "The sandbox ran out of disk space while installing packages.")
                failedResult.isError = true
                var child = ConversationItem(kind: .child, key: "child:e5f6g7h8", sequence: 5)
                child.child = ChildCard(
                    childThreadId: LenientID("e5f6g7h8"), name: "Split the billing job", kind: "implement",
                    state: "running",
                    step: "Writing the first test", messagesPaused: false, filesChanged: 3, additions: 42, deletions: 7)
                var repository = ConversationItem(kind: .repository, key: "repository:example/web", sequence: 6)
                repository.repository = RepositoryCard(
                    repo: "example/web", role: "context", state: "failed", error: "The repository could not be cloned.")
                activityEntries = [
                    .event(
                        text(
                            "m1:0",
                            "Looking at the importer.\n\n1. Read the limit from configuration\n2. Pass it to the importer\n\n```php\n$limit = RateLimit::forImporter();\n```"
                        )),
                    .event(
                        {
                            var thinking = ConversationItem(
                                kind: .thinking, key: "m1:1", sequence: 1,
                                text: "The limit applies to every queue, so the importer needs its own value.")
                            thinking.at = iso(280)
                            return thinking
                        }()),
                    .event(tools("tools:m2:0", "Read 1 file", [step("z", .read, "app/Importer.php", output: "<?php")])),
                    .event(running),
                    .event(edits),
                    .event(text("m5:0", "Now writing the test for the **boundary**", streaming: true)),
                    .event(child),
                    .event(repository),
                    .event(failedResult),
                ]
            }
            // Each page fits one screen, so a screenshot never cuts a line at the edge of the list.
            let entries: [ConversationEntry]
            switch page {
            case "messages-a": entries = Array(messageEntries[0..<6])
            case "messages-b": entries = Array(messageEntries[6..<10])
            case "messages-c": entries = Array(messageEntries[10..<11])
            case "messages-d": entries = Array(messageEntries[11..<12])
            case "activity-b":
                entries = Array(activityEntries[5..<9])
                waiting = WaitingRow(isQueued: false)
                turn = TurnChange(turnId: LenientID("41"), state: .running)
            case "activity-open", "activity-open-a":
                entries = [activityEntries[1], activityEntries[3], activityEntries[4]]
            case "notice": entries = [activityEntries[2]]
            case "code-wide":
                let wideLine =
                    "php artisan import:retry --connection=primary --chunk=500 --max-attempts=3 --backoff=250 --queue=imports-nightly-ENDMARK"
                entries = [
                    .event(text("m1:0", "Run the retry like this:\n\n```\n\(wideLine)\n```\n", streaming: false))
                ]
            case "history-failed": entries = Array(messageEntries[0..<2])
            default:
                entries = Array(activityEntries[0..<5])
                turn = TurnChange(turnId: LenientID("41"), state: .running)
            }
            let thread = ThreadModel(threadID: "d0e515ef", session: session)
            await thread.apply(
                ConversationSnapshot(
                    load: .ready, show: show, entries: entries, currentStepKey: "tools:m3:0", waiting: waiting,
                    turn: turn,
                    connection: .live, hasOlderHistory: page == "history-failed", didOlderFail: page == "history-failed"
                ))
            return thread
        }
    }
#endif
