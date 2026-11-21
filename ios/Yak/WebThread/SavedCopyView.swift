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
    let blockedTap: () -> Void
    @Environment(\.colorScheme) private var colorScheme

    func makeCoordinator() -> SavedCopyCoordinator { SavedCopyCoordinator(blockedTap: blockedTap) }

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
        context.coordinator.webView = webView
        return webView
    }

    func updateUIView(_ webView: WKWebView, context: Context) {
        context.coordinator.blockedTap = blockedTap
        webView.isHidden = isHidden
        webView.overrideUserInterfaceStyle = colorScheme == .dark ? .dark : .light
        webView.pageZoom = WebPageZoom.value(for: webView.traitCollection.preferredContentSizeCategory)
        context.coordinator.load(copy, baseURL: instanceURL)
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

    weak var webView: WKWebView?
    var blockedTap: () -> Void
    private var loadedKey: String?
    private var isLoaded = false

    init(blockedTap: @escaping () -> Void) { self.blockedTap = blockedTap }

    func load(_ copy: SavedWebCopy, baseURL: URL) {
        let key = "\(copy.threadID)/\(copy.savedAt.timeIntervalSince1970)"
        guard loadedKey != key, let webView else { return }
        loadedKey = key
        isLoaded = false
        webView.load(
            copy.archive, mimeType: "application/x-webarchive", characterEncodingName: "utf-8", baseURL: baseURL)
    }

    func userContentController(_ controller: WKUserContentController, didReceive message: WKScriptMessage) {
        blockedTap()
    }

    /// The archive's own load is the only navigation; anything after it is a tap, which does nothing but say so.
    func webView(
        _ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction
    ) async -> WKNavigationActionPolicy {
        if !isLoaded, navigationAction.navigationType == .other { return .allow }
        if navigationAction.navigationType == .linkActivated || navigationAction.navigationType == .formSubmitted {
            blockedTap()
        }
        return .cancel
    }

    func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
        isLoaded = true
        webView.callAsyncJavaScript(Self.readOnlyScript, arguments: [:], in: nil, in: .defaultClient) { result in
            if case .failure = result { Self.log("The saved copy could not be made read-only") }
        }
    }

    func webView(
        _ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: any Error
    ) {
        Self.log("The saved copy did not load")
    }

    private nonisolated static func log(_ message: String) {
        #if canImport(os)
            Logger(subsystem: "Yak", category: "WebConversation").error("\(message, privacy: .public)")
        #endif
    }
}
