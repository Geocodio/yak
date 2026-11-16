import SwiftUI
import WebKit
import YakKit

/// The task's conversation, rendered by the server as HTML. JavaScript is off, nothing is stored, a tapped link
/// goes to the app's browser sheet, and the view is as tall as its content.
struct TaskBodyView: UIViewRepresentable {
    let entry: TaskConversationEntry
    let openLink: (URL) -> Void
    @Binding var height: CGFloat

    func makeCoordinator() -> Coordinator { Coordinator(self) }

    func makeUIView(context: Context) -> WKWebView {
        let configuration = WKWebViewConfiguration()
        configuration.websiteDataStore = .nonPersistent()
        configuration.defaultWebpagePreferences.allowsContentJavaScript = false
        let view = WKWebView(frame: .zero, configuration: configuration)
        view.isOpaque = false
        view.backgroundColor = .clear
        view.scrollView.isScrollEnabled = false
        view.navigationDelegate = context.coordinator
        context.coordinator.observe(view)
        return view
    }

    func updateUIView(_ view: WKWebView, context: Context) {
        context.coordinator.parent = self
        let html = Self.document(entry)
        guard html != context.coordinator.loaded else { return }
        context.coordinator.loaded = html
        view.loadHTMLString(html, baseURL: nil)
    }

    /// One entry inside the app's stylesheet. Its label is native text above the view.
    static func document(_ entry: TaskConversationEntry) -> String {
        let sections = entry.bodyHtml
        return """
            <!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1">
            <style>
            :root { color-scheme: light dark; }
            body { font: -apple-system-body; margin: 0; padding: 0; color: CanvasText; background: transparent;
                   overflow-wrap: anywhere; }
            p { margin: 0 0 8px 0; } a { color: LinkText; }
            pre, code { font: -apple-system-footnote; font-family: ui-monospace, Menlo, monospace; }
            pre { overflow-x: auto; padding: 8px; background: rgba(127,127,127,0.12); border-radius: 8px; }
            img { max-width: 100%; height: auto; border-radius: 8px; }
            </style></head><body>\(sections)</body></html>
            """
    }

    static func label(_ kind: String) -> String {
        switch kind {
        case "user", "you": String(localized: "task.body.you")
        case "review": String(localized: "task.body.review")
        default: String(localized: "task.body.yak")
        }
    }

    @MainActor final class Coordinator: NSObject, WKNavigationDelegate {
        var parent: TaskBodyView
        var loaded = ""
        private var observation: NSKeyValueObservation?

        init(_ parent: TaskBodyView) { self.parent = parent }

        func observe(_ view: WKWebView) {
            observation = view.scrollView.observe(\.contentSize, options: [.new]) { [weak self, weak view] _, _ in
                Task { @MainActor in
                    guard let self, let next = view?.scrollView.contentSize.height,
                        abs(self.parent.height - next) > 0.5
                    else { return }
                    self.parent.height = next
                }
            }
        }

        func webView(
            _ webView: WKWebView, decidePolicyFor action: WKNavigationAction,
            decisionHandler: @escaping @MainActor (WKNavigationActionPolicy) -> Void
        ) {
            guard action.navigationType == .linkActivated else {
                decisionHandler(.allow)
                return
            }
            decisionHandler(.cancel)
            if let url = action.request.url, ["http", "https"].contains(url.scheme?.lowercased()) {
                parent.openLink(url)
            }
        }
    }
}

/// One conversation entry: its label as a heading, then its rendered body.
struct TaskEntryView: View {
    let entry: TaskConversationEntry
    let openLink: (URL) -> Void
    @State private var height: CGFloat = 24

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(verbatim: TaskBodyView.label(entry.kind)).font(.footnote.weight(.semibold))
                .foregroundStyle(Color.yakMuted).accessibilityAddTraits(.isHeader)
            TaskBodyView(entry: entry, openLink: openLink, height: $height).frame(height: max(height, 24))
        }
    }
}
