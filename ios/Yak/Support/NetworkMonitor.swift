import Foundation
import Network
import Observation

/// Whether the device has a network path right now, so screens can say "offline" and retry
/// by themselves when the connection returns.
@MainActor @Observable
final class NetworkMonitor {
    private(set) var isOnline = true

    @ObservationIgnored private let monitor: NWPathMonitor?
    @ObservationIgnored private var pollTask: Task<Void, Never>?

    /// With no `poll`, reads the real network path. With one, asks `poll` every `interval` instead, which is how
    /// test mode lets the stub server take the app offline and bring it back.
    init(poll: (@Sendable () async -> Bool)? = nil, interval: Duration = .milliseconds(300)) {
        guard let poll else {
            let monitor = NWPathMonitor()
            self.monitor = monitor
            monitor.pathUpdateHandler = { [weak self] path in
                let online = path.status == .satisfied
                Task { @MainActor in self?.isOnline = online }
            }
            monitor.start(queue: DispatchQueue(label: "NetworkMonitor"))
            return
        }
        monitor = nil
        pollTask = Task { [weak self] in
            while !Task.isCancelled {
                let online = await poll()
                guard let self else { return }
                if isOnline != online { isOnline = online }
                try? await Task.sleep(for: interval)
            }
        }
    }

    deinit {
        monitor?.cancel()
        pollTask?.cancel()
    }
}
