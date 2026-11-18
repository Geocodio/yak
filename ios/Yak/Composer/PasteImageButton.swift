import SwiftUI
import UIKit

/// The system paste control, shown above the field while the pasteboard holds an image. The system control never
/// asks for the paste permission; the pasteboard's contents are not read until the person taps it.
struct PasteImageButton: View {
    let composer: ComposerModel
    @State private var hasImage = UIPasteboard.general.hasImages

    var body: some View {
        Group {
            if hasImage && composer.canPaste {
                PasteButton(supportedContentTypes: [.image]) { providers in
                    Task { await composer.paste(providers) }
                }
                .buttonBorderShape(.capsule).labelStyle(.titleOnly).tint(.primary)
                .frame(minHeight: 44, alignment: .leading)
                .accessibilityLabel(Text("composer.paste"))
                .accessibilityIdentifier("composer.pasteImage")
            }
        }
        .onReceive(NotificationCenter.default.publisher(for: UIPasteboard.changedNotification)) { _ in
            hasImage = UIPasteboard.general.hasImages
        }
        .onReceive(NotificationCenter.default.publisher(for: UIApplication.didBecomeActiveNotification)) { _ in
            hasImage = UIPasteboard.general.hasImages
        }
    }
}
