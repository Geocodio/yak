import Foundation

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

public protocol HTTPTransport: Sendable {
    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse)
}

/// Sends requests with no cookie storage. Each request's own `timeoutInterval` decides how long it waits
/// for data; there is no total limit, so a slow upload that keeps moving bytes is not cut off.
public struct URLSessionTransport: HTTPTransport {
    private let session: URLSession

    public init() {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.timeoutIntervalForRequest = 60
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
    private let appVersion: String
    private let systemDescription: String
    private let sleep: @Sendable (Duration) async throws -> Void

    /// Delays before the retries of a GET: three retries, four attempts in all.
    private static let retryDelays: [Duration] = [.milliseconds(500), .seconds(1), .seconds(2)]
    private static let retryableStatuses: Set<Int> = [502, 503, 504]
    private static let reservedHeaders: Set<String> = [
        "authorization", ClientProtocol.headerName.lowercased(), "user-agent", "accept", "content-type",
    ]

    public init(
        baseURL: URL, token: String?, transport: any HTTPTransport, appVersion: String,
        systemDescription: String,
        sleep: @escaping @Sendable (Duration) async throws -> Void = { try await Task.sleep(for: $0) }
    ) {
        self.baseURL = baseURL
        self.token = token
        self.transport = transport
        self.appVersion = appVersion
        self.systemDescription = systemDescription
        self.userAgent = "YakApp/\(appVersion) (\(systemDescription))"
        self.sleep = sleep
    }

    /// The same client for the same instance, sending `token` as the bearer token.
    public func authenticated(token: String) -> APIClient {
        APIClient(
            baseURL: baseURL, token: token, transport: transport, appVersion: appVersion,
            systemDescription: systemDescription, sleep: sleep)
    }

    public func send<Response: Decodable & Sendable>(_ endpoint: Endpoint<Response>) async throws(APIFailure)
        -> Response
    {
        let request = try makeRequest(endpoint)
        let maximumRetries = endpoint.method == .get && endpoint.retriesTransientFailures ? Self.retryDelays.count : 0
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

    private func makeRequest<Response>(_ endpoint: Endpoint<Response>) throws(APIFailure) -> URLRequest {
        guard var components = URLComponents(url: baseURL, resolvingAgainstBaseURL: false) else {
            throw .encodingFailed
        }
        var basePath = components.percentEncodedPath
        while basePath.hasSuffix("/") { basePath.removeLast() }
        components.percentEncodedPath = "\(basePath)/api/app/v1/\(endpoint.path)"
        if !endpoint.query.isEmpty {
            // Every reserved character is escaped, including "+", which a server reads as a space.
            components.percentEncodedQuery = endpoint.query.map {
                "\(Endpoint<Response>.segment($0.name))=\(Endpoint<Response>.segment($0.value ?? ""))"
            }.joined(separator: "&")
        }
        guard let url = components.url else { throw .encodingFailed }
        var request = URLRequest(url: url)
        request.httpMethod = endpoint.method.rawValue
        let (seconds, attoseconds) = endpoint.timeout.components
        request.timeoutInterval = Double(seconds) + Double(attoseconds) / 1e18
        for (name, value) in endpoint.headers where !Self.reservedHeaders.contains(name.lowercased()) {
            request.setValue(value, forHTTPHeaderField: name)
        }
        request.setValue(String(ClientProtocol.current), forHTTPHeaderField: ClientProtocol.headerName)
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue(userAgent, forHTTPHeaderField: "User-Agent")
        if let token {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }
        if let multipart = endpoint.multipart {
            request.httpBody = multipart.encoded()
            request.setValue(multipart.contentType, forHTTPHeaderField: "Content-Type")
        } else if let body = endpoint.body {
            // The caller gets .encodingFailed; the underlying error type adds nothing.
            guard let encoded = try? JSONEncoder().encode(body) else { throw .encodingFailed }
            request.httpBody = encoded
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
            // The caller gets .notYak; the underlying error type adds nothing.
            guard let decoded = try? JSONDecoder().decode(Response.self, from: data) else { throw .notYak }
            return decoded
        }
        guard let error = APIError.parse(status: status, body: data) else {
            throw status >= 500
                ? .server(APIError(status: status, code: "unknown", message: "", details: [:])) : .notYak
        }
        if status == 426, error.code == "protocol_too_old" {
            // A number from the network never goes through a trapping conversion: a missing,
            // negative, fractional or out-of-range value is not a Yak answer.
            guard case .number(let number)? = error.details["min_protocol"],
                let minimum = Int(exactly: number), minimum >= 0
            else { throw .notYak }
            throw .appTooOld(minimum: minimum)
        }
        if status == 401 { throw .unauthorized(code: error.code) }
        if status == 429 {
            let seconds = response.value(forHTTPHeaderField: "Retry-After").flatMap {
                Int($0.trimmingCharacters(in: .whitespaces))
            }
            throw .server(
                APIError(
                    status: error.status, code: error.code, message: error.message, details: error.details,
                    retryAfterSeconds: seconds.flatMap { (0...3600).contains($0) ? $0 : nil }))
        }
        throw .server(error)
    }
}
