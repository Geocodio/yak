import Foundation

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

/// Passes requests on and reports, from the raw response, what ends an instance's session: a 401
/// (unauthenticated, invalid_token, device_revoked), a 426 protocol_too_old, and an
/// `X-Yak-Protocol` header below `ClientProtocol.minimumServer`. Every request of every part of the
/// session goes through it, so none of them has to look for these answers itself.
public struct SessionTransport: HTTPTransport {
    public enum Signal: Equatable, Sendable {
        case unauthorized(code: String)
        case appTooOld(minimum: Int)
        case serverTooOld
    }

    private static let unauthorizedCodes: Set<String> = ["unauthenticated", "invalid_token", "device_revoked"]

    private let wrapped: any HTTPTransport
    private let onSignal: @Sendable (Signal) -> Void

    public init(wrapping transport: any HTTPTransport, onSignal: @escaping @Sendable (Signal) -> Void) {
        wrapped = transport
        self.onSignal = onSignal
    }

    public func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let (data, response) = try await wrapped.send(request)
        if let signal = Self.signal(data: data, response: response) { onSignal(signal) }
        return (data, response)
    }

    static func signal(data: Data, response: HTTPURLResponse) -> Signal? {
        if let header = response.value(forHTTPHeaderField: ClientProtocol.headerName),
            let serverProtocol = Int(header), serverProtocol < ClientProtocol.minimumServer
        {
            return .serverTooOld
        }
        switch response.statusCode {
        case 401:
            guard let error = APIError.parse(status: 401, body: data), unauthorizedCodes.contains(error.code)
            else { return nil }
            return .unauthorized(code: error.code)
        case 426:
            // Same reading as `APIClient`: a number that is missing, negative or fractional is no signal.
            guard let error = APIError.parse(status: 426, body: data), error.code == "protocol_too_old",
                case .number(let number)? = error.details["min_protocol"],
                let minimum = Int(exactly: number), minimum >= 0
            else { return nil }
            return .appTooOld(minimum: minimum)
        default:
            return nil
        }
    }
}
