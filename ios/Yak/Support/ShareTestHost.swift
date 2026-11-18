#if DEBUG
    import SwiftUI
    import UIKit
    import YakKit

    /// The share screen inside the app, with fake shared items and a fake sender, so the walkthrough reaches every
    /// state. `-yakTestShare <case>`: ready, last-thread, private-last-thread, no-instance, signed-out, too-many,
    /// preparing-slow, no-context-offline, repository-not-ready, saving, failed.
    struct ShareTestHost: View {
        let caseName: String
        @State private var model: ShareExtensionModel?

        var body: some View {
            Group {
                if let model {
                    ShareScreen(model: model) { _ in }
                } else {
                    ProgressView()
                }
            }
            .task { await build() }
        }

        private static let contextJSON = """
            {"instanceName":"Example Yak","limits":{"maxFiles":8,"maxFileBytes":10485760},
             "lastThread":{"id":"d0e515ef","title":"Fix the overlap on small screens","isPrivate":false,"canSend":true,"nextAttachmentNumber":4},
             "options":{"repos":[{"slug":"example/api","name":"api","setupStatus":"ready","setupStep":null},
               {"slug":"example/web","name":"web","setupStatus":"running","setupStep":"Installing dependencies"}],
              "picker":{"accounts":[{"id":3,"name":"Test account","kind":"team","icon":"users","color":"slate","isSystem":false,"isDefault":true,"defaultModel":"sonnet","usable":true,"status":"ok"}],
               "defaultsByRepo":{"example/api":{"accountId":3,"brokenDefaultAccountId":null,"model":null}},"models":[]},
              "defaultRepo":"example/api",
              "models":[{"family":"sonnet","latest":{"value":"sonnet","label":"Sonnet 5.5","description":"Fast and capable"},"older":[]}]},
             "lastRepo":"example/api","updatedAt":800000000}
            """

        @MainActor private func build() async {
            let folder = URL(fileURLWithPath: NSTemporaryDirectory()).appendingPathComponent(
                "yak-share-\(UUID().uuidString)")
            try? FileManager.default.createDirectory(at: folder, withIntermediateDirectories: true)
            let files = DiskFileStore(root: folder)
            let tokens = InMemoryTokenStore()
            let instances = InstanceStore(defaults: MemoryDefaults(), tokens: tokens, now: { Date() })
            if caseName != "no-instance",
                let instance = try? await instances.add(
                    url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test person",
                    token: "test-token")
            {
                if caseName == "signed-out" { try? tokens.removeToken(for: instance.id) }
                if caseName != "no-context-offline", var context = try? Self.context() {
                    if caseName == "private-last-thread" {
                        context.lastThread = ShareLastThread(
                            id: "d0e515ef", title: nil, isPrivate: true, canSend: true, nextAttachmentNumber: 4)
                    }
                    if caseName == "last-thread" || caseName == "private-last-thread" {
                        // The sheet opens on the last thread's target through its own control; the context is enough.
                    }
                    try? ShareContextStore.write(context, instance: instance.id, files: files)
                }
            }
            let options = (try? Self.context())?.options
            let isOffline = caseName == "no-context-offline"
            let host = ShareExtensionModel(
                instances: instances, tokens: tokens, files: files,
                sender: FakeSender(fails: caseName == "failed", hangs: caseName == "saving"),
                clock: SystemClock(),
                fetchOptions: { _, _ in
                    if isOffline { throw APIFailure.network(.notConnectedToInternet) }
                    guard let options else { throw APIFailure.network(.notConnectedToInternet) }
                    return options
                })
            model = host
            Task {
                await host.start(Self.providers(for: caseName))
                if caseName == "last-thread" || caseName == "private-last-thread" { host.target = .lastThread }
                if caseName == "repository-not-ready" { host.selectRepository("example/web") }
                if caseName == "saving" || caseName == "failed" {
                    host.typed = "Please look at this"
                    _ = await host.send()
                }
            }
        }

        private static func context() throws -> ShareContext {
            try JSONDecoder().decode(ShareContext.self, from: Data(contextJSON.utf8))
        }

        private static func providers(for name: String) -> [any ShareItemProvider] {
            let images = name == "too-many" ? 10 : 2
            var result: [any ShareItemProvider] = (0..<images).map { FakeProvider(kind: .image, number: $0) }
            if name == "preparing-slow" { result.insert(FakeProvider(kind: .image, number: 0, hangs: true), at: 0) }
            result.append(FakeProvider(kind: .link, number: 0))
            result.append(FakeProvider(kind: .text, number: 0))
            return result
        }

        private struct FakeProvider: ShareItemProvider {
            let kind: ShareItemKind
            let number: Int
            var hangs = false
            var suggestedName: String? { "Photo \(number + 1)" }

            func loadImage(maximumBytes: Int) async throws -> PreparedAttachment {
                if hangs { try await Task.sleep(for: .seconds(3600)) }
                let size = CGSize(width: 800, height: 600)
                let format = UIGraphicsImageRendererFormat()
                format.scale = 1
                let data = UIGraphicsImageRenderer(size: size, format: format).jpegData(withCompressionQuality: 0.8) {
                    context in
                    [UIColor.systemTeal, .systemIndigo, .systemOrange][number % 3].setFill()
                    context.fill(CGRect(origin: .zero, size: size))
                }
                return PreparedAttachment(
                    data: data, fileName: "photo-\(number).jpg", contentType: "image/jpeg", isImage: true)
            }

            func loadURL() async throws -> URL { URL(string: "https://status.example.com/issue/1")! }
            func loadText(maximumBytes: Int) async throws -> Data { Data("The overlap shows on small screens.".utf8) }
        }

        private struct FakeSender: ShareSending {
            let fails: Bool
            let hangs: Bool
            func send(_ draft: OutboxDraft, stagingFolder: String, instance: UUID, token: String)
                async throws(ShareSendProblem)
            {
                if hangs { try? await Task.sleep(for: .seconds(3600)) }
                if fails { throw .notSaved }
            }
        }
    }
#endif
