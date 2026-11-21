import SwiftUI
import UIKit
import WebKit
import YakKit

#if canImport(os)
    import os
#endif

/// The embedded conversation page of an instance. The model owns the web view, so it lives across thread switches and
/// across panes that come and go; this view only places it.
struct WebConversationView: UIViewRepresentable {
    let model: WebConversationModel
    let dataStore: () -> WKWebsiteDataStore
    let version: String
    @Environment(\.colorScheme) private var colorScheme

    func makeUIView(context: Context) -> WebConversationContainer {
        let page: WebConversationPageView
        if let existing = model.page as? WebConversationPageView {
            page = existing
        } else {
            page = WebConversationPageView(model: model, dataStore: dataStore(), version: version)
            model.attach(page)
        }
        return WebConversationContainer(page: page)
    }

    func updateUIView(_ container: WebConversationContainer, context: Context) {
        container.page.webView.overrideUserInterfaceStyle = colorScheme == .dark ? .dark : .light
    }
}

/// A plain view the page sits in. A second pane may make its container before the first has gone, and adding the page
/// to the new container takes it out of the old one.
@MainActor
final class WebConversationContainer: UIView {
    let page: WebConversationPageView

    init(page: WebConversationPageView) {
        self.page = page
        super.init(frame: .zero)
        accessibilityIdentifier = "thread.web"
        let webView = page.webView
        webView.translatesAutoresizingMaskIntoConstraints = false
        addSubview(webView)
        NSLayoutConstraint.activate([
            webView.leadingAnchor.constraint(equalTo: leadingAnchor),
            webView.trailingAnchor.constraint(equalTo: trailingAnchor),
            webView.topAnchor.constraint(equalTo: topAnchor), webView.bottomAnchor.constraint(equalTo: bottomAnchor),
        ])
        page.applyZoom(for: traitCollection.preferredContentSizeCategory)
        registerForTraitChanges([UITraitPreferredContentSizeCategory.self]) { (view: WebConversationContainer, _) in
            view.page.applyZoom(for: view.traitCollection.preferredContentSizeCategory)
        }
    }

    required init?(coder: NSCoder) { nil }
}

