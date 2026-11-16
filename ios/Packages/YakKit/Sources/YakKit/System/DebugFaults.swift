import Foundation
import Synchronization

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

/// How the debug menu makes requests misbehave.
public enum DebugFault: String, CaseIterable, Sendable { case off, slow, verySlow, offline, serverError }

/// Shared by every session's transport; starts `.off` in every process.
public final class DebugFaultSwitch: Sendable {
    private let value = Mutex<DebugFault>(.off)

    public init() {}

    public var fault: DebugFault { value.withLock { $0 } }

    public func set(_ fault: DebugFault) { value.withLock { $0 = fault } }
}

/// Applies the switch's fault to each request. The fault is read once, when the request starts.
public struct DebugFaultTransport: HTTPTransport {
    private let transport: any HTTPTransport
    private let faults: DebugFaultSwitch
    private let clock: any YakClock

    public init(wrapping transport: any HTTPTransport, faults: DebugFaultSwitch, clock: any YakClock) {
        self.transport = transport
        self.faults = faults
        self.clock = clock
    }

    public func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        switch faults.fault {
        case .off:
            return try await transport.send(request)
        case .slow:
            try await clock.sleep(for: .seconds(4))
            return try await transport.send(request)
        case .verySlow:
            try await clock.sleep(for: .seconds(10))
            return try await transport.send(request)
        case .offline:
            throw URLError(.notConnectedToInternet)
        case .serverError:
            let body = Data(#"{"error":"unavailable","message":"Simulated by the debug menu."}"#.utf8)
            let url = request.url ?? URL(fileURLWithPath: "/")
            guard
                let response = HTTPURLResponse(
                    url: url, statusCode: 503, httpVersion: "HTTP/1.1",
                    headerFields: ["Content-Type": "application/json"])
            else { throw URLError(.badServerResponse) }
            return (body, response)
        }
    }
}

public enum DebugBuildKind: Equatable, Sendable { case debug, testFlight, appStore, unknown }

public enum DebugMenuAvailability {
    /// `storeEnvironment` is the raw value of the App Store environment the app reports ("Production",
    /// "Sandbox", "Xcode"), nil when it could not be read.
    public static func kind(isDebugBuild: Bool, storeEnvironment: String?) -> DebugBuildKind {
        if isDebugBuild { return .debug }
        switch storeEnvironment {
        case "Sandbox": return .testFlight
        case "Xcode": return .debug
        case "Production": return .appStore
        default: return .unknown
        }
    }

    public static func isAvailable(_ kind: DebugBuildKind) -> Bool {
        kind == .debug || kind == .testFlight
    }
}
