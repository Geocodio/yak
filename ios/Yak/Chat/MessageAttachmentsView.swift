import QuickLook
import SwiftUI
import YakKit

/// The files of a message under its bubble: images as tiles, then files as rows, opened with Quick Look.
struct MessageAttachmentsView: View {
    let messageKey: String
    let rows: [MessageAttachmentRow]
    let hiddenCount: Int
    let thread: ThreadModel
    @State private var previewURL: URL?
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    private var tileSide: CGFloat { dynamicTypeSize.isAccessibilitySize ? 88 : 112 }
    private var images: [MessageAttachmentRow] { rows.filter { $0.kind == .image } }
    private var files: [MessageAttachmentRow] { rows.filter { $0.kind == .file } }

    var body: some View {
        VStack(alignment: .trailing, spacing: 6) {
            LazyVGrid(
                columns: [GridItem(.fixed(tileSide), spacing: 6), GridItem(.fixed(tileSide), spacing: 6)], spacing: 6
            ) {
                ForEach(images) { row in
                    AttachmentTile(row: row, side: tileSide, thread: thread, previewURL: $previewURL)
                        .accessibilityIdentifier("message.\(messageKey).attachment.\(row.id)")
                }
            }
            .frame(width: tileSide * 2 + 6)
            .frame(maxWidth: .infinity, alignment: .trailing)
            ForEach(files) { row in
                AttachmentFileRow(row: row, thread: thread, previewURL: $previewURL)
                    .accessibilityIdentifier("message.\(messageKey).attachment.\(row.id)")
            }
            if hiddenCount > 0 {
                Text("chat.attachments.more \(hiddenCount)").font(.footnote).foregroundStyle(Color.yakMuted)
            }
        }
        .padding(.top, 2)
        .quickLookPreview($previewURL)
    }
}

private enum TileState: Equatable {
    case loading
    case image(Data)
    case failed, offline, notAvailable
}

private struct AttachmentTile: View {
    let row: MessageAttachmentRow
    let side: CGFloat
    let thread: ThreadModel
    @Binding var previewURL: URL?
    @State private var state: TileState = .loading
    @State private var isSlowToLoad = false
    @State private var isOpening = false
    @State private var reloads = 0
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        Button {
            switch state {
            case .failed, .offline: reloads += 1
            case .image: open()
            default: break
            }
        } label: {
            ZStack(alignment: .bottomLeading) {
                content
                if let reference = row.reference {
                    Text(verbatim: reference).font(.caption2.weight(.medium)).padding(.horizontal, 8).padding(
                        .vertical, 3
                    )
                    .background(Color.yakBackground, in: .capsule).padding(6)
                }
            }
            .frame(width: side, height: side).clipShape(.rect(cornerRadius: 14))
            .overlay { if isOpening { ProgressView() } }
        }
        .buttonStyle(.plain)
        .disabled(state == .loading || isOpening)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(Text("chat.attachment.image.speech \(row.reference ?? "") \(row.title)"))
        .accessibilityValue(stateSpeech)
        .accessibilityHint(state == .notAvailable ? Text(verbatim: "") : Text("chat.attachment.hint"))
        .accessibilityRemoveTraits(state == .notAvailable ? .isButton : [])
        .task(id: LoadKey(link: row.openURL, reloads: reloads, isOffline: thread.isOffline)) { await load() }
    }

    /// What the picture shows when it is not the picture: spoken, because the visible text is hidden from VoiceOver.
    private var stateSpeech: Text {
        switch state {
        case .failed: Text("common.retry")
        case .offline: Text("chat.attachment.offline")
        default: Text(verbatim: "")
        }
    }

    @ViewBuilder private var content: some View {
        switch state {
        case .image(let data):
            if let image = UIImage(data: data) {
                Image(uiImage: image).resizable().scaledToFill().frame(width: side, height: side).clipped()
                    .transition(.opacity)
            } else {
                placeholder(symbol: "photo", text: nil)
            }
        case .loading:
            Color.yakFill.opacity(isSlowToLoad ? 1 : 0)
        case .failed: placeholder(symbol: "photo", text: "common.retry")
        case .offline: placeholder(symbol: "photo", text: "chat.attachment.offline")
        case .notAvailable: unavailable
        }
    }

    private func placeholder(symbol: String, text: LocalizedStringKey?) -> some View {
        VStack(spacing: 4) {
            Image(systemName: symbol).font(.title2).accessibilityHidden(true)
            if let text { Text(text).font(.caption) }
        }
        .foregroundStyle(Color.yakMuted).frame(width: side, height: side).background(Color.yakFill)
    }

    private var unavailable: some View {
        Text("chat.attachment.webOnly").font(.caption2).foregroundStyle(Color.yakMuted).padding(8)
            .frame(width: side, height: side).background(Color.yakFill)
    }

    private func load() async {
        isSlowToLoad = false
        if case .image = state { return }
        state = .loading
        let slow = Task {
            try? await Task.sleep(for: .milliseconds(300))
            if !Task.isCancelled { isSlowToLoad = true }
        }
        defer { slow.cancel() }
        let result = await thread.loadThumbnail(row, pixelSize: Int(side * 3))
        guard !Task.isCancelled else { return }
        switch result {
        case .image(let data): withAnimation(reduceMotion ? nil : .easeIn(duration: 0.2)) { state = .image(data) }
        case .failed: state = .failed
        case .offline: state = .offline
        case .notAvailable: state = .notAvailable
        case .expired:
            guard await thread.refreshShowForExpiredLinks() else {
                state = .failed
                return
            }
            // The refreshed row has a new link, which restarts this task; if none arrives, the tile shows Retry.
            try? await Task.sleep(for: .seconds(3))
            if !Task.isCancelled { state = .failed }
        }
    }

    private func open() {
        isOpening = true
        Task {
            defer { isOpening = false }
            if case .ready(let url) = await thread.loadFile(row) { previewURL = url } else { state = .failed }
        }
    }

    private struct LoadKey: Hashable {
        let link: URL?
        let reloads: Int
        let isOffline: Bool
    }
}

