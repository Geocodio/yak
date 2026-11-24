import SwiftUI
import YakKit

/// The unsent messages with files whose background upload is going out, waiting for the network or failed, above the
/// composer. The page lists the same messages without a progress value. A failed upload says so and offers Retry; it
/// also goes again by itself later.
struct UploadChipsView: View {
    let thread: ThreadModel
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        let chips = thread.uploadChips
        if !chips.isEmpty {
            VStack(spacing: 0) {
                ForEach(chips) { chip in
                    if chip.id != chips.first?.id { Divider().overlay(Color.yakBorderStrong) }
                    row(chip)
                }
                Rectangle().fill(Color.yakBorderStrong).frame(height: Design.Space.line)
            }
            .background(Color.yakBubble)
            .accessibilityElement(children: .contain)
            .accessibilityIdentifier("upload.list")
        }
    }

    private func row(_ chip: UploadChip) -> some View {
        VStack(alignment: .leading, spacing: Design.Space.nudge) {
            HStack(alignment: .center, spacing: Design.Space.compact) {
                Text(verbatim: chip.text).font(.yak(.footnote)).foregroundStyle(.primary).lineLimit(2)
                    .fixedSize(horizontal: false, vertical: true).id(dynamicTypeSize)
                    .frame(maxWidth: .infinity, minHeight: Design.Size.control, alignment: .leading)
                trailing(chip)
            }
            line(chip.state)
        }
        .padding(.leading, Design.Space.medium).padding(.trailing, Design.Space.tight)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("upload.row.\(chip.id.uuidString.lowercased())")
    }

    @ViewBuilder private func line(_ state: UploadChip.State) -> some View {
        switch state {
        case .uploading(let percent):
            VStack(alignment: .leading, spacing: Design.Space.nudge) {
                ProgressView(value: Double(percent), total: 100).tint(.primary)
                    .accessibilityHidden(true)
                Text("chat.upload.progress \(percent)").font(.yak(.caption, .medium))
                    .foregroundStyle(Color.yakMuted).fixedSize(horizontal: false, vertical: true)
                    .accessibilityIdentifier("upload.progress")
            }
        case .waitingForNetwork:
            Text("chat.upload.waiting").font(.yak(.caption, .medium)).foregroundStyle(Color.yakMuted)
                .fixedSize(horizontal: false, vertical: true).accessibilityIdentifier("upload.waiting")
        case .failed:
            Text("chat.upload.failed").font(.yak(.caption, .medium)).foregroundStyle(Color.yakDanger)
                .fixedSize(horizontal: false, vertical: true).accessibilityIdentifier("upload.failed")
        }
    }

    @ViewBuilder private func trailing(_ chip: UploadChip) -> some View {
        if chip.state == .failed {
            Button("common.retry") { Task { await thread.retryUnsent(chip.id) } }
                .buttonStyle(.bordered).tint(.primary).controlSize(.small)
                .frame(minHeight: Design.Size.control)
                .accessibilityIdentifier("upload.retry")
        }
    }
}
