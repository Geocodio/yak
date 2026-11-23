import SwiftUI
import YakKit

/// The Changed mode of the Files pane: one file per screen, paged sideways, with the banners and every load state.
struct ChangedFilesView: FilesModeView {
    static let mode = FilesMode.changed

    let thread: ThreadModel
    @Environment(\.isPaneShown) private var isPaneShown
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @Environment(\.horizontalSizeClass) private var sizeClass
    @State private var isShowingWeb = false
    @State private var movedNotice: String?
    @SceneStorage("yak.files") private var storedPositions: Data?

    init(thread: ThreadModel, scene: SceneModel) {
        self.thread = thread
    }

    private var model: FilesModel { thread.files }

    var body: some View {
        content
            .task {
                model.restorePositions(storedPositions)
                await model.start()
            }
            .onChange(of: model.positionsData) { _, data in storedPositions = data }
            .task(id: thread.snapshot?.lastDiffUpdateSequence) {
                if let snapshot = thread.snapshot { await model.threadChanged(snapshot) }
            }
            .task(id: thread.snapshot?.turn?.state.isActive) {
                if let snapshot = thread.snapshot { await model.threadChanged(snapshot) }
            }
            .onChange(of: isPaneShown) { _, shown in Task { await model.setVisible(shown) } }
            .onChange(of: model.snapshot?.lastMove) { _, move in announce(move) }
            .sheet(isPresented: $isShowingWeb) {
                if let url = webURL { BrowserSheet(url: url) }
            }
            .accessibilityElement(children: .contain)
            .accessibilityIdentifier("files.changed")
    }

    private var webURL: URL? {
        HandoffLink.webURL(instance: thread.session.instance.url, threadID: thread.threadID)
    }

    // MARK: States

    @ViewBuilder private var content: some View {
        if let snapshot = model.snapshot {
            switch snapshot.load {
            case .loading:
                RowSkeleton(rowCount: 6, identifier: "files.loading")
            case .slow:
                StateMessage(
                    title: "list.slow", systemImage: "hourglass", identifier: "files.slow",
                    actions: { RetryButton(identifier: "files.retry") { Task { await model.retry() } } })
            case .failed(let problem):
                StateMessage(
                    title: ProblemCopy.message(problem, offline: "files.error.offline"), detail: nil,
                    systemImage: "exclamationmark.triangle", identifier: "files.error",
                    actions: { RetryButton(identifier: "files.retry") { Task { await model.retry() } } })
            case .ready:
                if snapshot.pager.files.isEmpty {
                    StateMessage(
                        title: "files.empty.title", detail: "files.empty.detail",
                        systemImage: "doc.text.magnifyingglass",
                        identifier: "files.empty")
                } else {
                    VStack(spacing: 0) {
                        notices(snapshot)
                        pages(snapshot)
                    }
                }
            }
        } else {
            RowSkeleton(rowCount: 6, identifier: "files.loading")
        }
    }

    @ViewBuilder private func notices(_ snapshot: ChangesSnapshot) -> some View {
        if snapshot.isOffline || snapshot.isShowingCachedCopy {
            NoticeLine(text: "list.offlineSaved", systemImage: "wifi.slash", identifier: "files.offlineNotice")
        } else if let problem = snapshot.problem {
            HStack {
                ProblemCopy.message(problem, offline: "files.error.offline").font(.yak(.footnote, .medium))
                Spacer(minLength: 8)
                Button("common.retry") { Task { await model.retry() } }.font(.yak(.footnote, .semibold))
                    .frame(minHeight: 44)
            }
            .padding(.horizontal, 20).background(Color.primary.opacity(0.05)).accessibilityIdentifier("files.problem")
        }
        if let banner = banner(snapshot) {
            Text(banner).font(sizeClass == .regular ? .yak(.subheadline, .medium) : .yak(.footnote, .medium))
                .foregroundStyle(sizeClass == .regular ? Color.primary : Color.yakMuted)
                .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 20).padding(.vertical, 8)
                .background(Color.yakCard).fixedSize(horizontal: false, vertical: true)
                .id(dynamicTypeSize).accessibilityIdentifier("files.banner")
        }
    }

    private func banner(_ snapshot: ChangesSnapshot) -> String? {
        if let movedNotice { return movedNotice }
        let isWorking = snapshot.diff?.isTurnRunning == true || thread.snapshot?.turn?.state.isActive == true
        if isWorking { return String(localized: "files.banner.working") }
        if snapshot.diff?.isTruncated == true { return String(localized: "files.banner.truncated") }
        return nil
    }

    /// Says once that the file the person was reading left the changes.
    private func announce(_ move: DiffPager.Move?) {
        guard case .movedBecauseGone(let previous)? = move else { return }
        movedNotice = String(localized: "files.banner.gone \((previous.path as NSString).lastPathComponent)")
        Task {
            do { try await Task.sleep(for: .seconds(4)) } catch { return }
            movedNotice = nil
        }
    }

    // MARK: Pages

    private func canEdit(_ file: DiffFileSummary, state: FilePageState?) -> Bool {
        FilePageView.canEdit(
            file: file, state: state, canDrive: thread.snapshot?.show?.canDrive ?? false,
            filesAvailable: FilesMode.built.contains(.everything) && thread.panes.contains(.files),
            primaryRepo: thread.snapshot?.show?.thread.shownRepo)
    }

    private func pages(_ snapshot: ChangesSnapshot) -> some View {
        let position = Binding<DiffFileKey?>(
            get: { snapshot.pager.current },
            set: { key in
                guard let key, key != model.currentKey else { return }
                Task { await model.show(key) }
            })
        return ScrollView(.horizontal) {
            LazyHStack(spacing: 0) {
                ForEach(snapshot.pager.files, id: \.key) { file in
                    FilePageView(
                        file: file, state: snapshot.pages[file.key],
                        isThrowaway: snapshot.diff?.isThrowaway(file) == true,
                        isViewed: snapshot.marks.isViewed(file),
                        toggleViewed: { Task { await model.toggleViewed() } },
                        retry: { Task { await model.retry() } }, openWeb: { isShowingWeb = true },
                        edit: canEdit(file, state: snapshot.pages[file.key])
                            ? { thread.fileOpenRequest = FileOpenRequest(path: file.path, edit: true) } : nil
                    )
                    .containerRelativeFrame(.horizontal)
                    .id(file.key)
                }
            }
            .scrollTargetLayout()
        }
        .scrollTargetBehavior(.paging)
        .scrollPosition(id: position)
        .scrollIndicators(.hidden)
    }
}
