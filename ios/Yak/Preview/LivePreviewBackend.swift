import Foundation
import YakKit

/// The model's backend on a real session: the preview session, the outbox and the data store of the instance.
struct LivePreviewBackend: PreviewBackend {
    let preview: PreviewSession
    let session: InstanceSession
    let engine: ThreadEngine?
    let defaults: any KeyValueStore

    var allowsLoopbackHTTP: Bool { preview.allowsLoopbackHTTP }

    func snapshots() async -> AsyncStream<PreviewSnapshot> { await preview.snapshots() }
    func setVisible(_ visible: Bool) async { await preview.setVisible(visible) }
    func threadChanged(_ snapshot: ConversationSnapshot) async { await preview.threadChanged(snapshot) }
    func refresh() async { await preview.refresh() }
    func tryAgain() async { await preview.tryAgain() }
    func restore() async -> ThreadActionOutcome { await preview.restore() }
    func retryRestore() async -> ThreadActionOutcome { await engine?.retryRestore() ?? .failed(.offline) }

    func ticket(path: String, reason: PreviewTicketReason) async -> PreviewTicketOutcome {
        await preview.ticket(path: path, reason: reason)
    }

    func ask(_ intent: MessageIntent) async throws(SendProblem) -> OutboxItem {
        let text = intent == .fixPreview ? PreviewRequests.fixText : PreviewRequests.addManifestText
        return try await session.send(
            OutboxDraft(target: .thread(preview.threadID), text: text, delivery: .steer, intent: intent))
    }

    func intentStates() async -> AsyncStream<PreviewIntentState> {
        guard let outbox = await session.outbox() else { return AsyncStream { $0.finish() } }
        let changes = await outbox.changes()
        let target = OutboxTarget.thread(preview.threadID)
        return AsyncStream { continuation in
            let task = Task {
                for await items in changes {
                    var state = PreviewIntentState()
                    for item in items where item.target == target {
                        guard let intent = item.intent else { continue }
                        if case .refused(let refusal) = item.state {
                            state.refused[intent] = refusal.message
                        } else {
                            state.pending.insert(intent)
                        }
                    }
                    continuation.yield(state)
                }
                continuation.finish()
            }
            continuation.onTermination = { _ in task.cancel() }
        }
    }

    func prepareStore() async -> Bool {
        await PreviewWebData.prepare(instanceID: session.instance.id, defaults: defaults)
    }

    func close() async { await preview.close() }
}
