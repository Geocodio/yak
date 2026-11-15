import Foundation

extension InstanceSession {
    /// A new shell's connection, or nil while the session is not active or the instance URL has no origin.
    /// Each call makes another shell; the caller owns `start` and `close`.
    public func terminalConnection(
        thread id: String, sandboxState: String, connector: any TerminalSocketConnector,
        timings: TerminalTimings = .standard
    ) -> TerminalConnection? {
        guard let client = apiClient(), let origin = TerminalOrigin.make(instance: instance.url) else { return nil }
        return TerminalConnection(
            threadID: id, client: client, origin: origin, connector: connector, clock: clock,
            allowsInsecure: environment.allowsInsecureRealtime, sandboxState: sandboxState, timings: timings)
    }

    public func fileTree(thread id: String, scope: FileScope, timings: FileTimings = .standard) -> FileTree? {
        guard let client = apiClient() else { return nil }
        return FileTree(threadID: id, scope: scope, client: client, clock: clock, timings: timings)
    }

    public func fileDocument(
        thread id: String, scope: FileScope, path: String, timings: FileTimings = .standard
    ) -> FileDocument? {
        guard let client = apiClient(), let cache = instanceCache() else { return nil }
        return FileDocument(
            threadID: id, scope: scope, path: path, client: client, cache: cache, clock: clock, timings: timings)
    }
}