private struct AttachmentFileRow: View {
    let row: MessageAttachmentRow
    let thread: ThreadModel
    @Binding var previewURL: URL?
    @State private var isOpening = false
    @State private var failed = false

    var body: some View {
        Button {
            guard row.openURL != nil, !isOpening else { return }
            isOpening = true
            failed = false
            Task {
                defer { isOpening = false }
                switch await thread.loadFile(row) {
                case .ready(let url): previewURL = url
                case .expired:
                    _ = await thread.refreshShowForExpiredLinks()
                    failed = true
                default: failed = true
                }
            }
        } label: {
            VStack(alignment: .trailing, spacing: 4) {
                HStack(spacing: 10) {
                    Image(systemName: "doc").foregroundStyle(Color.yakMuted).accessibilityHidden(true)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: row.title).font(.body).lineLimit(1).truncationMode(.middle)
                        if row.openURL == nil {
                            Text("chat.attachment.webOnly").font(.footnote).foregroundStyle(Color.yakMuted)
                        } else {
                            Text(verbatim: row.detail).font(.footnote).foregroundStyle(Color.yakMuted)
                        }
                    }
                    Spacer(minLength: 0)
                    if isOpening {
                        ProgressView()
                    } else if row.openURL != nil {
                        Image(systemName: "chevron.right").font(.footnote).foregroundStyle(Color.yakMuted)
                            .accessibilityHidden(true)
                    }
                }
                if failed { Text("chat.attachment.failed").font(.footnote).foregroundStyle(Color.yakMuted) }
            }
            .padding(.horizontal, 14).padding(.vertical, 10).frame(minHeight: 52)
            .background(Color.yakCard, in: .rect(cornerRadius: Design.Radius.row))
        }
        .buttonStyle(.plain)
        .disabled(row.openURL == nil || isOpening)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(Text(verbatim: "\(row.title), \(row.detail)"))
        .accessibilityValue(failed ? Text("chat.attachment.failed") : Text(verbatim: ""))
        .accessibilityHint(Text("chat.attachment.hint"))
    }
}
