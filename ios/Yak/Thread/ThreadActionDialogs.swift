import SwiftUI
import YakKit

/// The system sheet that asks before a close or a delete, and the alert for a delete the server could not finish.
/// Each screen that can start an action attaches it for its own `surface`, so one question shows once, where it was
/// asked. The destructive button is never the default: Cancel is.
private struct ThreadActionDialogs: ViewModifier {
    let actions: ThreadActionsModel
    let surface: ThreadActionSubject.Surface

    func body(content: Content) -> some View {
        content
            .confirmationDialog(
                Text(verbatim: shownConfirmation?.title ?? ""), isPresented: confirmationBinding,
                titleVisibility: .visible, presenting: shownConfirmation
            ) { confirmation in
                Button(confirmation.confirmLabel, role: confirmation.kind == .delete ? .destructive : nil) {
                    actions.confirm(confirmation)
                }
                .accessibilityIdentifier("thread.confirm.\(confirmation.kind == .delete ? "delete" : "close")")
                Button("common.cancel", role: .cancel) {}
            } message: { confirmation in
                Text(verbatim: confirmation.message)
            }
            .alert(
                "lifecycle.deleteFailed.title", isPresented: failureBinding, presenting: shownFailure
            ) { failure in
                Button("lifecycle.deleteFailed.ok", role: .cancel) {}
                Button("lifecycle.deleteFailed.retry") { actions.delete(failure.threadID, from: surface) }
            } message: { failure in
                Text(verbatim: failure.message)
            }
    }

    private var shownConfirmation: ThreadActionConfirmation? {
        actions.confirmation.flatMap { $0.surface == surface ? $0 : nil }
    }

    private var shownFailure: ThreadActionsModel.DeleteFailure? {
        actions.deleteFailure.flatMap { $0.surface == surface ? $0 : nil }
    }

    private var confirmationBinding: Binding<Bool> {
        Binding(get: { shownConfirmation != nil }, set: { if !$0 { actions.confirmation = nil } })
    }

    private var failureBinding: Binding<Bool> {
        Binding(get: { shownFailure != nil }, set: { if !$0 { actions.deleteFailure = nil } })
    }
}

extension View {
    func threadActionDialogs(_ actions: ThreadActionsModel, surface: ThreadActionSubject.Surface) -> some View {
        modifier(ThreadActionDialogs(actions: actions, surface: surface))
    }
}

/// A sentence about an action on the thread screen: why it did not happen. It leaves by itself.
struct ThreadActionBanner: View {
    let notice: ThreadActionNotice
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var isShown = false

    var body: some View {
        Group {
            if isShown {
                Text(verbatim: notice.text).font(.yak(.subheadline, .medium))
                    .multilineTextAlignment(.center)
                    .padding(.horizontal, Design.Space.medium).padding(.vertical, Design.Space.compact)
                    .background(.regularMaterial, in: .rect(cornerRadius: Design.Radius.card))
                    .padding(.horizontal, Design.Space.medium)
                    .transition(reduceMotion ? .opacity : .opacity.combined(with: .move(edge: .top)))
                    .accessibilityIdentifier("thread.actionNotice")
            }
        }
        .task {
            withMotion(Motion.panel, reduceMotion: reduceMotion) { isShown = true }
            UIAccessibility.post(notification: .announcement, argument: notice.text)
        }
    }
}

/// The drawer's toast: an inverted bar with a check mark for a success, the same bar with a warning mark for a
/// failure. It sits above the search field.
struct ThreadActionToast: View {
    let notice: ThreadActionNotice

    var body: some View {
        HStack(spacing: Design.Space.compact) {
            Image(systemName: notice.isFailure ? "exclamationmark.circle" : "checkmark").accessibilityHidden(true)
            Text(verbatim: notice.text).fixedSize(horizontal: false, vertical: true)
            Spacer(minLength: 0)
        }
        .font(.yak(.subheadline, .medium))
        .foregroundStyle(Color.yakAccentInk)
        .padding(.horizontal, Design.Space.medium).padding(.vertical, Design.Space.snug)
        .frame(maxWidth: .infinity, minHeight: Design.Size.control, alignment: .leading)
        .background(Color.yakText, in: .rect(cornerRadius: Design.Radius.card))
        .padding(.horizontal, Design.Space.medium).padding(.vertical, Design.Space.small)
        .accessibilityElement(children: .combine)
        .accessibilityIdentifier("drawer.toast")
        .task(id: notice.id) { UIAccessibility.post(notification: .announcement, argument: notice.text) }
    }
}
