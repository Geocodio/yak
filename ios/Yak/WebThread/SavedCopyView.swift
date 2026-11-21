import SwiftUI
import UIKit
import WebKit

#if canImport(os)
    import os
#endif

/// When a saved copy was made, in the words of its line: the time for today, the date and time for an earlier day.
enum SavedCopyStamp {
    static func text(for date: Date, now: Date = .now, calendar: Calendar = .current) -> String {
        calendar.isDate(date, inSameDayAs: now)
            ? date.formatted(date: .omitted, time: .shortened) : date.formatted(date: .abbreviated, time: .shortened)
    }
}

/// The line above a saved copy: that it is a copy, from when, and what happens about the live page.
struct SavedCopyLine: View {
    let state: SavedCopyState
    let savedAt: Date
    let retry: () -> Void

    var body: some View {
        let stamp = SavedCopyStamp.text(for: savedAt)
        VStack(alignment: .leading, spacing: Design.Space.tight) {
            text(stamp: stamp).font(.footnote.weight(.medium)).foregroundStyle(Color.yakText)
                .fixedSize(horizontal: false, vertical: true)
            if state == .failed {
                Button(action: retry) { Text("common.retry").fixedSize(horizontal: false, vertical: true) }
                    .font(.footnote.weight(.semibold)).foregroundStyle(Color.yakText).underline()
                    .frame(minHeight: Design.Size.control, alignment: .leading).contentShape(.rect)
                    .accessibilityIdentifier("thread.web.copyRetry")
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.horizontal, Design.Space.pageInset).padding(.vertical, Design.Space.compact)
        .background(Color.yakFill)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("thread.web.copyLine")
        .id(stamp)
    }

    private func text(stamp: String) -> Text {
        switch state {
        case .offline: Text("web.copy.line.offline \(stamp)")
        case .reconnecting: Text("web.copy.line.reconnecting \(stamp)")
        case .slow: Text("web.copy.line.slow \(stamp)")
        case .failed: Text("web.copy.line.failed \(stamp)")
        }
    }
}

/// A saved copy of a thread's page, read-only: page scripts are off, the data store keeps nothing, and every
/// navigation after the archive's own load is refused. A tap on a link, a button or a card says the app is offline.
struct SavedCopyView: UIViewRepresentable {
    let copy: SavedWebCopy
    let instanceURL: URL
    /// The app is locked or covered: the copy is hidden, like the live page.
    let isHidden: Bool
    /// The element to scroll to and mark, once the copy has loaded; each request runs once.
    let focus: SavedCopyFocus?
    /// The copy ran the request with this serial.
    let focusHandled: (Int) -> Void
    let blockedTap: () -> Void
    /// The archive could not be shown.
    let failed: () -> Void
    @Environment(\.colorScheme) private var colorScheme

    func makeCoordinator() -> SavedCopyCoordinator {
        SavedCopyCoordinator(blockedTap: blockedTap, failed: failed, focusHandled: focusHandled)
    }

    func makeUIView(context: Context) -> WKWebView {
        let configuration = WKWebViewConfiguration()
        configuration.websiteDataStore = .nonPersistent()
        configuration.defaultWebpagePreferences.allowsContentJavaScript = false
        configuration.limitsNavigationsToAppBoundDomains = false
        configuration.userContentController.add(
            WeakScriptHandler(context.coordinator), contentWorld: .defaultClient, name: SavedCopyCoordinator.handlerName
        )
        let webView = WKWebView(frame: .zero, configuration: configuration)
        webView.navigationDelegate = context.coordinator
        webView.allowsBackForwardNavigationGestures = false
        webView.allowsLinkPreview = false
        let color = UIColor(Color.yakBackground)
        webView.backgroundColor = color
        webView.scrollView.backgroundColor = color
        webView.underPageBackgroundColor = color
        webView.accessibilityIdentifier = "thread.web.copy"
        webView.accessibilityLabel = String(localized: "web.copy.label")
        context.coordinator.attach(webView)
        return webView
    }

