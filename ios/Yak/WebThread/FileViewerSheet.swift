import SwiftUI
import WebKit
import YakKit

/// A shared file's page over the thread: a bar with Done, the file's name and a menu, and the page below it. The page
/// is on the instance's embed data store, so it is signed in like the thread page.
struct FileViewerSheet: View {
    let model: FileViewerModel
    let dataStore: WKWebsiteDataStore
    let version: String
    let isOffline: Bool
    /// The app is locked or covered: the page is hidden and the sheet shows nothing of it.
    let isCovered: Bool
    /// Closes the viewer and opens the thread of a link.
    let openThread: (String) -> Void
    @Environment(\.dismiss) private var dismiss
    @Environment(\.openURL) private var openURL
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var browserURL: BrowserLink?
    @State private var copiedLink = 0
    @State private var notice: String?

    var body: some View {
        NavigationStack {
            ZStack {
                FileViewerWebView(
                    model: model, dataStore: dataStore, version: version, isHidden: isCovered,
                    openLink: { browserURL = BrowserLink(url: $0) },
                    openThread: { id in
                        dismiss()
                        openThread(id)
                    }, notify: { notice = $0 }
                )
                .accessibilityHidden(isCovered || model.phase != .ready)
                ThreadLoadView(
                    presentation: model.presentation, retry: { model.retry() }, openThreads: {},
                    offlineBody: "viewer.offline.body", loadingText: "viewer.loading", slowBody: "viewer.slow.body"
                )
                .background(Color.yakBackground)
                .opacity(model.phase == .ready ? 0 : 1)
                .allowsHitTesting(model.phase != .ready)
                .accessibilityHidden(model.phase == .ready)
                VStack(spacing: Design.Space.compact) {
                    if let notice { FileLine(text: Text(verbatim: notice), identifier: "fileViewer.notice") }
                    if copiedLink > 0 { CopiedToast().id(copiedLink) }
                    Spacer()
                }
                .allowsHitTesting(false)
            }
            .background(Color.yakBackground)
            .motion(Motion.crossFade, value: model.phase == .ready)
            .navigationBarTitleDisplayMode(.inline)
            .toolbarBackground(Color.yakBackground, for: .navigationBar)
            .toolbarBackgroundVisibility(.visible, for: .navigationBar)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("common.done") { dismiss() }
                        .accessibilityIdentifier("fileViewer.done")
                }
                .sharedBackgroundVisibility(.hidden)
                ToolbarItem(placement: .principal) {
                    Text(model.title ?? String(localized: "viewer.title.fallback"))
                        .font(.yak(.headline)).lineLimit(1).truncationMode(.middle)
                        .accessibilityAddTraits(.isHeader).accessibilityIdentifier("fileViewer.title")
                }
                ToolbarItem(placement: .primaryAction) { menu }
                    .sharedBackgroundVisibility(.hidden)
            }
        }
        .presentationBackground(Color.yakBackground)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("fileViewer")
        .accessibilityHidden(isCovered)
        .sensoryFeedback(.success, trigger: copiedLink)
        .sheet(item: $browserURL) { BrowserSheet(url: $0.url).ignoresSafeArea() }
        .onChange(of: isOffline, initial: true) { model.connectivityChanged(isOnline: !isOffline) }
        .task(id: notice) {
            guard notice != nil else { return }
            do { try await Task.sleep(for: WebConversationModel.noticeLifetime) } catch { return }
            notice = nil
        }
        .onDisappear { model.close() }
    }

    private var menu: some View {
        Menu {
            Button {
                UIPasteboard.general.url = model.url
                copiedLink += 1
            } label: {
                Label("thread.copyLink", systemImage: "link")
            }
            Button {
                openURL(model.url)
            } label: {
                Label("viewer.openSafari", systemImage: "safari")
            }
        } label: {
            Image(systemName: "square.and.arrow.up").frame(
                minWidth: Design.Size.control, minHeight: Design.Size.control
            )
            .contentShape(.rect)
        }
        .accessibilityLabel("viewer.menu.label")
        .accessibilityIdentifier("fileViewer.menu")
    }
}
