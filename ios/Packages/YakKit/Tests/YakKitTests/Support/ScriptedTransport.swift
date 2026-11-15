import Foundation

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

/// Answers requests by route ("GET threads/d0e515ef/events": method, a space, the path after
/// `/api/app/v1/`, no query). A route with no answer gets a 404. The last answer of a route repeats.
actor ScriptedTransport: HTTPTransport {
    private var answers: [String: [FakeTransport.Result]] = [:]
    private var prefixHandlers: [(prefix: String, respond: @Sendable (URLRequest) async -> FakeTransport.Result)] = []
    private var heldRoutes: Set<String> = []
    private var holders: [Int: (route: String, continuation: CheckedContinuation<Void, any Error>)] = [:]
    private var nextHolder = 0
    private(set) var requests: [URLRequest] = []
    private var requestWaiters: [(route: String, count: Int, continuation: CheckedContinuation<Void, Never>)] = []

    private static let notFound = FakeTransport.Result.json(404, #"{"error":"not_found","message":"Not found."}"#)

    func on(_ route: String, _ results: FakeTransport.Result...) {
        answers[route] = results
    }

    func on(prefix: String, respond: @escaping @Sendable (URLRequest) async -> FakeTransport.Result) {
        prefixHandlers.append((prefix, respond))
    }

    func hold(_ route: String) { heldRoutes.insert(route) }

    func release(_ route: String) {
        heldRoutes.remove(route)
        for (identifier, holder) in holders where holder.route == route {
            holders[identifier] = nil
            holder.continuation.resume()
        }
    }

    func requests(on route: String) -> [URLRequest] {
        requests.filter { Self.route(of: $0) == route }
    }

    func waitForRequests(_ count: Int, on route: String) async {
        if requests(on: route).count >= count { return }
        await withCheckedContinuation { requestWaiters.append((route, count, $0)) }
    }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let route = Self.route(of: request)
        requests.append(request)
        let count = requests(on: route).count
        for waiter in requestWaiters where waiter.route == route && count >= waiter.count {
            waiter.continuation.resume()
        }
        requestWaiters.removeAll { $0.route == route && count >= $0.count }

        if heldRoutes.contains(route) { try await waitUntilReleased(route) }

        let result: FakeTransport.Result
        if var queue = answers[route], !queue.isEmpty {
            result = queue.count > 1 ? queue.removeFirst() : queue[0]
            answers[route] = queue
        } else if let handler = prefixHandlers.first(where: { route.hasPrefix($0.prefix) }) {
            result = await handler.respond(request)
        } else {
            result = Self.notFound
        }
        switch result {
        case .failure(let code):
            throw URLError(code)
        case .response(let status, let body, let headers):
            let response = HTTPURLResponse(
                url: request.url!, statusCode: status, httpVersion: nil, headerFields: headers)!
            return (Data(body.utf8), response)
        }
    }

    private func waitUntilReleased(_ route: String) async throws {
        nextHolder += 1
        let identifier = nextHolder
        try await withTaskCancellationHandler {
            try await withCheckedThrowingContinuation { (continuation: CheckedContinuation<Void, any Error>) in
                if Task.isCancelled {
                    continuation.resume(throwing: URLError(.cancelled))
                } else {
                    holders[identifier] = (route, continuation)
                }
            }
        } onCancel: {
            Task { await self.cancelHolder(identifier) }
        }
    }

    private func cancelHolder(_ identifier: Int) {
        guard let holder = holders.removeValue(forKey: identifier) else { return }
        holder.continuation.resume(throwing: URLError(.cancelled))
    }

    private static func route(of request: URLRequest) -> String {
        let path = request.url?.path ?? ""
        let prefix = "/api/app/v1/"
        let relative = path.hasPrefix(prefix) ? String(path.dropFirst(prefix.count)) : path
        return "\(request.httpMethod ?? "GET") \(relative)"
    }
}

extension FakeTransport.Result {
    /// A response whose body is the scenario file `name`.
    static func scenario(_ status: Int, _ name: String) throws -> FakeTransport.Result {
        .json(status, String(decoding: try Scenarios.data(name), as: UTF8.self))
    }
}