    func updateUIView(_ webView: WKWebView, context: Context) {
        context.coordinator.blockedTap = blockedTap
        context.coordinator.failed = failed
        context.coordinator.focusHandled = focusHandled
        webView.isHidden = isHidden
        webView.overrideUserInterfaceStyle = colorScheme == .dark ? .dark : .light
        webView.pageZoom = WebPageZoom.value(for: webView.traitCollection.preferredContentSizeCategory)
        context.coordinator.load(copy, baseURL: instanceURL)
        context.coordinator.focus(focus)
    }
}

@MainActor
final class SavedCopyCoordinator: NSObject, WKNavigationDelegate, WKScriptMessageHandler {
    static let handlerName = "yakCopy"

    /// Runs in the app's own script world, which runs although the page's scripts are off. It scrolls the
    /// conversation to its newest message, dims what cannot act, and reports a tap on anything that would act.
    private static let readOnlyScript = """
        await new Promise((resolve) => requestAnimationFrame(() => resolve()));
        const scroller = document.querySelector('[data-testid="thread-conversation-scroller"]');
        if (scroller) { scroller.scrollTop = scroller.scrollHeight; }
        const style = document.createElement('style');
        style.textContent = 'button, [role="button"], a, input, textarea, select { opacity: 0.55; cursor: default; }';
        document.head.appendChild(style);
        document.documentElement.classList.add('yak-saved-copy');
        document.addEventListener('click', (event) => {
          if (event.target.closest('a, button, [role="button"], input, textarea, select, summary, label')) {
            event.preventDefault();
            event.stopPropagation();
            window.webkit.messageHandlers.\(handlerName).postMessage('tap');
          }
        }, true);
        """

    /// Scrolls to the element and marks it, with the mark of the live page but static, so Reduce Motion needs no
    /// second path. Reports whether the element is in the copy. The page's scripts are off; this runs in the app's own
    /// script world, which reads the element id as an argument and never builds code from it.
    private static let focusScript = """
        const target = document.getElementById(fragment);
        if (!target) { return false; }
        if (!document.getElementById('yak-focus-style')) {
          const style = document.createElement('style');
          style.id = 'yak-focus-style';
          style.textContent = '[data-yak-target] { outline: 2px solid color-mix(in srgb, currentColor 45%, transparent); outline-offset: 2px; background-color: color-mix(in srgb, currentColor 8%, transparent); }';
          document.head.appendChild(style);
        }
        target.setAttribute('data-yak-target', '');
        if (!target.hasAttribute('tabindex') && target.matches('div, section, article, li')) { target.setAttribute('tabindex', '-1'); }
        target.scrollIntoView({ block: 'center' });
        target.focus({ preventScroll: true });
        setTimeout(() => target.removeAttribute('data-yak-target'), seconds * 1000);
        return true;
        """

    /// Requests that run code, call out or play media are refused. Images, styles and fonts are not: the archive serves
    /// its own through the same loader, so blocking them would blank the copy (measured: an archived image and style
    /// stop rendering under a rule for all loads, and an archived page makes no network request on its own).
    static let blockNetworkRules = """
        [{"trigger": {"url-filter": ".*", "resource-type": ["script", "fetch", "raw", "ping", "media", "popup"]},
          "action": {"type": "block"}}]
        """

    weak var webView: WKWebView?
    var blockedTap: () -> Void
    var failed: () -> Void
    var focusHandled: (Int) -> Void
    private var pendingFocus: SavedCopyFocus?
    private var handledFocusSerial = 0
    /// The copy loaded and its own script ran, so a scroll to a target comes after the scroll to the newest message.
    private var isReadyForFocus = false
    private var loadedKey: String?
    private var isLoaded = false
    private var hasAllowedArchiveLoad = false
    private enum Rules { case compiling, ready, failed }
    private var rules = Rules.compiling
    private var pending: (copy: SavedWebCopy, baseURL: URL)?

