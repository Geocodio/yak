import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

@Test func anUploadRequestHasTheHeadersAndNoBody() async throws {
    let client = APIClient(
        baseURL: URL(string: "https://yak.example.com")!, token: "yakdev_abc",
        transport: FakeTransport(.json(200, "{}")),
        appVersion: "1.0.0", systemDescription: "27.2.1; iPhone19,4")
    let request = try await client.uploadRequest(
        path: "threads/d0e515ef/messages", contentType: "multipart/form-data; boundary=yak-K")
    #expect(request.httpMethod == "POST")
    #expect(request.url?.absoluteString == "https://yak.example.com/api/app/v1/threads/d0e515ef/messages")
    #expect(request.value(forHTTPHeaderField: "Authorization") == "Bearer yakdev_abc")
    #expect(request.value(forHTTPHeaderField: "X-Yak-Protocol") == "1")
    #expect(request.value(forHTTPHeaderField: "Accept") == "application/json")
    #expect(request.value(forHTTPHeaderField: "User-Agent") == "YakApp/1.0.0 (27.2.1; iPhone19,4)")
    #expect(request.value(forHTTPHeaderField: "Content-Type") == "multipart/form-data; boundary=yak-K")
    #expect(request.httpBody == nil)
}

@Test func interpretIsPublicAndReadsAnAnswer() throws {
    let response = HTTPURLResponse(
        url: URL(string: "https://yak.example.com")!, statusCode: 200, httpVersion: nil, headerFields: nil)!
    let empty: EmptyResponse = try APIClient.interpret(data: Data(), response: response)
    _ = empty
}
