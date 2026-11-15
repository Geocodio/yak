import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let sendKey = UUID(uuidString: "11111111-2222-3333-4444-555555555555")!
private let threadRoute = "POST threads/d0e515ef/messages"

private func jsonObject(_ endpoint: Endpoint<MessageSendResult>) throws -> [String: Any] {
    let body = try #require(endpoint.body)
    let data = try JSONEncoder().encode(body)
    return try #require(JSONSerialization.jsonObject(with: data) as? [String: Any])
}

private func plainMessage(intent: MessageIntent?) -> Endpoint<MessageSendResult> {
    .sendMessage(
        "d0e515ef", text: PreviewRequests.fixText, model: nil, delivery: .steer, sendKey: sendKey, attachments: [],
        references: [], intent: intent)
}

@Suite struct MessageIntentTests {
    @Test func aMessageCarriesItsIntent() throws {
        let body = try jsonObject(plainMessage(intent: .fixPreview))
        #expect(body["intent"] as? String == "fix_preview")
        #expect(body["message"] as? String == PreviewRequests.fixText)
    }

    @Test func noIntentSendsNoKey() throws {
        #expect(try jsonObject(plainMessage(intent: nil))["intent"] == nil)
        let part = MultipartBody.Part.file(
            name: "x", fileName: "a.txt", contentType: "text/plain", data: Data("A".utf8))
        let endpoint = Endpoint<MessageSendResult>.sendMessage(
            "d0e515ef", text: "Hi", model: nil, delivery: .steer, sendKey: sendKey, attachments: [part],
            references: ["File #1"])
        let text = String(decoding: try #require(endpoint.multipart).encoded(), as: UTF8.self)
        #expect(!text.contains("name=\"intent\""))
    }

    @Test func aMultipartMessageCarriesTheIntent() throws {
        let part = MultipartBody.Part.file(
            name: "x", fileName: "a.txt", contentType: "text/plain", data: Data("A".utf8))
        let endpoint = Endpoint<MessageSendResult>.sendMessage(
            "d0e515ef", text: PreviewRequests.addManifestText, model: nil, delivery: .steer, sendKey: sendKey,
            attachments: [part], references: ["File #1"], intent: .addPreviewManifest)
        let text = String(decoding: try #require(endpoint.multipart).encoded(), as: UTF8.self)
        let delivery = try #require(text.range(of: "name=\"delivery\""))
        let intent = try #require(text.range(of: "name=\"intent\""))
        let attachment = try #require(text.range(of: "name=\"attachments[]\""))
        #expect(delivery.upperBound < intent.lowerBound && intent.upperBound < attachment.lowerBound)
        #expect(text.contains("\r\n\r\nadd_preview_manifest\r\n"))
    }

    @Test func anOldOutboxListDecodesWithoutAnIntent() throws {
        let item = OutboxItem(
            id: sendKey, target: .thread("d0e515ef"), text: "Hi", model: nil, delivery: .steer, attachments: [],
            createdAt: Date(timeIntervalSince1970: 0), intent: .fixPreview)
        let encoded = try JSONEncoder().encode(item)
        #expect(try JSONDecoder().decode(OutboxItem.self, from: encoded).intent == .fixPreview)
        var object = try #require(try JSONSerialization.jsonObject(with: encoded) as? [String: Any])
        object["intent"] = nil
        let old = try JSONSerialization.data(withJSONObject: object)
        #expect(try JSONDecoder().decode(OutboxItem.self, from: old).intent == nil)
    }

    @Test func theOutboxSendsAndKeepsTheIntent() async throws {
        let files = InMemoryFileStore()
        let instance = UUID()
        func makeOutbox(_ transport: ScriptedTransport) -> Outbox {
            Outbox(
                instance: instance,
                client: APIClient(
                    baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
                    appVersion: "1.0", systemDescription: "Test phone"),
                files: files, clock: ManualClock(), makeID: IDSequence().next)
        }
        let transport = ScriptedTransport()
        await transport.on(threadRoute, try .scenario(200, "scenario-message-sent"))
        await transport.hold(threadRoute)
        let outbox = makeOutbox(transport)
        let saved = try await outbox.enqueue(
            OutboxDraft(target: .thread("d0e515ef"), text: PreviewRequests.fixText, intent: .fixPreview))
        await transport.waitForRequests(1, on: threadRoute)
        let request = try #require(await transport.requests(on: threadRoute).first)
        let body = try #require(
            request.httpBody.flatMap { try? JSONSerialization.jsonObject(with: $0) } as? [String: Any])
        #expect(body["intent"] as? String == "fix_preview")

        let restarted = makeOutbox(ScriptedTransport())
        await restarted.load()
        #expect(await restarted.items().map(\.id) == [saved.id])
        #expect(await restarted.items().map(\.intent) == [.fixPreview])
        await transport.release(threadRoute)
    }
}
