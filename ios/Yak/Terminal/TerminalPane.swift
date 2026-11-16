import SwiftUI
import UIKit
import YakKit

extension PaneDescriptor {
    static let terminal = PaneDescriptor(
        id: .terminal, name: "pane.terminal", symbol: "terminal",
        content: { thread in AnyView(TerminalPane(thread: thread).id(thread.threadID)) },
        barRow: { _ in AnyView(EmptyView()) })
}

/// The Terminal pane: the focused shell's output, the banner over it, the full-pane states and the key row, which it
/// hands to the bottom bar.
struct TerminalPane: View {
    let thread: ThreadModel

    @State private var model: TerminalPaneModel
    @State private var keyboard = HardwareKeyboardMonitor()
    @Environment(\.scenePhase) private var scenePhase
    @Environment(\.isNetworkOnline) private var isOnline
    @Environment(\.isPaneShown) private var isShown

    init(thread: ThreadModel) {
        self.thread = thread
        _model = State(
            initialValue: TerminalPaneModel(
                thread: thread, timings: .forThisRun, connector: URLSessionTerminalSocketConnector()))
    }

    private var factsKey: String {
        guard let facts = thread.snapshot?.show?.thread else { return "" }
        return "\(facts.status)|\(facts.blockedReason ?? "")"
    }

    var body: some View {
        ZStack(alignment: .bottom) {
            if showsFullPaneState {
                TerminalStateView(model: model)
            } else if let shell = model.focusedShell {
                TerminalSurfaceView(surface: shell.surface)
                    .id(model.focusedID)
                    .simultaneousGesture(TapGesture().onEnded { model.isSurfaceFocused = true })
                    .accessibilityElement(children: .contain)
                if let snapshot = model.focusedSnapshot {
                    VStack(spacing: 8) {
                        if model.isScrolledUp { jumpButton }
                        TerminalBanner(model: model, snapshot: snapshot)
                    }
                }
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color.terminalBackground, ignoresSafeAreaEdges: [])
        .paneBarRow(key: "terminal") { TerminalKeyRow(model: model, keyboard: keyboard) }
        .paneMenuItems(key: "terminal") {
            Button {
                Task { await model.newShell() }
            } label: {
                Label("terminal.shell.new", systemImage: "plus")
            }
            .yakShortcut(.newShell).disabled(!model.shells.canAdd)
        }
        .task(id: thread.snapshot?.show != nil) { await model.start() }
        .onChange(of: factsKey) { Task { await model.threadFactsChanged() } }
        .onChange(of: scenePhase) { _, phase in Task { await model.scenePhaseChanged(phase) } }
        .onChange(of: isOnline, initial: true) { _, online in Task { await model.networkChanged(isOnline: online) } }
        .onReceive(NotificationCenter.default.publisher(for: UIResponder.keyboardWillShowNotification)) { _ in
            model.keyboardChanged(isShown: true)
        }
        .onReceive(NotificationCenter.default.publisher(for: UIResponder.keyboardWillHideNotification)) { _ in
            model.keyboardChanged(isShown: false)
        }
        .onDisappear { Task { await model.stop() } }
        .background { closeShortcut }
        .confirmationDialog(
            "terminal.banner.showShells", isPresented: $model.isShowingShells, titleVisibility: .visible
        ) {
            ForEach(model.shells.shells) { entry in
                Button(role: .destructive) {
                    Task { await model.closeShell(entry.id) }
                } label: {
                    Text("terminal.shells.close \(entry.title)")
                }
            }
        }
        .confirmationDialog(
            Text("terminal.shells.confirm \(model.closingShell?.title ?? "")"),
            isPresented: Binding(get: { model.closingShell != nil }, set: { if !$0 { model.closingShell = nil } }),
            titleVisibility: .visible
        ) {
            Button("terminal.shells.closeShell", role: .destructive) {
                if let closing = model.closingShell { Task { await model.closeShell(closing.id) } }
                model.closingShell = nil
            }
        } message: {
            Text("terminal.shells.confirmDetail")
        }
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("terminal")
    }

    /// The full-pane states cover a shell that has not shown output yet; once it has, the banner takes over.
    private var showsFullPaneState: Bool {
        guard !model.hasConnectedOnce else { return false }
        guard model.hasFacts, let snapshot = model.focusedSnapshot, model.focusedShell != nil || !model.canDrive
        else { return true }
        switch snapshot.phase {
        case .idle, .opening, .waitingForSlot, .waitedTooLong, .restoring, .stillRestoring, .refused, .failed:
            return true
        default: return false
        }
    }

    private var jumpButton: some View {
        Button {
            model.jumpToEnd()
        } label: {
            Label("terminal.jump", systemImage: "arrow.down.to.line").font(.subheadline.weight(.semibold))
        }
        .buttonStyle(TerminalActionStyle())
        .accessibilityIdentifier("terminal.jump")
    }

    /// Command W closes the focused shell; it is only live while the pane is shown.
    @ViewBuilder private var closeShortcut: some View {
        if isShown, let entry = model.shells.shells.first(where: { $0.id == model.focusedID }) {
            Button("terminal.shells.closeShell") { model.requestClose(entry) }
                .yakShortcut(.closeShell).frame(width: 0, height: 0).opacity(0).accessibilityHidden(true)
        }
    }
}