    init(
        blockedTap: @escaping () -> Void, failed: @escaping () -> Void, focusHandled: @escaping (Int) -> Void
    ) {
        self.blockedTap = blockedTap
        self.failed = failed
        self.focusHandled = focusHandled
    }

    /// The rule list is added before the first load, so no request of the copy can reach the network.
    func attach(_ webView: WKWebView) {
        self.webView = webView
        Task { [weak self] in
            let list = try? await WKContentRuleListStore.default().compileContentRuleList(
                forIdentifier: "SavedCopyBlockNetwork", encodedContentRuleList: Self.blockNetworkRules)
            guard let self else { return }
            if let list {
                webView.configuration.userContentController.add(list)
                rules = .ready
            } else {
                Self.log("The saved copy could not block the network")
                rules = .failed
            }
            if let pending { load(pending.copy, baseURL: pending.baseURL) }
        }
    }

    func load(_ copy: SavedWebCopy, baseURL: URL) {
        let key = "\(copy.threadID)/\(copy.savedAt.timeIntervalSince1970)"
        guard loadedKey != key, let webView else { return }
        // Without the rules the copy is not shown: a failed compile ends in the plain offline state.
        switch rules {
        case .compiling:
            pending = (copy, baseURL)
            return
        case .failed:
            failed()
            return
        case .ready: break
        }
        pending = nil
        loadedKey = key
        isLoaded = false
        isReadyForFocus = false
        hasAllowedArchiveLoad = false
        webView.load(
            copy.archive, mimeType: "application/x-webarchive", characterEncodingName: "utf-8", baseURL: baseURL)
    }

    func userContentController(_ controller: WKUserContentController, didReceive message: WKScriptMessage) {
        blockedTap()
    }

    /// A request is run once its copy has loaded; a request already run is not run again.
    func focus(_ request: SavedCopyFocus?) {
        guard let request, request.serial != handledFocusSerial else { return }
        pendingFocus = request
        runPendingFocus()
    }

    private func runPendingFocus() {
        guard isReadyForFocus, let request = pendingFocus, let webView else { return }
        pendingFocus = nil
        handledFocusSerial = request.serial
        // The model forgets the request now, so a view made again for this copy does not scroll it again.
        focusHandled(request.serial)
        webView.callAsyncJavaScript(
            Self.focusScript, arguments: ["fragment": request.fragment, "seconds": Motion.focusHighlightSeconds],
            in: nil, in: .defaultClient
        ) { result in
            if case .success(let found) = result, found as? Bool == true { return }
            Self.log("The saved copy does not hold the target of a push or link")
        }
    }

    /// The archive's own load is the only navigation; anything after it is a tap, which does nothing but say so.
    func webView(
        _ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction
    ) async -> WKNavigationActionPolicy {
        // Only the main frame's load of the archive itself, once; a subframe or a second navigation is refused.
        if !hasAllowedArchiveLoad, navigationAction.targetFrame?.isMainFrame == true,
            navigationAction.navigationType == .other
        {
            hasAllowedArchiveLoad = true
            return .allow
        }
        if navigationAction.navigationType == .linkActivated || navigationAction.navigationType == .formSubmitted {
            blockedTap()
        }
        return .cancel
    }

    func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
        isLoaded = true
        webView.callAsyncJavaScript(Self.readOnlyScript, arguments: [:], in: nil, in: .defaultClient) {
            [weak self] result in
            if case .failure = result { Self.log("The saved copy could not be made read-only") }
            // The newest-message scroll has run; the target scroll comes after it.
            Task { @MainActor in
                self?.isReadyForFocus = true
                self?.runPendingFocus()
            }
        }
    }

    func webView(
        _ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: any Error
    ) {
        Self.log("The saved copy did not load")
        failed()
    }

    func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: any Error) {
        Self.log("The saved copy did not finish loading")
        failed()
    }

    private nonisolated static func log(_ message: String) {
        #if canImport(os)
            Logger(subsystem: "Yak", category: "WebConversation").error("\(message, privacy: .public)")
        #endif
    }
}
