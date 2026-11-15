import Foundation
import Testing
import YakKit

// Plain `import YakKit`, never `@testable`: SwiftUI previews and test doubles outside the module can
// only use public initializers, so each model they build is built here the same way.

@Test func threadModelsAreBuildableOutsideTheModule() {
    let row = ThreadRow(
        id: "a1", title: "Title", repo: "example/api", status: .running, needsYouReason: nil, needsYouLabel: nil,
        isPrivate: false, isShared: false, isUnattended: false, isStarred: false, canDelete: true,
        ownerInitials: "TP", lastActivityAt: nil, group: .today, children: [])
    let task = TaskNeedsYou(taskId: LenientID("t1"), title: "Task", needsYouReason: .coOwner, isPrivate: true)
    let list = ThreadList(
        starred: [], needsYou: [.thread(row), .task(task)], threads: [row], needsYouCount: 2, hasAnyThreads: true)
    #expect(list.threads.first == row && list.needsYou.count == 2)

    let detail = ThreadDetail(
        id: "a1", name: "a1", title: "Title", repo: "example/api", kind: "thread", status: .open,
        visibility: "private", branch: nil, sandboxState: "ready", runnerState: "idle", blockedReason: nil,
        parentThreadId: nil, rootThreadId: nil, lastActivityAt: nil, pullRequestCount: 0, isOwner: true,
        isStarred: false)
    let choice = ModelChoice(value: "model", label: "Model", description: "")
    let composer = Composer(
        state: .idle, model: nil, models: [ModelFamily(family: "Family", latest: choice, older: [])],
        accountName: nil, nextAttachmentNumber: 1)
    let message = ThreadMessage(
        id: "m1", uuid: nil, sendKey: nil, text: "Hi", authorName: nil, authorInitials: "TP", model: nil,
        state: .pending, mode: nil, intent: nil, sentAt: "2026-11-15T09:00:00Z", readAfterSequence: nil,
        bodyHtml: "<p>Hi</p>")
    let question = ThreadQuestion(
        taskId: LenientID("t1"), expiresAt: nil,
        questions: [
            ClarificationQuestion(
                id: "q", header: "H", question: "Q?", options: [QuestionOption(label: "A", description: "")],
                multiSelect: false)
        ])
    let show = ThreadShow(
        thread: detail, canDrive: true, messages: [message], composer: composer, notice: nil,
        panes: ThreadPanes(chat: true, preview: false, terminal: false, changes: false, files: false),
        question: question, latestSequence: 3)
    #expect(show.thread.isPrivate && show.question?.roundKey == "t1:q")
    _ = ThreadNotice(kind: "k", title: "T", body: "B", resetsAt: nil, canContinue: false)
    _ = ThreadCreated(id: "a1")
    _ = MessageSendResult(status: .sent, uuid: nil, threadId: nil)
    _ = StatusResponse(status: "ok")
}

@Test func eventModelsAreBuildableOutsideTheModule() {
    _ = StreamDelta(blockKey: "b", kind: "text", text: "x")
    _ = TurnChange(turnId: LenientID("1"), state: .running)
    _ = ThreadStateChange(
        status: .open, sandboxState: "ready", runnerState: "idle", blockedReason: nil, pullRequestOpening: nil)
    _ = MessageStateChange(uuid: "u", state: "read")
    _ = QuestionChange(open: true)
    _ = RepositoryCard(id: nil, repo: "example/api", role: "extra", state: "ready")
    _ = ChildCard(childThreadId: LenientID("c"), name: "n", kind: "k", state: "running", messagesPaused: false)
    _ = ThreadEvent(sequence: 1, type: "stream.reset", createdAt: "2026-11-15T09:00:00Z", data: .null)
    _ = WaitingRow(isQueued: true)
    _ = ToolStep(
        key: "tool:1", category: .command, tool: "Bash", label: "ls", isFailed: false, isRunning: false)
    _ = ConversationSnapshot()
}

@Test func newThreadModelsAreBuildableOutsideTheModule() {
    let account = ClaudeAccount(
        id: LenientID("1"), name: "Account", kind: "personal", isDefault: true, defaultModel: nil, usable: true,
        status: "ok")
    let picker = AccountPicker(
        accounts: [account], defaultsByRepo: ["example/api": RepositoryDefaults(accountId: LenientID("1"))])
    let options = NewThreadOptions(
        repos: [NewThreadRepository(slug: "example/api", name: "api", setupStatus: "ready")], picker: picker,
        defaultRepo: nil, models: [])
    #expect(options.picker.accounts == [account])
}

@Test func outboxModelsAreBuildableOutsideTheModule() {
    let draft = OutboxDraft(target: .thread("a1"), text: "Hi", delivery: .steer)
    let item = OutboxItem(
        id: UUID(), target: draft.target, text: draft.text, model: nil, delivery: draft.delivery, attachments: [],
        createdAt: Date(timeIntervalSince1970: 0))
    _ = OutboxDelivery(
        sendKey: item.id, target: item.target, status: .sent, messageUUID: nil, threadID: "a1", text: "Hi",
        sentAt: Date(timeIntervalSince1970: 0))
    _ = OutboxAttachment(reference: "r", fileName: "f.png", contentType: "image/png", path: "p")
    _ = OutboxRefusal(code: "c", message: "m")
}

@Test func workspaceModelsAreBuildableOutsideTheModule() {
    let session = TerminalSession(url: "wss://bridge.example.com/t", token: "token", expiresAt: "2026-11-16T09:00:00Z")
    let entry = FileTreeEntry(name: "a.txt", path: "a.txt", isDirectory: false, isWritable: true, size: 3)
    let folder = FileTreeFolder(root: "", entries: [entry])
    let body = FileBody(
        path: "a.txt", sha256: "abc", size: 3, isBinary: false, isTooLarge: false, content: "hey", headContent: nil,
        language: .text)
    let content = FileContent(
        path: "a.txt", sha256: "abc", size: 3, isBinary: false, isTooLarge: false, content: "hey", headContent: nil,
        language: .text, canEdit: true)
    let saved = FileSaveResult(file: body, working: true)
    #expect(session.token == "token" && folder.entries == [entry] && !folder.truncated)
    #expect(content.body == body && saved.file == body)
}
