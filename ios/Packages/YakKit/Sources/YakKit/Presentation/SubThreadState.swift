import Foundation

/// How one sub-thread's state reads: its word, its mark and its tone. The Overview group takes it from here, and so
/// does a drawer row that carries the stack state (`ThreadRow.stackState`); a drawer row from a server that does not
/// send it falls back to `of(listStatus:)` and the drawer's own words. The words are the web's: the server's `queued`
/// is "Waiting" and its `waiting` is "Needs you".
public struct SubThreadStateStyle: Equatable, Sendable {
    public enum Word: Equatable, Sendable {
        case starting, waiting, working, needsYou, done, failed, stopped, takenOver
        /// A state a newer server sent that this app does not know.
        case other
    }

    public enum Mark: Equatable, Sendable {
        /// A filled dot: the sub-thread is live or needs attention.
        case filled
        /// A hollow ring: it is not running.
        case ring
    }

    public enum Tone: Equatable, Sendable { case brand, quiet, attention, failure }

    public let word: Word
    public let mark: Mark
    public let tone: Tone

    public init(word: Word, mark: Mark, tone: Tone) {
        self.word = word
        self.mark = mark
        self.tone = tone
    }

    /// The style of a stack sub-thread. A taken over sub-thread keeps the mark of its state and says "Taken over".
    public static func of(_ state: StackChildState, takenOver: Bool = false) -> SubThreadStateStyle {
        let base: SubThreadStateStyle =
            switch state {
            case .starting: SubThreadStateStyle(word: .starting, mark: .filled, tone: .brand)
            case .queued: SubThreadStateStyle(word: .waiting, mark: .ring, tone: .quiet)
            case .working: SubThreadStateStyle(word: .working, mark: .filled, tone: .brand)
            case .waiting: SubThreadStateStyle(word: .needsYou, mark: .filled, tone: .attention)
            case .done: SubThreadStateStyle(word: .done, mark: .ring, tone: .quiet)
            case .failed: SubThreadStateStyle(word: .failed, mark: .filled, tone: .failure)
            case .closed: SubThreadStateStyle(word: .stopped, mark: .ring, tone: .quiet)
            default: SubThreadStateStyle(word: .other, mark: .ring, tone: .quiet)
            }
        return takenOver ? SubThreadStateStyle(word: .takenOver, mark: base.mark, tone: base.tone) : base
    }

    /// The style for a sub-thread row of a list that carries no stack state (an older server), for the two list
    /// statuses that mean the same as a stack state. Nil for every other list status, which the drawer words as it
    /// always did.
    public static func of(listStatus: ThreadRowStatus) -> SubThreadStateStyle? {
        switch listStatus {
        case .running: of(.working)
        case .closed: of(.closed)
        default: nil
        }
    }
}
