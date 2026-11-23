import SwiftUI
import UIKit
import UIKit.UIGestureRecognizerSubclass
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

/// Records when a finger touches the web view, and gets in the way of nothing: the touch goes on to the page.
private final class TouchRecorder: UIGestureRecognizer, UIGestureRecognizerDelegate {
    var onTouch: () -> Void = {}

    override init(target: Any?, action: Selector?) {
        super.init(target: target, action: action)
        cancelsTouchesInView = false
        delaysTouchesBegan = false
        delaysTouchesEnded = false
        delegate = self
    }

    override func touchesBegan(_ touches: Set<UITouch>, with event: UIEvent) {
        onTouch()
        state = .failed
    }

    func gestureRecognizer(
        _ gestureRecognizer: UIGestureRecognizer, shouldRecognizeSimultaneouslyWith other: UIGestureRecognizer
    ) -> Bool { true }
}

@MainActor
final class FileViewerPageView: NSObject, FileViewerPage, WKNavigationDelegate, WKUIDelegate {
    let webView: WKWebView
    var openLink: (URL) -> Void
    var openThread: (String) -> Void
    private weak var model: FileViewerModel?
    private let policy: FileViewerPolicy
    private let quickLook: WebQuickLook
    /// What a navigation that the report asks for must follow: a touch of the person, just before it.
    private var touches = RecentTouchGate()
    private let now: () -> ContinuousClock.Instant
    private let recorder = TouchRecorder(target: nil, action: nil)

    init(
        model: FileViewerModel, dataStore: WKWebsiteDataStore, version: String, openLink: @escaping (URL) -> Void,
        openThread: @escaping (String) -> Void, notify: @escaping @MainActor (String) -> Void,
        now: @escaping () -> ContinuousClock.Instant = { ContinuousClock.now }
    ) {
        self.now = now
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
        recorder.onTouch = { [weak self] in
            guard let self else { return }
            self.touches.touched(at: self.now())
        }
        webView.addGestureRecognizer(recorder)
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

    func close() {
        webView.stopLoading()
        quickLook.cancel()
    }

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
        // A new window (a link with a target) has no frame yet; the link's own frame says where it comes from.
        let target = navigationAction.targetFrame
        let isFromReport = !navigationAction.sourceFrame.isMainFrame
        let decision = policy.decide(
            url: navigationAction.request.url, method: navigationAction.request.httpMethod,
            isMainFrame: target?.isMainFrame ?? !isFromReport,
            isUserInitiated: type == .linkActivated || type == .formSubmitted)
        switch decision {
        case .allow: return target == nil ? .cancel : .allow
        case .cancel: return .cancel
        case .signedOut:
            model?.failed(.signedOut)
            return .cancel
        case .openLink(let url):
            // Script in the report can click a link on a timer. Only a touch just before it makes it a tap.
            guard !isFromReport || touches.takesAction(at: now()) else { return .cancel }
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
        guard let url = navigationResponse.response.url else { return .allow }
        let response = navigationResponse.response as? HTTPURLResponse
        switch policy.responseAction(
            url: url, status: response?.statusCode ?? 200, isMainFrame: navigationResponse.isForMainFrame,
            canShowMIMEType: navigationResponse.canShowMIMEType,
            contentDisposition: response?.value(forHTTPHeaderField: "Content-Disposition"))
        {
        case .allow: return .allow
        case .fail(let failure):
            model?.failed(failure)
            return .cancel
        case .quickLook(let fileURL):
            let name = navigationResponse.response.suggestedFilename ?? model?.title ?? "file"
            if quickLook.start(url: fileURL, name: name, in: webView) {
                model?.handedToQuickLook()
            } else {
                model?.failed(.unavailable)
            }
            return .cancel
        }
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
        // A navigation the viewer cancelled itself ends as a cancelled or interrupted load; every other error is one.
        guard !FileViewerPolicy.isOwnCancellation(domain: nsError.domain, code: nsError.code) else { return }
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
