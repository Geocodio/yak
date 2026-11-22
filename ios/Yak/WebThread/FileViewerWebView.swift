import SwiftUI
import UIKit
import WebKit
import YakKit

#if canImport(os)
    import os
#endif

/// The web view of the file viewer. It has no script bridge: the page it shows is a shared file's page, which does not
/// speak to the app. It loads only what `FileViewerPolicy` allows, and every tap that would leave the file goes to the
/// app's own link handling.
struct FileViewerWebView: UIViewRepresentable {
    let model: FileViewerModel
    let dataStore: WKWebsiteDataStore
    let version: String
    let isHidden: Bool
    let openLink: (URL) -> Void
    let openThread: (String) -> Void
    let notify: @MainActor (String) -> Void
    @Environment(\.colorScheme) private var colorScheme

    func makeUIView(context: Context) -> WKWebView {
        let page = FileViewerPageView(
            model: model, dataStore: dataStore, version: version, openLink: openLink, openThread: openThread,
            notify: notify)
        context.coordinator.page = page
        model.attach(page)
        return page.webView
    }

    func updateUIView(_ webView: WKWebView, context: Context) {
        webView.overrideUserInterfaceStyle = colorScheme == .dark ? .dark : .light
        context.coordinator.page?.setHidden(isHidden)
        context.coordinator.page?.openLink = openLink
        context.coordinator.page?.openThread = openThread
    }

    func makeCoordinator() -> Coordinator { Coordinator() }

    /// Keeps the page alive as long as the web view is on screen; the web view does not keep its delegates.
    @MainActor final class Coordinator {
        var page: FileViewerPageView?
    }
}

@MainActor
final class FileViewerPageView: NSObject, FileViewerPage, WKNavigationDelegate, WKUIDelegate {
    let webView: WKWebView
    var openLink: (URL) -> Void
    var openThread: (String) -> Void
    private weak var model: FileViewerModel?
    private let policy: FileViewerPolicy
    private let quickLook: WebQuickLook

    init(
        model: FileViewerModel, dataStore: WKWebsiteDataStore, version: String, openLink: @escaping (URL) -> Void,
        openThread: @escaping (String) -> Void, notify: @escaping @MainActor (String) -> Void
    ) {
        self.model = model
        policy = model.policy
        self.openLink = openLink
        self.openThread = openThread
        quickLook = WebQuickLook(policy: model.policy.navigation, notify: notify)
        let configuration = WKWebViewConfiguration()
        configuration.websiteDataStore = dataStore
        configuration.limitsNavigationsToAppBoundDomains = false
        configuration.applicationNameForUserAgent = "YakApp/\(version) (embed)"
        webView = WKWebView(frame: .zero, configuration: configuration)
        super.init()
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
        webView.accessibilityIdentifier = "fileViewer.web"
        webView.accessibilityLabel = String(localized: "viewer.page.label")
    }

    func load(_ request: URLRequest) { webView.load(request) }

    func stop() { webView.stopLoading() }

    /// A hidden page also gives up focus, so a field of the report cannot take keystrokes under the lock cover.
    func setHidden(_ isHidden: Bool) {
        guard webView.isHidden != isHidden else { return }
        webView.isHidden = isHidden
        guard isHidden else { return }
        webView.endEditing(true)
        webView.callAsyncJavaScript("document.activeElement?.blur?.()", arguments: [:], in: nil, in: .page) { _ in }
    }

    // MARK: Navigation

    func webView(
        _ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction
    ) async -> WKNavigationActionPolicy {
        let type = navigationAction.navigationType
        // A new window (a link with a target) has no frame yet; it is a link of the main frame for the rule.
        let target = navigationAction.targetFrame
        switch policy.decide(
            url: navigationAction.request.url, method: navigationAction.request.httpMethod,
            isMainFrame: target?.isMainFrame ?? true, isUserInitiated: type == .linkActivated || type == .formSubmitted)
        {
        case .allow: return target == nil ? .cancel : .allow
        case .cancel: return .cancel
        case .openLink(let url):
            openLink(url)
            return .cancel
        case .openThread(let id):
            openThread(id)
            return .cancel
        case .download(let url):
            quickLook.start(url: url, name: model?.title ?? "report.html", in: webView)
            return .cancel
        }
    }

    func webView(
        _ webView: WKWebView, decidePolicyFor navigationResponse: WKNavigationResponse
    ) async -> WKNavigationResponsePolicy {
        guard let url = navigationResponse.response.url,
            let status = (navigationResponse.response as? HTTPURLResponse)?.statusCode,
            let failure = policy.failure(ofResponseFrom: url, status: status)
        else { return .allow }
        model?.failed(failure)
        return .cancel
    }

    func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
        model?.finished(pageTitle: webView.title)
    }

    func webView(
        _ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: any Error
    ) {
        report(error)
    }

    func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: any Error) {
        report(error)
    }

    func webViewWebContentProcessDidTerminate(_ webView: WKWebView) { model?.processEnded() }

    private func report(_ error: any Error) {
        let nsError = error as NSError
        // A response the app cancelled ends as an interrupted load, and a cancelled load is not a failure.
        guard nsError.domain == NSURLErrorDomain, nsError.code != NSURLErrorCancelled else { return }
        model?.loadFailed()
    }

    // MARK: Windows and permissions

    /// A page never opens a window of its own: a tap goes through the rule above, a script gets nothing.
    func webView(
        _ webView: WKWebView, createWebViewWith configuration: WKWebViewConfiguration,
        for navigationAction: WKNavigationAction, windowFeatures: WKWindowFeatures
    ) -> WKWebView? {
        nil
    }

    func webView(
        _ webView: WKWebView, decideMediaCapturePermissionsFor origin: WKSecurityOrigin,
        initiatedBy frame: WKFrameInfo, type: WKMediaCaptureType
    ) async -> WKPermissionDecision { .deny }
}
