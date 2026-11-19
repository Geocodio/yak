import SwiftUI
import WebKit

/// The preview's web view. It wraps `WKWebView` because only that gives the pane a data store per instance, the
/// status code of a main-frame response, `window.open` and page dialogs. It runs no scripts of its own and has no
/// bridge to the app.
struct PreviewWebView: UIViewRepresentable {
    let model: PreviewModel
    let dataStore: WKWebsiteDataStore

    func makeCoordinator() -> PreviewWebCoordinator { PreviewWebCoordinator(model: model) }

    func makeUIView(context: Context) -> WKWebView {
        let configuration = WKWebViewConfiguration()
        configuration.websiteDataStore = dataStore
        configuration.limitsNavigationsToAppBoundDomains = false
        let view = WKWebView(frame: .zero, configuration: configuration)
        view.navigationDelegate = context.coordinator
        view.uiDelegate = context.coordinator
        view.allowsBackForwardNavigationGestures = true
        // A page without a background of its own is drawn the way a browser draws it: black text on white.
        view.isOpaque = true
        view.backgroundColor = .white
        view.scrollView.backgroundColor = .white
        view.accessibilityIdentifier = "preview.page"
        context.coordinator.adopt(view)
        model.attach(context.coordinator)
        return view
    }

    func updateUIView(_ view: WKWebView, context: Context) {}
}

@MainActor
final class PreviewWebCoordinator: NSObject, WKNavigationDelegate, WKUIDelegate, PreviewPageDriver {
    static let loadTimeout: TimeInterval = 30

    private let model: PreviewModel
    private weak var view: WKWebView?
    private var progressObservation: NSKeyValueObservation?

    init(model: PreviewModel) { self.model = model }

    func adopt(_ view: WKWebView) {
        self.view = view
        progressObservation = view.observe(\.estimatedProgress, options: [.new]) { [weak self] view, _ in
            let progress = view.estimatedProgress
            Task { @MainActor in self?.model.pageProgress(progress) }
        }
    }

    // MARK: Driver

    var currentURL: URL? { view?.url }

    func load(_ url: URL) {
        view?.load(URLRequest(url: url, timeoutInterval: Self.loadTimeout))
    }

    func reload() { view?.reload() }
    func stopLoading() { view?.stopLoading() }

    // MARK: Navigation

    func webView(
        _ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction
    ) async -> WKNavigationActionPolicy {
        guard let url = navigationAction.request.url else { return .cancel }
        let type = navigationAction.navigationType
        let decision = model.decide(
            navigation: url, isMainFrame: navigationAction.targetFrame?.isMainFrame ?? true,
            isUserInitiated: type == .linkActivated || type == .formSubmitted)
        return decision == .allow ? .allow : .cancel
    }

    func webView(
        _ webView: WKWebView, decidePolicyFor navigationResponse: WKNavigationResponse
    ) async -> WKNavigationResponsePolicy {
        guard let url = navigationResponse.response.url else { return .allow }
        let status = (navigationResponse.response as? HTTPURLResponse)?.statusCode ?? 200
        let isAllowed = model.responded(
            status: status, url: url, isMainFrame: navigationResponse.isForMainFrame,
            canShow: navigationResponse.canShowMIMEType)
        return isAllowed ? .allow : .cancel
    }

    func webView(_ webView: WKWebView, didStartProvisionalNavigation navigation: WKNavigation!) {
        model.pageStarted()
    }

    func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
        model.pageFinished(url: webView.url)
    }

    func webView(
        _ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: any Error
    ) {
        report(error)
    }

    func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: any Error) {
        report(error)
    }

    func webViewWebContentProcessDidTerminate(_ webView: WKWebView) { model.pageProcessEnded() }

    private func report(_ error: any Error) {
        let nsError = error as NSError
        // WebKit's own "frame load interrupted" follows a response the app cancelled.
        guard nsError.domain == NSURLErrorDomain else { return }
        model.pageFailed(URLError.Code(rawValue: nsError.code))
    }

    // MARK: Windows, dialogs and permissions

    func webView(
        _ webView: WKWebView, createWebViewWith configuration: WKWebViewConfiguration,
        for navigationAction: WKNavigationAction, windowFeatures: WKWindowFeatures
    ) -> WKWebView? {
        guard let url = navigationAction.request.url else { return nil }
        let decision = model.decide(
            navigation: url, isMainFrame: true, isUserInitiated: navigationAction.navigationType == .linkActivated)
        if decision == .allow { load(url) }
        return nil
    }

    func webView(
        _ webView: WKWebView, decideMediaCapturePermissionsFor origin: WKSecurityOrigin,
        initiatedBy frame: WKFrameInfo, type: WKMediaCaptureType
    ) async -> WKPermissionDecision { .deny }

    func webView(
        _ webView: WKWebView, runJavaScriptAlertPanelWithMessage message: String, initiatedByFrame frame: WKFrameInfo
    ) async {
        _ = await present(title: frame.request.url?.host(), message: message, field: nil, hasCancel: false)
    }

    func webView(
        _ webView: WKWebView, runJavaScriptConfirmPanelWithMessage message: String, initiatedByFrame frame: WKFrameInfo
    ) async -> Bool {
        await present(title: frame.request.url?.host(), message: message, field: nil, hasCancel: true) != nil
    }

    func webView(
        _ webView: WKWebView, runJavaScriptTextInputPanelWithPrompt prompt: String, defaultText: String?,
        initiatedByFrame frame: WKFrameInfo
    ) async -> String? {
        await present(title: frame.request.url?.host(), message: prompt, field: defaultText ?? "", hasCancel: true)
    }

    /// Shows the page's dialog titled with the preview host. The result is nil when the person cancels, the text of
    /// the field (or an empty string) otherwise.
    private func present(title: String?, message: String, field: String?, hasCancel: Bool) async -> String? {
        guard let presenter = Self.topViewController() else { return nil }
        return await withCheckedContinuation { continuation in
            let alert = UIAlertController(title: title, message: message, preferredStyle: .alert)
            if let field { alert.addTextField { $0.text = field } }
            if hasCancel {
                alert.addAction(
                    UIAlertAction(title: String(localized: "preview.dialog.cancel"), style: .cancel) { _ in
                        continuation.resume(returning: nil)
                    })
            }
            alert.addAction(
                UIAlertAction(title: String(localized: "preview.dialog.ok"), style: .default) { _ in
                    continuation.resume(returning: alert.textFields?.first?.text ?? "")
                })
            presenter.present(alert, animated: true)
        }
    }

    private static func topViewController() -> UIViewController? {
        let scenes = UIApplication.shared.connectedScenes.compactMap { $0 as? UIWindowScene }
        var top = scenes.flatMap(\.windows).first(where: \.isKeyWindow)?.rootViewController
        while let presented = top?.presentedViewController { top = presented }
        return top
    }
}
