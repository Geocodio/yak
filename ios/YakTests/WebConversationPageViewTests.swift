import Foundation
import Testing
import WebKit
import YakKit

@testable import Yak

/// A navigation action with the request a test chooses.
private final class FakeNavigationAction: WKNavigationAction {
    private let fakeRequest: URLRequest
    init(url: String) { fakeRequest = URLRequest(url: URL(string: url)!) }
    override var request: URLRequest { fakeRequest }
}

@MainActor private final class Recorder {
    var requests: [WebConversationRequest] = []
}

extension WebKitTests {
    @MainActor @Suite struct WebConversationPageViewTests {
        /// The view keeps its model weakly, so the test holds the model next to it.
        private func page() -> (view: WebConversationPageView, model: WebConversationModel, recorder: Recorder) {
            let model = WebConversationModel(
                instanceURL: URL(string: "https://yak.example.com")!, allowsLoopbackHTTP: false,
                mint: { _ in .failed(.network) })
            let recorder = Recorder()
            model.handleRequest = { recorder.requests.append($0) }
            let view = WebConversationPageView(model: model, dataStore: .nonPersistent(), version: "1")
            return (view, model, recorder)
        }

        private func createWindow(_ view: WebConversationPageView, url: String) -> WKWebView? {
            view.webView(
                view.webView, createWebViewWith: WKWebViewConfiguration(), for: FakeNavigationAction(url: url),
                windowFeatures: WKWindowFeatures())
        }

        @Test func aWindowTheTargetBlankLinkOpensRoutesTheAddressAndMakesNoWebView() {
            let (view, model, recorder) = page()
            #expect(createWindow(view, url: "https://example.com/docs") == nil)
            #expect(createWindow(view, url: "https://yak.example.com/threads/a1b2c3d4") == nil)
            #expect(createWindow(view, url: "javascript:alert(1)") == nil)
            #expect(createWindow(view, url: "file:///etc/passwd") == nil)
            #expect(
                recorder.requests == [.link(URL(string: "https://example.com/docs")!), .openThread("a1b2c3d4")])
            withExtendedLifetime(model) {}
        }

        @Test func theWebViewIsLabelledAndAHiddenPageGivesUpEditing() {
            let (view, model, _) = page()
            #expect(view.webView.accessibilityLabel == String(localized: "web.page.label"))
            view.setHidden(true)
            #expect(view.webView.isHidden)
            view.setHidden(false)
            #expect(!view.webView.isHidden)
            withExtendedLifetime(model) {}
        }
    }
}
