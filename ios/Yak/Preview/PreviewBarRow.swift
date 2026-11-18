import SwiftUI
import UIKit
import YakKit

/// The preview's row in the bottom bar: a pill with the lock, the path and reload. Tapping the path edits it.
struct PreviewBarRow: View {
    let model: PreviewModel
    let axis: Axis

    @State private var isEditing = false
    @State private var draft = ""
    @FocusState private var isFocused: Bool
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    private var canEdit: Bool { model.card?.kind == .page }
    private var isLoading: Bool {
        switch model.page {
        case .loading, .slow, .signingIn: true
        default: false
        }
    }

    var body: some View {
        if axis == .vertical { strip } else { row }
    }

    /// The trailing strip of a short window: reload, then the lock that opens the address in a popover.
    private var strip: some View {
        VStack(spacing: 4) {
            reloadButton
            Button {
                guard canEdit else { return }
                draft = model.address
                isEditing = true
            } label: {
                Image(systemName: model.isSecure ? "lock.fill" : "lock.open")
                    .foregroundStyle(model.isSecure ? Color.green : Color.yakMuted)
                    .frame(minWidth: 44, minHeight: 44).contentShape(.rect)
            }
            .opacity(canEdit ? 1 : 0.55)
            .accessibilityLabel(Text("preview.address.label \(model.address)"))
            .accessibilityValue(Text(model.isSecure ? "preview.secure" : "preview.testConnection"))
            .accessibilityIdentifier("preview.address")
            .popover(isPresented: $isEditing) {
                field.padding(12).frame(minWidth: 280)
                    .presentationCompactAdaptation(.popover)
                    .onAppear { isFocused = true }
            }
        }
        .background { reloadShortcut }
        .accessibilityElement(children: .contain)
    }

    private var row: some View {
        VStack(spacing: 2) {
            HStack(spacing: 4) {
                if isEditing { field } else { addressButton }
                reloadButton
            }
            .padding(.horizontal, 12)
            .background(Color.yakCard, in: .capsule)
            progress
        }
        .background { reloadShortcut }
        .accessibilityElement(children: .contain)
    }

    private var addressButton: some View {
        Button {
            guard canEdit else { return }
            draft = model.address
            isEditing = true
            isFocused = true
            selectAllSoon()
        } label: {
            HStack(spacing: 8) {
                Image(systemName: model.isSecure ? "lock.fill" : "lock.open")
                    .font(.footnote).foregroundStyle(model.isSecure ? Color.green : Color.yakMuted)
                    .accessibilityHidden(true)
                Text(model.address).font(.system(.subheadline, design: .monospaced).weight(.medium)).lineLimit(1)
                    .foregroundStyle(canEdit ? Color.primary : Color.yakMuted).truncationMode(.middle)
                Spacer(minLength: 0)
            }
            .frame(minHeight: 44).contentShape(.rect)
        }
        .buttonStyle(.plain)
        .accessibilityLabel(Text("preview.address.label \(model.address)"))
        .accessibilityValue(Text(model.isSecure ? "preview.secure" : "preview.testConnection"))
        .accessibilityIdentifier("preview.address")
    }

    /// The path is selected when editing starts, so typing replaces it.
    private func selectAllSoon() {
        Task {
            do { try await Task.sleep(for: .milliseconds(150)) } catch { return }
            UIApplication.shared.sendAction(#selector(UIResponder.selectAll(_:)), to: nil, from: nil, for: nil)
        }
    }

    private var field: some View {
        HStack(spacing: 4) {
            TextField("preview.address.placeholder", text: $draft)
                .font(.system(.body, design: .monospaced))
                .keyboardType(.URL).textInputAutocapitalization(.never).autocorrectionDisabled()
                .submitLabel(.go).focused($isFocused).frame(minHeight: 44)
                .onSubmit {
                    let typed = draft
                    isEditing = false
                    Task { await model.go(to: typed) }
                }
                .accessibilityIdentifier("preview.address.field")
            Button {
                isEditing = false
            } label: {
                Image(systemName: "xmark.circle.fill").foregroundStyle(Color.yakMuted)
                    .frame(minWidth: 44, minHeight: 44)
            }
            .accessibilityLabel("preview.address.cancel")
            .accessibilityIdentifier("preview.address.cancel")
        }
        .background {
            Button("preview.address.cancel") { isEditing = false }.yakShortcut(.cancel)
                .frame(width: 0, height: 0).opacity(0).accessibilityHidden(true)
        }
    }

    private var reloadButton: some View {
        Button {
            if isLoading { model.stopLoading() } else { Task { await model.reload() } }
        } label: {
            Image(systemName: isLoading ? "xmark" : "arrow.clockwise").frame(minWidth: 44, minHeight: 44)
                .contentShape(.rect)
        }
        .disabled(!canEdit)
        .accessibilityLabel(isLoading ? Text("preview.stop") : Text("preview.reload"))
        .accessibilityIdentifier(isLoading ? "preview.stop" : "preview.reload")
    }

    @ViewBuilder private var progress: some View {
        if case .loading(let value) = model.page {
            ProgressView(value: min(max(value, 0.05), 1)).progressViewStyle(.linear).tint(.primary)
                .frame(height: 2).padding(.horizontal, 20)
                .animation(reduceMotion ? nil : .default, value: value)
                .accessibilityHidden(true)
        }
    }

    /// Command R reloads.
    private var reloadShortcut: some View {
        Button("shortcut.reloadPreview") { Task { await model.reload() } }.yakShortcut(.reloadPreview)
            .frame(width: 0, height: 0).opacity(0).accessibilityHidden(true)
    }
}
