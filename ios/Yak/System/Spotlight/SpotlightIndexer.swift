import Observation

/// Keeps the Spotlight index of cached thread titles; the rows of Settings read its state.
@MainActor @Observable
final class SpotlightIndexer {
    enum State: Equatable { case idle, updating, failed }

    private(set) var state: State = .idle

    func reindex() async {}
}
