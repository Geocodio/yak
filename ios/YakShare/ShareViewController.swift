import SwiftUI
import UIKit
import YakKit

/// The share sheet's principal class: it hosts the share screen and completes or cancels the request. It never
/// touches `UIApplication`.
final class ShareViewController: UIViewController {
    private var model: ShareModel?

    override func viewDidLoad() {
        super.viewDidLoad()
        guard let storage = SharedStorage.open() else {
            extensionContext?.cancelRequest(withError: CocoaError(.userCancelled))
            return
        }
        let files = storage.files
        let instances = InstanceStore(defaults: storage.defaults, tokens: storage.tokens, now: { Date() })
        let version = Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "0"
        let transport = URLSessionTransport()
        let makeClient: @Sendable (Instance, String) -> APIClient = { instance, token in
            APIClient(
                baseURL: instance.url, token: token, transport: transport, appVersion: version,
                systemDescription: "share extension")
        }
        let model = ShareModel(
            instances: instances, tokens: storage.tokens, files: files,
            sender: ShareSender(
                files: files, appBundleID: storage.appBundleID, appGroup: storage.appGroup, clock: SystemClock(),
                makeClient: { instance, token in
                    (await instances.all()).first { $0.id == instance }.map { makeClient($0, token) }
                }),
            clock: SystemClock(),
            fetchOptions: { instance, token in
                try await makeClient(instance, token).send(.newThreadOptions())
            })
        self.model = model
        let screen = ShareScreen(model: model) { [weak self] didSend in
            if didSend {
                UINotificationFeedbackGenerator().notificationOccurred(.success)
                self?.extensionContext?.completeRequest(returningItems: nil)
            } else {
                self?.extensionContext?.cancelRequest(withError: CocoaError(.userCancelled))
            }
        }
        let host = UIHostingController(rootView: screen)
        addChild(host)
        host.view.translatesAutoresizingMaskIntoConstraints = false
        view.addSubview(host.view)
        NSLayoutConstraint.activate([
            host.view.topAnchor.constraint(equalTo: view.topAnchor),
            host.view.bottomAnchor.constraint(equalTo: view.bottomAnchor),
            host.view.leadingAnchor.constraint(equalTo: view.leadingAnchor),
            host.view.trailingAnchor.constraint(equalTo: view.trailingAnchor),
        ])
        host.didMove(toParent: self)
        let providers = (extensionContext?.inputItems as? [NSExtensionItem] ?? [])
            .flatMap { $0.attachments ?? [] }.map { SystemShareProvider(provider: $0) as any ShareItemProvider }
        Task { await model.start(providers) }
    }
}
