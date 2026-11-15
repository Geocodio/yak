import Foundation

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

/// Records requests and replays queued results. The last result repeats when the queue runs out.
actor FakeTransport: HTTPTransport {
    enum Result: Sendable {
        case response(status: Int, body: String, headers: [String: String])
        case failure(URLError.Code)

        static func json(_ status: Int, _ body: String, headers: [String: String] = [:]) -> Result {
            .response(status: status, body: body, headers: headers)
        }
    }

    private var queue: [Result]
    private(set) var requests: [URLRequest] = []

    init(_ results: Result...) { queue = results }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        requests.append(request)
        let next = queue.count > 1 ? queue.removeFirst() : queue[0]
        switch next {
        case .failure(let code):
            throw URLError(code)
        case .response(let status, let body, let headers):
            let response = HTTPURLResponse(
                url: request.url!, statusCode: status, httpVersion: nil, headerFields: headers)!
            return (Data(body.utf8), response)
        }
    }
}

/// Collects the delays the client asked to sleep, without sleeping.
actor SleepRecorder {
    private(set) var delays: [Duration] = []
    func record(_ delay: Duration) { delays.append(delay) }
}
