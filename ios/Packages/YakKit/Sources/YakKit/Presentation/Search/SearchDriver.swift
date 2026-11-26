import Foundation

/// Runs a `SearchMachine` against the server: the pause before a request, the request itself and the wait after a
/// 429. Every change of the state goes to `onChange` on the main actor.
@MainActor
public final class SearchDriver {
    public typealias Perform = @Sendable (String) async throws(APIFailure) -> SearchAnswer

    private var machine = SearchMachine()
    private let clock: any YakClock
    private let debounce: Duration
    private let perform: Perform
    private let onChange: @MainActor (SearchState) -> Void
    private var requestTask: Task<Void, Never>?
    private var timerTask: Task<Void, Never>?

    public init(
        clock: any YakClock, debounce: Duration = .milliseconds(300), perform: @escaping Perform,
        onChange: @escaping @MainActor (SearchState) -> Void
    ) {
        self.clock = clock
        self.debounce = debounce
        self.perform = perform
        self.onChange = onChange
    }

    public var state: SearchState { machine.state }

    public func setText(_ text: String) { apply(.textChanged(text)) }
    public func retry() { apply(.retry) }
    public func setOnline(_ isOnline: Bool) { apply(.connectivity(isOnline: isOnline)) }

    /// Stops whatever is pending; the state stays as it is.
    public func stop() { cancelPending() }

    private func apply(_ event: SearchEvent) {
        let before = machine.state
        let effects = machine.send(event)
        for effect in effects { run(effect) }
        if machine.state != before { onChange(machine.state) }
    }

    private func cancelPending() {
        requestTask?.cancel()
        requestTask = nil
        timerTask?.cancel()
        timerTask = nil
    }

    private func run(_ effect: SearchEffect) {
        switch effect {
        case .cancelPending: cancelPending()
        case .startDebounce(let generation):
            timerTask = Task { [clock, debounce] in
                do { try await clock.sleep(for: debounce) } catch { return }
                self.apply(.debounced(generation: generation))
            }
        case .startWait(let generation, let seconds):
            timerTask = Task { [clock] in
                do { try await clock.sleep(for: .seconds(seconds)) } catch { return }
                self.apply(.waitOver(generation: generation))
            }
        case .request(let generation, let text):
            requestTask = Task { [perform] in
                do throws(APIFailure) {
                    let answer = try await perform(text)
                    guard !Task.isCancelled else { return }
                    self.apply(.answered(generation: generation, results: answer.results))
                } catch {
                    guard !Task.isCancelled else { return }
                    self.apply(.failed(generation: generation, SearchFailure(error)))
                }
            }
        }
    }
}
