import SwiftUI
import YakKit

/// The files of the draft above the field: a thumbnail or a file chip, its reference and a remove button.
struct AttachmentStrip: View {
    let composer: ComposerModel

    var body: some View {
        if !composer.attachments.isEmpty || composer.preparingCount > 0 {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 10) {
                    ForEach(composer.attachments, id: \.path) { attachment in
                        AttachmentTile(attachment: attachment, thumbnail: composer.thumbnails[attachment.reference]) {
                            Task { await composer.remove(attachment) }
                        }
                    }
                    ForEach(0..<composer.preparingCount, id: \.self) { _ in PreparingTile() }
                }
                .padding(.horizontal, 12).padding(.top, 14).padding(.bottom, 8)
            }
            .accessibilityIdentifier("composer.attachments")
            .transition(.opacity.combined(with: .scale(scale: 0.96, anchor: .bottom)))
        }
    }
}

private struct AttachmentTile: View {
    let attachment: OutboxAttachment
    let thumbnail: Data?
    let remove: () -> Void
    @ScaledMetric private var scaledSide = 64.0
    /// The tile grows with the text but stops at a size the bar can hold.
    private var side: Double { min(scaledSide, 96) }

    var body: some View {
        VStack(spacing: 4) {
            ZStack(alignment: .topTrailing) {
                content.frame(width: side, height: side).clipShape(.rect(cornerRadius: 12))
                Button(action: remove) {
                    Image(systemName: "xmark.circle.fill").font(.system(size: 20)).symbolRenderingMode(.palette)
                        .foregroundStyle(Color(.systemBackground), Color.primary)
                        .frame(width: 44, height: 44, alignment: .topTrailing).contentShape(.rect)
                }
                .buttonStyle(.plain).offset(x: 12, y: -12)
                .accessibilityLabel(Text("attachment.remove \(attachment.reference)"))
                .accessibilityIdentifier("composer.attachment.remove")
            }
            Text(attachment.reference).font(.caption2).foregroundStyle(.primary).lineLimit(1).minimumScaleFactor(0.6)
                .dynamicTypeSize(...DynamicTypeSize.accessibility2).frame(maxWidth: side)
        }
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("composer.attachment")
    }

    @ViewBuilder private var content: some View {
        if let thumbnail, let image = UIImage(data: thumbnail) {
            Image(uiImage: image).resizable().scaledToFill()
                .accessibilityLabel(Text(verbatim: attachment.reference))
        } else {
            VStack(spacing: 4) {
                Image(systemName: "doc").font(.title3)
                Text(attachment.fileName).font(.caption2).lineLimit(2).multilineTextAlignment(.center)
            }
            .padding(4).frame(maxWidth: .infinity, maxHeight: .infinity).background(Color.primary.opacity(0.08))
            .accessibilityElement(children: .combine)
        }
    }
}

private struct PreparingTile: View {
    @ScaledMetric private var scaledSide = 64.0
    private var side: Double { min(scaledSide, 96) }

    var body: some View {
        ProgressView().frame(width: side, height: side).background(
            Color.primary.opacity(0.08), in: .rect(cornerRadius: 12)
        )
        .accessibilityLabel("attachment.preparing")
    }
}
