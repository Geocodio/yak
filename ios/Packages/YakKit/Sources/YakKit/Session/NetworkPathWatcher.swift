import Foundation

#if canImport(Network)
    import Network

    /// Reports whether the device has a usable network path: the current state first, then every change.
    public final class NetworkPathWatcher: Sendable {
        public init() {}

        public func updates() -> AsyncStream<Bool> {
            AsyncStream(bufferingPolicy: .bufferingNewest(1)) { continuation in
                let monitor = NWPathMonitor()
                monitor.pathUpdateHandler = { continuation.yield($0.status == .satisfied) }
                continuation.onTermination = { _ in monitor.cancel() }
                monitor.start(queue: DispatchQueue(label: "YakKit.NetworkPathWatcher"))
            }
        }
    }
#endif
