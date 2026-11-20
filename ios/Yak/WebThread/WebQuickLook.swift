import QuickLook
import UIKit
import WebKit
import YakKit

#if canImport(os)
    import os
#endif

/// Shows one same-origin file of the page in Quick Look: it is downloaded through the web view's own session into a
/// temporary folder, within `QuickLookFile.maximumBytes`, and deleted when the preview closes.
@MainActor
final class WebQuickLook: NSObject, WKDownloadDelegate, QLPreviewControllerDataSource,
    @preconcurrency QLPreviewControllerDelegate
{
    private let policy: EmbedNavigationPolicy
    private let present: @MainActor (UIViewController) -> Void
    private var folder: URL?
    private var file: URL?
    private var isBusy = false
    private var progressObservation: NSKeyValueObservation?

    static var root: URL {
        FileManager.default.temporaryDirectory.appending(path: "YakQuickLook", directoryHint: .isDirectory)
    }

    init(
        policy: EmbedNavigationPolicy,
        present: @escaping @MainActor (UIViewController) -> Void = { controller in
            PreviewWebCoordinator.topViewController()?.present(controller, animated: true)
        }
    ) {
        self.policy = policy
        self.present = present
        super.init()
        // A file left by a run that ended while its preview was open.
        try? FileManager.default.removeItem(at: Self.root)
    }

    /// Starts the download; one at a time, and only for an address on the instance's own origin.
    func start(url: URL, name: String, in webView: WKWebView) {
        guard !isBusy, policy.isInstanceOrigin(url) else { return }
        isBusy = true
        let target = Self.root.appending(path: UUID().uuidString, directoryHint: .isDirectory)
            .appending(path: QuickLookFile.fileName(name), directoryHint: .notDirectory)
        file = target
        folder = target.deletingLastPathComponent()
        Task {
            let download = await webView.startDownload(using: URLRequest(url: url))
            download.delegate = self
            progressObservation = download.progress.observe(\.completedUnitCount) { [weak self] progress, _ in
                let received = progress.completedUnitCount
                Task { @MainActor in
                    // A body that outgrows the cap is cancelled and its partial file removed.
                    guard QuickLookFile.mustCancel(receivedBytes: received) else { return }
                    download.cancel { _ in }
                    self?.finish()
                }
            }
        }
    }

    // MARK: Download

    func download(
        _ download: WKDownload, decideDestinationUsing response: URLResponse, suggestedFilename: String,
        completionHandler: @escaping @MainActor (URL?) -> Void
    ) {
        let status = (response as? HTTPURLResponse)?.statusCode ?? 200
        guard (200..<300).contains(status), QuickLookFile.admits(expectedLength: response.expectedContentLength),
            let file, let folder
        else { return completionHandler(nil) }
        do {
            try FileManager.default.createDirectory(
                at: folder, withIntermediateDirectories: true, attributes: [.protectionKey: FileProtectionType.complete]
            )
            completionHandler(file)
        } catch {
            completionHandler(nil)
        }
    }

    func download(
        _ download: WKDownload, willPerformHTTPRedirection response: HTTPURLResponse, newRequest request: URLRequest,
        decisionHandler: @escaping @MainActor (WKDownload.RedirectPolicy) -> Void
    ) {
        decisionHandler(request.url.map(policy.isInstanceOrigin) == true ? .allow : .cancel)
    }

    func downloadDidFinish(_ download: WKDownload) {
        guard let file, let size = (try? FileManager.default.attributesOfItem(atPath: file.path))?[.size] as? Int64,
            QuickLookFile.isWithinLimit(bytes: size)
        else { return finish() }
        let controller = QLPreviewController()
        controller.dataSource = self
        controller.delegate = self
        present(controller)
    }

    func download(_ download: WKDownload, didFailWithError error: any Error, resumeData: Data?) {
        #if canImport(os)
            Logger(subsystem: "Yak", category: "WebQuickLook").error("A preview download failed")
        #endif
        finish()
    }

    // MARK: Preview

    func numberOfPreviewItems(in controller: QLPreviewController) -> Int { file == nil ? 0 : 1 }

    func previewController(_ controller: QLPreviewController, previewItemAt index: Int) -> any QLPreviewItem {
        (file ?? Self.root) as NSURL
    }

    func previewControllerDidDismiss(_ controller: QLPreviewController) { finish() }

    private func finish() {
        progressObservation?.invalidate()
        progressObservation = nil
        if let folder { try? FileManager.default.removeItem(at: folder) }
        folder = nil
        file = nil
        isBusy = false
    }
}
