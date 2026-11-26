import Foundation

/// Why a search did not come back with an answer.
public enum SearchFailure: Equatable, Sendable {
    case offline
    /// 429; the server's `Retry-After` in seconds when it sent a sane one.
    case rateLimited(retryAfterSeconds: Int?)
    case failed

    public init(_ failure: APIFailure) {
        switch failure {
        case .network: self = .offline
        case .server(let error) where error.status == 429:
            self = .rateLimited(retryAfterSeconds: error.retryAfterSeconds)
        case .server(let error) where error.status == 503: self = .offline
        default: self = .failed
        }
    }
}

/// What the drawer's search field shows, as one value. The drawer asks the server from two characters; below that
/// the field only narrows the list that is loaded.
public struct SearchState: Equatable, Sendable {
    public enum Phase: Equatable, Sendable {
        /// The field is empty.
        case idle
        /// One character: no request is sent.
        case tooShort
        case loading
        case results([SearchResult])
        case empty
        /// The server cannot be reached: the loaded threads are filtered by the text instead.
        case offline
        case failed
        /// The server said to wait; the search asks again by itself when the wait has passed.
        case rateLimited(retryAfterSeconds: Int)
    }

    /// Posted to VoiceOver once per finished search.
    public struct Announcement: Equatable, Sendable {
        public let generation: Int
        /// Zero for a search without results.
        public let count: Int
    }

    /// The trimmed text the state answers.
    public internal(set) var query = ""
    public internal(set) var phase = Phase.idle
    public internal(set) var announcement: Announcement?
    /// Counts the questions asked; an answer for an older number is dropped.
    public internal(set) var generation = 0
    public internal(set) var isOnline = true

    public init() {}

    /// The server is asked or has answered: the list gives way to shapes, results or a line that nothing matched.
    public var showsServerResults: Bool {
        switch phase {
        case .loading, .results, .empty: true
        case .idle, .tooShort, .offline, .failed, .rateLimited: false
        }
    }

    /// The field narrows the loaded list: below two characters, and while the server cannot be reached. A failed or
    /// limited search keeps the loaded matches under its message.
    public var filtersLoadedList: Bool {
        switch phase {
        case .idle: false
        case .tooShort, .offline, .failed, .rateLimited: true
        case .loading, .results, .empty: false
        }
    }
}

public enum SearchEvent: Equatable, Sendable {
    case textChanged(String)
    case debounced(generation: Int)
    case answered(generation: Int, results: [SearchResult])
    case failed(generation: Int, SearchFailure)
    /// The person asked again.
    case retry
    case waitOver(generation: Int)
    case connectivity(isOnline: Bool)
}

public enum SearchEffect: Equatable, Sendable {
    /// Drops the request in flight and the timer.
    case cancelPending
    case startDebounce(generation: Int)
    case request(generation: Int, text: String)
    case startWait(generation: Int, seconds: Int)
}

/// The search field's rules as pure transitions: from two characters one question per pause in the typing, an
/// older question's answer never shows over a newer text, and a failure says what to do.
public struct SearchMachine: Equatable, Sendable {
    public static let minimumLength = 2
    /// Used when a 429 carries no usable `Retry-After`.
    public static let defaultWaitSeconds = 10
    public static let waitRange = 1...60

    public private(set) var state = SearchState()

    public init() {}

    public mutating func send(_ event: SearchEvent) -> [SearchEffect] {
        switch event {
        case .textChanged(let text): return changeText(text)
        case .debounced(let generation):
            guard generation == state.generation, state.phase == .loading else { return [] }
            return [.request(generation: generation, text: state.query)]
        case .answered(let generation, let results):
            guard generation == state.generation, state.phase == .loading else { return [] }
            state.phase = results.isEmpty ? .empty : .results(results)
            state.announcement = .init(generation: generation, count: results.count)
            return []
        case .failed(let generation, let failure):
            guard generation == state.generation, state.phase == .loading else { return [] }
            return fail(failure)
        case .retry:
            switch state.phase {
            case .failed, .offline: return ask()
            default: return []
            }
        case .waitOver(let generation):
            guard generation == state.generation, case .rateLimited = state.phase else { return [] }
            return ask()
        case .connectivity(let isOnline):
            state.isOnline = isOnline
            if !isOnline {
                guard state.phase == .loading else { return [] }
                state.generation += 1
                state.phase = .offline
                return [.cancelPending]
            }
            return state.phase == .offline ? ask() : []
        }
    }

    private mutating func changeText(_ text: String) -> [SearchEffect] {
        let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
        guard trimmed != state.query else { return [] }
        state.query = trimmed
        state.generation += 1
        switch trimmed.count {
        case 0:
            state.phase = .idle
            return [.cancelPending]
        case 1..<Self.minimumLength:
            state.phase = .tooShort
            return [.cancelPending]
        default:
            guard state.isOnline else {
                state.phase = .offline
                return [.cancelPending]
            }
            state.phase = .loading
            return [.cancelPending, .startDebounce(generation: state.generation)]
        }
    }

    /// Asks again at once, for a retry, a wait that is over and the connection coming back.
    private mutating func ask() -> [SearchEffect] {
        guard state.query.count >= Self.minimumLength else { return [] }
        state.generation += 1
        state.phase = .loading
        return [.cancelPending, .request(generation: state.generation, text: state.query)]
    }

    private mutating func fail(_ failure: SearchFailure) -> [SearchEffect] {
        switch failure {
        case .offline:
            state.phase = .offline
            return []
        case .failed:
            state.phase = .failed
            return []
        case .rateLimited(let seconds):
            let wait = min(
                max(seconds ?? Self.defaultWaitSeconds, Self.waitRange.lowerBound), Self.waitRange.upperBound)
            state.phase = .rateLimited(retryAfterSeconds: wait)
            return [.startWait(generation: state.generation, seconds: wait)]
        }
    }
}