/// The web view of the conversation and everything it reports. It accepts messages only from the main frame on the
/// instance's origin and lets the page navigate only to its own embedded pages.
@MainActor
final class WebConversationPageView: NSObject, WebConversationPage, WKNavigationDelegate, WKUIDelegate,
    WKScriptMessageHandler
{
    static let handlerName = "yak"

    let webView: WKWebView
    private weak var model: WebConversationModel?
    private let policy: EmbedNavigationPolicy
    private let quickLook: WebQuickLook

    init(model: WebConversationModel, dataStore: WKWebsiteDataStore, version: String) {
        self.model = model
        policy = model.policy
        quickLook = WebQuickLook(policy: model.policy, notify: { [weak model] in model?.showNotice($0) })
        let configuration = WKWebViewConfiguration()
        configuration.websiteDataStore = dataStore
        configuration.limitsNavigationsToAppBoundDomains = false
        configuration.applicationNameForUserAgent = "YakApp/\(version) (embed)"
        webView = WKWebView(frame: .zero, configuration: configuration)
        super.init()
        configuration.userContentController.add(WeakScriptHandler(self), contentWorld: .page, name: Self.handlerName)
        webView.navigationDelegate = self
        webView.uiDelegate = self
        webView.allowsBackForwardNavigationGestures = false
        webView.allowsLinkPreview = false
        #if DEBUG
            webView.isInspectable = true
        #endif
        let color = UIColor(Color.yakBackground)
        webView.backgroundColor = color
        webView.scrollView.backgroundColor = color
        webView.underPageBackgroundColor = color
        webView.accessibilityIdentifier = "thread.web.page"
        webView.accessibilityLabel = String(localized: "web.page.label")
    }

    func applyZoom(for category: UIContentSizeCategory) {
        webView.pageZoom = WebPageZoom.value(for: category)
    }

    // MARK: WebConversationPage

    func loadRedeem(_ request: URLRequest) { webView.load(request) }

    func deliver(_ message: EmbedAppMessage) {
        guard let dictionary = message.dictionary() else { return }
        webView.callAsyncJavaScript(
            "window.__yakEmbed?.receive(m)", arguments: ["m": dictionary], in: nil, in: .page
        ) { _ in }
    }

    func captureArchive() async -> Data? {
        await withCheckedContinuation { continuation in
            webView.createWebArchiveData { result in
                switch result {
                case .success(let data): continuation.resume(returning: data)
                case .failure(let error):
                    Self.log("A page archive failed: \(type(of: error))")
                    continuation.resume(returning: nil)
                }
            }
        }
    }

    func quickLook(url: URL, name: String) { quickLook.start(url: url, name: name, in: webView) }

    /// A hidden page also gives up focus, so a field of the page cannot take keystrokes under the lock cover.
    func setHidden(_ isHidden: Bool) {
        webView.isHidden = isHidden
        guard isHidden else { return }
        webView.endEditing(true)
        webView.callAsyncJavaScript(
            "document.activeElement?.blur?.()", arguments: [:], in: nil, in: .page
        ) { _ in }
    }

    // MARK: Messages

    func userContentController(_ controller: WKUserContentController, didReceive message: WKScriptMessage) {
        let frame = message.frameInfo
        let origin = frame.securityOrigin
        guard frame.isMainFrame, policy.isInstanceOrigin(scheme: origin.protocol, host: origin.host, port: origin.port)
        else { return Self.log("A page message from another frame or origin was refused") }
        do {
            let decoded = try EmbedPageMessage.decode(
                message.body, instanceURL: policy.instanceURL, allowsLoopbackHTTP: policy.allowsLoopbackHTTP)
            model?.receive(decoded)
        } catch {
            Self.log("A page message was refused: \(type(of: error))")
        }
    }

    // MARK: Navigation

    func webView(
        _ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction
    ) async -> WKNavigationActionPolicy {
        let type = navigationAction.navigationType
        let decision = policy.decide(
            url: navigationAction.request.url, method: navigationAction.request.httpMethod,
            isMainFrame: navigationAction.targetFrame?.isMainFrame ?? false,
            isUserInitiated: type == .linkActivated || type == .formSubmitted)
        switch decision {
        case .allow: return .allow
        case .cancel: return .cancel
        case .openLink(let url):
            model?.open(link: url)
            return .cancel
        case .openThread(let id):
            model?.handleRequest(.openThread(id))
            return .cancel
        }
    }

    func webView(
        _ webView: WKWebView, decidePolicyFor navigationResponse: WKNavigationResponse
    ) async -> WKNavigationResponsePolicy {
        guard let url = navigationResponse.response.url,
            let status = (navigationResponse.response as? HTTPURLResponse)?.statusCode,
            let outcome = policy.outcome(
                ofResponseFrom: url, status: status, isMainFrame: navigationResponse.isForMainFrame)
        else { return .allow }
        model?.navigationFailed(outcome)
        return .cancel
    }

    func webView(_ webView: WKWebView, didCommit navigation: WKNavigation!) { model?.navigationCommitted() }

    func webView(
        _ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: any Error
    ) {
        report(error)
    }

    func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: any Error) {
        report(error)
    }

    func webViewWebContentProcessDidTerminate(_ webView: WKWebView) { model?.pageProcessEnded() }

    private func report(_ error: any Error) {
        let nsError = error as NSError
        // WebKit's own "frame load interrupted" follows a response the app cancelled.
        guard nsError.domain == NSURLErrorDomain, nsError.code != NSURLErrorCancelled else { return }
        model?.navigationFailed(.networkError)
    }

    // MARK: Windows

    func webView(
        _ webView: WKWebView, createWebViewWith configuration: WKWebViewConfiguration,
        for navigationAction: WKNavigationAction, windowFeatures: WKWindowFeatures
    ) -> WKWebView? {
        switch policy.decide(
            url: navigationAction.request.url, method: navigationAction.request.httpMethod, isMainFrame: true,
            isUserInitiated: true)
        {
        case .openLink(let url): model?.open(link: url)
        case .openThread(let id): model?.handleRequest(.openThread(id))
        case .allow, .cancel: break
        }
        return nil
    }

    func webView(
        _ webView: WKWebView, decideMediaCapturePermissionsFor origin: WKSecurityOrigin,
        initiatedBy frame: WKFrameInfo, type: WKMediaCaptureType
    ) async -> WKPermissionDecision { .deny }

    private static func log(_ message: String) {
        #if canImport(os)
            Logger(subsystem: "Yak", category: "WebConversation").error("\(message, privacy: .public)")
        #endif
    }
}

/// Passes script messages to its target without keeping it, because a user content controller keeps its handlers.
final class WeakScriptHandler: NSObject, WKScriptMessageHandler {
    private weak var target: (any WKScriptMessageHandler)?

    init(_ target: any WKScriptMessageHandler) { self.target = target }

    func userContentController(_ controller: WKUserContentController, didReceive message: WKScriptMessage) {
        target?.userContentController(controller, didReceive: message)
    }
}
