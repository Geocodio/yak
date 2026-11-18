import Foundation

/// What every pane but Chat shows above its bar: the open question, or what Yak is doing.
public enum ActivityLine: Equatable, Sendable {
    /// An open question round with `count` questions.
    case question(count: Int)
    /// A card above the composer waits for the person: a repository, a co-owner, a retry.
    case needsYou
    case step(category: ToolStep.Category, label: String, isFailed: Bool)
    /// A turn runs with no current step.
    case working

    /// The label is the step's own, never a thread title.
    public static func make(_ snapshot: ConversationSnapshot?) -> ActivityLine? {
        guard let snapshot else { return nil }
        if let question = snapshot.show?.question { return .question(count: question.questions.count) }
        if ThreadCardState.make(show: snapshot.show, isForbidden: false).needsYou { return .needsYou }
        let isRunning = snapshot.turn?.state.isActive ?? (snapshot.show?.composer.state == .working)
        guard isRunning else { return nil }
        guard let key = snapshot.currentStepKey,
            let item = snapshot.entries.lazy.compactMap({ entry -> ConversationItem? in
                if case .event(let item) = entry, item.key == key { return item }
                return nil
            }).first,
            let step = item.steps.last(where: \.isRunning) ?? item.steps.last
        else { return .working }
        return .step(category: step.category, label: step.label, isFailed: step.isFailed)
    }
}
