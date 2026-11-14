import Foundation

#if canImport(os)
    import os
#endif

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

public struct DeviceDescription: Sendable {
    public let name, model, osVersion, appVersion: String

    public init(name: String, model: String, osVersion: String, appVersion: String) {
        self.name = name
        self.model = model
        self.osVersion = osVersion
        self.appVersion = appVersion
    }

    /// The part of the User-Agent in parentheses: `<os version>; <model>`.
    public var systemDescription: String { "\(osVersion); \(model)" }
}

public struct PendingDeviceFlow: Sendable {
    public let instance: InstanceInfo
    public let url: URL
    public let code: DeviceCode

    public init(instance: InstanceInfo, url: URL, code: DeviceCode) {
        self.instance = instance
        self.url = url
        self.code = code
    }
}

/// Pairs this phone with an instance, by pairing code or by the device flow, and stores the result.
public actor Pairing {
    /// How much a `slow_down` answer adds to the polling interval.
    private static let slowDownStep = 5
    private static let maximumInterval = 60

    private let makeClient: @Sendable (URL) -> APIClient
    private let instances: InstanceStore
    private let sleep: @Sendable (Duration) async throws -> Void

    public init(
        makeClient: @escaping @Sendable (URL) -> APIClient, instances: InstanceStore,
        sleep: @escaping @Sendable (Duration) async throws -> Void
    ) {
        self.makeClient = makeClient
        self.instances = instances
        self.sleep = sleep
    }

    /// Asks the address what it is and whether the two sides speak a common protocol.
    public func check(_ address: URL) async throws(PairingProblem) -> InstanceInfo {
        let info: InstanceInfo
        do { info = try await makeClient(address).send(.instance()) } catch { throw PairingProblem(error) }
        guard info.yak else { throw .notYak }
        guard info.api.current >= ClientProtocol.minimumServer else { throw .serverTooOld }
        guard info.api.min <= ClientProtocol.current else { throw .appTooOld }
        return info
    }

    /// Redeems the code of a link whose instance the caller already checked, so it sends no other
    /// request first. A timeout or a lost connection is tried once more with the same `requestID`,
    /// which a server that knows it answers with the same token. When that also fails the code may
    /// have been redeemed already, so the problem is `.codeMayBeUsed`, not a plain network error.
    public func pair(link: PairingLink, device: DeviceDescription, requestID: String? = nil)
        async throws(PairingProblem) -> Instance
    {
        let client = makeClient(link.instance)
        let endpoint = Endpoint<PairingResponse>.pair(
            code: link.code, requestID: requestID, name: device.name, model: device.model,
            osVersion: device.osVersion, appVersion: device.appVersion)
        let response: PairingResponse
        do {
            do {
                response = try await client.send(endpoint)
            } catch .network(let code) where Self.isWorthRepeating(code) {
                response = try await client.send(endpoint)
            }
        } catch {
            // Only a clear answer from the instance settles the code; anything else leaves it unknown.
            let problem = PairingProblem(error)
            switch problem {
            case .codeInvalid, .codeExpired, .appTooOld, .serverTooOld, .cancelled: throw problem
            default: throw .codeMayBeUsed
            }
        }
        return try await store(response, url: link.instance, client: client)
    }

    private static func isWorthRepeating(_ code: URLError.Code) -> Bool {
        code == .timedOut || code == .networkConnectionLost
    }

    public func startDeviceFlow(address: URL, device: DeviceDescription) async throws(PairingProblem)
        -> PendingDeviceFlow
    {
        let info = try await check(address)
        do {
            let code = try await makeClient(address).send(
                .startDeviceFlow(
                    name: device.name, model: device.model, osVersion: device.osVersion,
                    appVersion: device.appVersion))
            return PendingDeviceFlow(instance: info, url: address, code: code)
        } catch { throw PairingProblem(error) }
    }

    /// Polls until the person approves on the dashboard. Cancelling the calling task throws
    /// `.cancelled`. Waiting stops with `.codeExpired` once the code's lifetime has passed.
    public func waitForApproval(_ flow: PendingDeviceFlow) async throws(PairingProblem) -> Instance {
        let client = makeClient(flow.url)
        // The server's numbers are untrusted: keep the interval in a sane range so arithmetic cannot overflow.
        var interval = min(max(flow.code.interval, 1), Self.maximumInterval)
        var waited = 0
        while true {
            guard waited < flow.code.expiresIn else { throw .codeExpired }
            do { try await sleep(.seconds(interval)) } catch { throw .cancelled }
            waited += interval
            if Task.isCancelled { throw .cancelled }
            do {
                let response = try await client.send(.pollDeviceToken(deviceCode: flow.code.deviceCode))
                return try await store(response, url: flow.url, client: client)
            } catch let failure as APIFailure {
                // A dropped request is no answer from the instance: keep polling until the code expires.
                if case .network(let code) = failure, code != .cancelled { continue }
                if case .server(let error) = failure, error.code == "authorization_pending" { continue }
                if case .server(let error) = failure, error.code == "slow_down" {
                    interval = min(interval + Self.slowDownStep, Self.maximumInterval)
                    continue
                }
                throw PairingProblem(failure)
            } catch let problem as PairingProblem {
                throw problem
            } catch { throw .other("unexpected") }
        }
    }

    /// Saves the instance with its token, then refreshes it from `me`. The entry is already usable
    /// when `me` fails, so that failure leaves the name and counts from the pairing answer.
    private func store(_ response: PairingResponse, url: URL, client: APIClient) async throws(PairingProblem)
        -> Instance
    {
        let instance: Instance
        do {
            instance = try await instances.add(
                url: url, name: url.host ?? url.absoluteString, userName: response.user.name,
                token: response.token)
        } catch { throw .other("storage_failed") }
        do {
            let me = try await client.authenticated(token: response.token).send(.me())
            try await instances.update(instance.id, from: me)
        } catch let failure as APIFailure {
            logMeFailure(failure)
        } catch { throw .other("storage_failed") }
        return await instances.all().first { $0.id == instance.id } ?? instance
    }
}

/// Logs which kind of failure the `me` refresh hit: the case or the server error code, never the
/// token or a response body. Linux has no `os.Logger`, so it is a no-op there.
private func logMeFailure(_ failure: APIFailure) {
    #if canImport(os)
        let reason: String
        switch failure {
        case .server(let error): reason = "server \(error.code)"
        case .unauthorized(let code): reason = "unauthorized \(code)"
        case .network(let code): reason = "network \(code.rawValue)"
        case .appTooOld: reason = "appTooOld"
        case .serverTooOld: reason = "serverTooOld"
        case .notYak: reason = "notYak"
        case .encodingFailed: reason = "encodingFailed"
        }
        Logger(subsystem: "YakKit", category: "Pairing").error("me refresh failed: \(reason, privacy: .public)")
    #endif
}
