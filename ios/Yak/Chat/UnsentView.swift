import ImageIO
import SwiftUI
import YakKit

/// A message that is still on this device: its bubble with its files, and one line that says where it stands.
/// A refused one offers Edit, Retry and Remove.
struct UnsentView: View {
    let item: OutboxItem
    let isOffline: Bool
    let thread: ThreadModel

    var body: some View {
        VStack(alignment: .trailing, spacing: 6) {
            MessageBubble { Text(verbatim: String(item.text.prefix(20_000))).textSelection(.enabled) }
            if !item.attachments.isEmpty {
                VStack(alignment: .trailing, spacing: 6) {
                    ForEach(item.attachments, id: \.path) { attachment in
                        UnsentAttachment(attachment: attachment, thread: thread)
                    }
                }
            }
            stateLine
            if case .refused = item.state { actions }
        }
        .frame(maxWidth: .infinity, alignment: .trailing)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("unsent.\(item.id.uuidString.lowercased())")
    }

    @ViewBuilder private var stateLine: some View {
        HStack(alignment: .center, spacing: 6) {
            switch item.state {
            case .queued: Text("chat.unsent.queued")
            case .sending:
                ProgressView().controlSize(.mini).accessibilityHidden(true)
                Text("chat.unsent.sending")
            case .slow:
                ProgressView().controlSize(.mini).accessibilityHidden(true)
                Text("chat.unsent.slow")
            case .waiting: Text(isOffline ? "chat.unsent.offline" : "chat.unsent.trying")
            case .refused(let refusal):
                Image(systemName: "exclamationmark.triangle.fill").foregroundStyle(Color.yakDanger)
                    .accessibilityHidden(true)
                Text("chat.unsent.refused \(refusal.message)")
            }
        }
        .font(.caption).foregroundStyle(Color.yakMuted)
        .multilineTextAlignment(.trailing).fixedSize(horizontal: false, vertical: true)
        .accessibilityIdentifier("unsent.state")
    }

    private var actions: some View {
        ViewThatFits(in: .horizontal) {
            HStack(spacing: 8) { buttons }
            VStack(alignment: .trailing, spacing: 8) { buttons }
        }
        .buttonStyle(.bordered).tint(.primary).controlSize(.large)
    }

    @ViewBuilder private var buttons: some View {
        Button {
            thread.edit(item)
        } label: {
            Text("chat.message.edit")
        }
        .accessibilityIdentifier("unsent.edit")
        Button {
            Task { await thread.retryUnsent(item.id) }
        } label: {
            Text("common.retry")
        }
        .accessibilityIdentifier("unsent.retry")
        Button(role: .destructive) {
            Task { _ = await thread.removeUnsent(item.id) }
        } label: {
            Text("chat.message.remove")
        }
        .accessibilityIdentifier("unsent.remove")
    }
}

/// One file of an unsent message: an image as a thumbnail, anything else as a chip with its name.
private struct UnsentAttachment: View {
    let attachment: OutboxAttachment
    let thread: ThreadModel
    @State private var thumbnail: UIImage?
    private var isImage: Bool { attachment.contentType.hasPrefix("image/") }

    var body: some View {
        Group {
            if isImage {
                Group {
                    if let thumbnail {
                        Image(uiImage: thumbnail).resizable().scaledToFill()
                    } else {
                        Color(.tertiarySystemFill)
                    }
                }
                .frame(width: 88, height: 88).clipShape(.rect(cornerRadius: 12))
                .accessibilityLabel(Text("chat.attachment.image \(attachment.fileName)"))
                .task { await load() }
            } else {
                AttachmentChip(title: attachment.fileName, detail: attachment.reference, isImage: false)
            }
        }
    }

    private func load() async {
        guard thumbnail == nil, let data = await thread.attachmentData(attachment) else { return }
        thumbnail = await Task.detached { Self.downsample(data, maximumPixels: 264) }.value
    }

    private nonisolated static func downsample(_ data: Data, maximumPixels: Int) -> UIImage? {
        guard
            let source = CGImageSourceCreateWithData(data as CFData, [kCGImageSourceShouldCache: false] as CFDictionary)
        else { return nil }
        let options: [CFString: Any] = [
            kCGImageSourceCreateThumbnailFromImageAlways: true, kCGImageSourceCreateThumbnailWithTransform: true,
            kCGImageSourceShouldCacheImmediately: true, kCGImageSourceThumbnailMaxPixelSize: maximumPixels,
        ]
        return CGImageSourceCreateThumbnailAtIndex(source, 0, options as CFDictionary).map { UIImage(cgImage: $0) }
    }
}
