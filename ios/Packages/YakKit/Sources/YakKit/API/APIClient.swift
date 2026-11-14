import Foundation

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

public protocol HTTPTransport: Sendable {
    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse)
}

/// Sends requests with a 15 second timeout and no cookie storage.
public struct URLSessionTransport: HTTPTransport {
    private let session: URLSession

    public init() {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.timeoutIntervalForRequest = 15
        configuration.httpCookieStorage = nil
        configuration.httpShouldSetCookies = false
        session = URLSession(configuration: configuration)
    }

    public func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let (data, response) = try await session.data(for: request)
        guard let http = response as? HTTPURLResponse else { throw URLError(.badServerResponse) }
        return (data, http)
    }
}

public actor APIClient {
    private let baseURL: URL
    private let token: String?
    private let transport: any HTTPTransport
    private let userAgent: String
    private let sleep: @Sendable (Duration) async throws -> Void

    /// Delays before the retries of a GET: three retries, four attempts in all.
    private static let retryDelays: [Duration] = [.milliseconds(500), .seconds(1), .seconds(2)]
    private static let retryableStatuses: Set<Int> = [502, 503, 504]

    public init(
        baseURL: URL, token: String?, transport: any HTTPTransport, appVersion: String,
        systemDescription: String,
        sleep: @escaping @Sendable (Duration) async throws -> Void = { try await Task.sleep(for: $0) }
    ) {
        self.baseURL = baseURL
        self.token = token
        self.transport = transport
        self.userAgent = "YakApp/\(appVersion) (\(systemDescription))"
        self.sleep = sleep
    }

    public func send<Response: Decodable & Sendable>(_ endpoint: Endpoint<Response>) async throws(APIFailure)
        -> Response
    {
        let request = makeRequest(endpoint)
        let maximumRetries = endpoint.method == .get ? Self.retryDelays.count : 0
        var attempt = 0
        while true {
            do {
                let (data, response) = try await transport.send(request)
                if attempt < maximumRetries, Self.retryableStatuses.contains(response.statusCode) {
                    try await sleep(Self.retryDelays[attempt])
                    attempt += 1
                    continue
                }
                return try Self.interpret(data: data, response: response)
            } catch let failure as APIFailure {
                throw failure
            } catch {
                let code = (error as? URLError)?.code ?? (error is CancellationError ? .cancelled : .unknown)
                guard code != .cancelled, attempt < maximumRetries else { throw .network(code) }
                do { try await sleep(Self.retryDelays[attempt]) } catch { throw .network(.cancelled) }
                attempt += 1
            }
        }
    }

    private func makeRequest<Response>(_ endpoint: Endpoint<Response>) -> URLRequest {
        var request = URLRequest(url: baseURL.appendingPathComponent("api/app/v1/\(endpoint.path)"))
        request.httpMethod = endpoint.method.rawValue
        request.timeoutInterval = 15
        request.setValue(String(ClientProtocol.current), forHTTPHeaderField: ClientProtocol.headerName)
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue(userAgent, forHTTPHeaderField: "User-Agent")
        if let token {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }
        if let body = endpoint.body {
            request.httpBody = body
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        }
        return request
    }

    private static func interpret<Response: Decodable>(data: Data, response: HTTPURLResponse) throws(APIFailure)
        -> Response
    {
        if let header = response.value(forHTTPHeaderField: ClientProtocol.headerName),
            let serverProtocol = Int(header), serverProtocol < ClientProtocol.minimumServer
        {
            throw .serverTooOld(current: serverProtocol)
        }
        let status = response.statusCode
        if (200..<300).contains(status) {
            if data.isEmpty, let empty = EmptyResponse() as? Response { return empty }
            guard let decoded = try? JSONDecoder().decode(Response.self, from: data) else { throw .notYak }
            return decoded
        }
        guard let error = APIError.parse(status: status, body: data) else {
            throw status >= 500
                ? .server(APIError(status: status, code: "unknown", message: "", details: [:])) : .notYak
        }
        if status == 426, error.code == "protocol_too_old" {
            guard case .number(let minimum)? = error.details["min_protocol"] else { throw .notYak }
            throw .appTooOld(minimum: Int(minimum))
        }
        if status == 401 { throw .unauthorized(code: error.code) }
        throw .server(error)
    }
}
