import SwiftUI
import UIKit

/// The Preview items of the thread menu: Open in Safari and Copy address.
struct PreviewMenuItems: View {
    let model: PreviewModel

    var body: some View {
        Button {
            Task { await model.perform(.openInBrowser) }
        } label: {
            Label("preview.action.openInSafari", systemImage: "safari")
        }
        .disabled(model.snapshot.lastPreviewURL == nil)
        .accessibilityIdentifier("preview.openInSafari")
        Button {
            if let url = model.copyableAddress() {
                UIPasteboard.general.url = url
                model.show(line: String(localized: "preview.line.copied"))
            }
        } label: {
            Label("preview.copyAddress", systemImage: "doc.on.doc")
        }
        .disabled(model.page != .loaded)
        .accessibilityIdentifier("preview.copyAddress")
        Divider()
    }
}
