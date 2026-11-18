import Foundation
import YakKit

extension SessionEnvironment {
    /// The real network (behind the debug fault switch when one is given), the given files, the system clock and random ids.
    static func app(
        version: String, system: String, files: any FileStore, allowsInsecureRealtime: Bool,
        faults: DebugFaultSwitch? = nil
    ) -> SessionEnvironment {
        SessionEnvironment(
            http: faults.map { DebugFaultTransport(wrapping: URLSessionTransport(), faults: $0, clock: SystemClock()) }
                ?? URLSessionTransport(), webSockets: URLSessionWebSocketConnector(), files: files,
            clock: SystemClock(), makeID: UUID.init, appVersion: version, systemDescription: system,
            allowsInsecureRealtime: allowsInsecureRealtime)
    }

    /// The files of a release build: the app group container named by the `YakAppGroup` Info.plist key, or the
    /// app's own support folder when the group is not available.
    static func releaseFiles() -> any FileStore {
        let manager = FileManager.default
        if let group = Bundle.main.object(forInfoDictionaryKey: "YakAppGroup") as? String,
            let container = manager.containerURL(forSecurityApplicationGroupIdentifier: group)
        {
            return DiskFileStore(root: container)
        }
        let support = manager.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        return DiskFileStore(root: support.appending(path: "Yak"))
    }
}
