import SwiftUI
import YakKit

/// The line under an unsent message that is uploading or whose background upload failed.
struct UploadLine: View {
    let state: UploadLineState
    let itemID: UUID
    let thread: ThreadModel
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    private var key: String { itemID.uuidString.lowercased() }

    var body: some View {
        VStack(alignment: .trailing, spacing: 6) {
            switch state {
            case .uploading(let percent): progress(percent: percent, isSlow: false)
            case .stalled(let percent): progress(percent: percent, isSlow: true)
            case .waitingForNetwork:
                Text("chat.upload.waiting").font(.caption).foregroundStyle(Color.yakMuted)
                    .multilineTextAlignment(.trailing)
            case .failedInBackground: failed
            }
        }
        .frame(maxWidth: .infinity, alignment: .trailing)
        .accessibilityIdentifier("unsent.\(key).upload")
    }

    private func progress(percent: Int, isSlow: Bool) -> some View {
        HStack(spacing: 8) {
            Capsule().fill(Color.yakFill).frame(width: 160, height: 3)
                .overlay(alignment: .leading) {
                    Capsule().fill(Color.yakMuted).frame(width: 160 * Double(percent) / 100, height: 3)
                        .animation(reduceMotion ? nil : .linear(duration: 0.25), value: percent)
                }
                .accessibilityHidden(true)
            Group {
                if isSlow {
                    Text("chat.upload.slow \(percent)")
                } else {
                    Text("chat.upload.progress \(percent)")
                }
            }
            .font(.caption.monospacedDigit()).foregroundStyle(Color.yakMuted)
            .multilineTextAlignment(.trailing).fixedSize(horizontal: false, vertical: true)
        }
        .accessibilityRepresentation { Text("chat.upload.progress.speech \(percent)") }
    }

    private var failed: some View {
        VStack(alignment: .trailing, spacing: 6) {
            HStack(spacing: 6) {
                Image(systemName: "exclamationmark.triangle.fill").foregroundStyle(Color.yakDanger)
                    .accessibilityHidden(true)
                Text("chat.upload.failed")
            }
            .font(.caption).foregroundStyle(Color.yakMuted).multilineTextAlignment(.trailing)
            .fixedSize(horizontal: false, vertical: true)
            ViewThatFits(in: .horizontal) {
                HStack(spacing: 8) { buttons }
                VStack(alignment: .trailing, spacing: 8) { buttons }
            }
            .buttonStyle(.bordered).tint(.primary).controlSize(.large)
        }
    }

    @ViewBuilder private var buttons: some View {
        Button {
            Task { await thread.retryUnsent(itemID) }
        } label: {
            Text("common.retry")
        }
        .accessibilityIdentifier("unsent.\(key).retry")
        Button(role: .destructive) {
            Task { _ = await thread.removeUnsent(itemID) }
        } label: {
            Text("chat.message.remove")
        }
        .accessibilityIdentifier("unsent.\(key).remove")
    }
}
