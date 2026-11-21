import QuickLook
import UIKit
import WebKit
import YakKit

#if canImport(os)
    import os
#endif

/// Shows one same-origin file of the page: it is downloaded through the web view's own session into a folder of its
/// own, within `QuickLookFile.maximumBytes`, and deleted when the preview or the share sheet closes. Quick Look shows
/// only the types of an allow-list; every other type goes to the share sheet. A refusal or a failure says so.
@MainActor
final class WebQuickLook: NSObject, WKDownloadDelegate, QLPreviewControllerDataSource,
    @preconcurrency QLPreviewControllerDelegate
{
    /// Puts a controller on screen from the scene that owns the web view; false when there is nothing to present from.
    typealias Present = @MainActor (UIViewController, WKWebView) -> Bool

    private let policy: EmbedNavigationPolicy
    private let present: Present
    private let notify: @MainActor (String) -> Void
    private weak var webView: WKWebView?
    private var folder: URL?
    private var file: URL?
    private var presentation = QuickLookFile.Presentation.preview
    private var current: WKDownload?
    private var isBusy = false
    private var progressObservation: NSKeyValueObservation?
    private var sizeWatch: Task<Void, Never>?

    /// How often the size of the growing file is checked against the cap, besides the download's own progress.
    private static let sizeWatchInterval: Duration = .milliseconds(250)

    static var root: URL {
        FileManager.default.temporaryDirectory.appending(path: "YakQuickLook", directoryHint: .isDirectory)
    }

    /// Removes the folders a run that ended with a preview open left behind. The app calls it once at launch, before
    /// any window exists, so it cannot delete a file another window is showing.
    static func removeStaleFolders() { try? FileManager.default.removeItem(at: root) }

    /// The topmost controller of the scene that owns `webView`.
    static func presenter(for webView: WKWebView) -> UIViewController? {
        var top = webView.window?.rootViewController
        while let presented = top?.presentedViewController { top = presented }
        return top
    }

    init(
        policy: EmbedNavigationPolicy,
        present: @escaping Present = { controller, webView in
            guard let presenter = WebQuickLook.presenter(for: webView) else { return false }
            if let popover = controller.popoverPresentationController {
                popover.sourceView = webView
                popover.sourceRect = CGRect(x: webView.bounds.midX, y: webView.bounds.midY, width: 1, height: 1)
            }
            presenter.present(controller, animated: true)
            return true
        },
        notify: @escaping @MainActor (String) -> Void = { _ in }
    ) {
        self.policy = policy
        self.present = present
        self.notify = notify
        super.init()
    }

    /// Starts the download; one at a time, and only for an address on the instance's own origin.
    func start(url: URL, name: String, in webView: WKWebView) {
        guard !isBusy, policy.isInstanceOrigin(url) else { return }
        isBusy = true
        self.webView = webView
        presentation = QuickLookFile.presentation(forFileName: name)
        let target = Self.root.appending(path: UUID().uuidString, directoryHint: .isDirectory)
            .appending(path: QuickLookFile.fileName(name), directoryHint: .notDirectory)
        file = target
        folder = target.deletingLastPathComponent()
        Task { [weak self] in
            let download = await webView.startDownload(using: URLRequest(url: url))
            guard let self, isBusy else {
                _ = await download.cancel()
                return
            }
            current = download
            download.delegate = self
            // A body that outgrows the cap is cancelled and its partial file removed. The progress may name no total
            // (a compressed, chunked answer), so the file's own size is watched as well.
            progressObservation = download.progress.observe(\.completedUnitCount) { [weak self] progress, _ in
                guard QuickLookFile.mustCancel(receivedBytes: progress.completedUnitCount) else { return }
                Task { @MainActor in self?.cancelForSize(download) }
            }
            sizeWatch = Task { [weak self] in
                while !Task.isCancelled {
                    do { try await Task.sleep(for: Self.sizeWatchInterval) } catch { return }
                    guard let self, let file, let size = Self.size(of: file) else { continue }
                    if QuickLookFile.mustCancel(receivedBytes: size) { return cancelForSize(download) }
                }
            }
        }
    }

    private static func size(of file: URL) -> Int64? {
        (try? FileManager.default.attributesOfItem(atPath: file.path))?[.size] as? Int64
    }

    private func cancelForSize(_ download: WKDownload) {
        guard current === download, isBusy else { return }
        Task { _ = await download.cancel() }
        fail(String(localized: "web.quickLook.tooLarge"))
    }

    // MARK: Download

    func download(
        _ download: WKDownload, decideDestinationUsing response: URLResponse, suggestedFilename: String,
        completionHandler: @escaping @MainActor (URL?) -> Void
    ) {
        guard download === current else { return completionHandler(nil) }
        let status = (response as? HTTPURLResponse)?.statusCode ?? 200
        guard (200..<300).contains(status) else {
            completionHandler(nil)
            return fail(String(localized: "web.quickLook.failed"))
        }
        guard QuickLookFile.admits(expectedLength: response.expectedContentLength) else {
            completionHandler(nil)
            return fail(String(localized: "web.quickLook.tooLarge"))
        }
        guard let file, let folder else { return completionHandler(nil) }
        do {
            try FileManager.default.createDirectory(
                at: folder, withIntermediateDirectories: true, attributes: [.protectionKey: FileProtectionType.complete]
            )
            completionHandler(file)
        } catch {
            completionHandler(nil)
            fail(String(localized: "web.quickLook.failed"))
        }
    }

    func download(
        _ download: WKDownload, willPerformHTTPRedirection response: HTTPURLResponse, newRequest request: URLRequest,
        decisionHandler: @escaping @MainActor (WKDownload.RedirectPolicy) -> Void
    ) {
        decisionHandler(request.url.map(policy.isInstanceOrigin) == true ? .allow : .cancel)
    }

    func downloadDidFinish(_ download: WKDownload) {
        guard download === current, isBusy else { return }
        sizeWatch?.cancel()
        guard let file, let size = Self.size(of: file), QuickLookFile.isWithinLimit(bytes: size) else {
            return fail(String(localized: "web.quickLook.tooLarge"))
        }
        guard let webView else { return fail(String(localized: "web.quickLook.failed")) }
        let shown: Bool
        switch presentation {
        case .preview:
            let controller = QLPreviewController()
            controller.dataSource = self
            controller.delegate = self
            shown = present(controller, webView)
        case .share:
            let controller = UIActivityViewController(activityItems: [file], applicationActivities: nil)
            controller.completionWithItemsHandler = { [weak self] _, _, _, _ in
                Task { @MainActor in self?.finish() }
            }
            shown = present(controller, webView)
        }
        if !shown { fail(String(localized: "web.quickLook.failed")) }
    }

    func download(_ download: WKDownload, didFailWithError error: any Error, resumeData: Data?) {
        guard download === current else { return }
        #if canImport(os)
            Logger(subsystem: "Yak", category: "WebQuickLook").error("A preview download failed")
        #endif
        fail(String(localized: "web.quickLook.failed"))
    }

    // MARK: Preview

    func numberOfPreviewItems(in controller: QLPreviewController) -> Int { file == nil ? 0 : 1 }

    func previewController(_ controller: QLPreviewController, previewItemAt index: Int) -> any QLPreviewItem {
        (file ?? Self.root) as NSURL
    }

    func previewControllerDidDismiss(_ controller: QLPreviewController) { finish() }

    /// A refusal or failure while the download or the presentation runs: said once, then the work is cleaned up.
    private func fail(_ text: String) {
        guard isBusy else { return }
        notify(text)
        finish()
    }

    private func finish() {
        progressObservation?.invalidate()
        progressObservation = nil
        sizeWatch?.cancel()
        sizeWatch = nil
        if let folder { try? FileManager.default.removeItem(at: folder) }
        folder = nil
        file = nil
        current = nil
        isBusy = false
    }
}
