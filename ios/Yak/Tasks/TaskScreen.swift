import SwiftUI
import YakKit

/// The task screen, board "Task with a co-owner decision": status, title, the conversation, the pull request,
/// and at the bottom the decision.
struct TaskScreen: View {
    let model: TaskModel
    let instanceURL: URL
    let openWeb: (URL) -> Void
    /// Leaves the screen for the list behind it.
    let back: () -> Void
    /// Leaves the task screens for the open thread.
    let didOpenThread: () -> Void

    @State private var isRequestingChanges = false
    @State private var isTagging = false
    @State private var isConfirmingCancel = false
    @Environment(\.walkthroughPlayback) private var playback
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @Environment(\.horizontalSizeClass) private var sizeClass

    var body: some View {
        content
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(Color(.systemBackground))
            .navigationBarTitleDisplayMode(.inline)
            .navigationTitle("")
            .toolbar { if model.task != nil { ToolbarItem(placement: .topBarTrailing) { menu } } }
            .safeAreaInset(edge: .bottom, spacing: 0) {
                TaskDecisionBar(model: model, openWeb: openWeb, requestChanges: { isRequestingChanges = true })
            }
            .sheet(isPresented: $isRequestingChanges) {
                RequestChangesSheet(model: model, close: { isRequestingChanges = false })
                    .presentationDetents([.medium, .large]).presentationBackground(Color(.systemBackground))
            }
            .sheet(isPresented: $isTagging) {
                if case .pick(let fallbackName) = model.earlyWarning {
                    TagCoOwnerSheet(model: model, fallbackName: fallbackName, close: { isTagging = false })
                        .presentationDetents([.large]).presentationBackground(Color(.systemBackground))
                }
            }
            .sensoryFeedback(.success, trigger: model.successCount)
            .sensoryFeedback(.error, trigger: model.errorCount)
            .accessibilityIdentifier("task.screen")
            .task {
                model.isCompactWidth = sizeClass != .regular
                await model.start()
            }
            .onDisappear { Task { await model.stop() } }
    }

    // MARK: States

    @ViewBuilder private var content: some View {
        if let task = model.task, model.load != .failed(.notFound) {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    if model.isOffline {
                        NoticeLine(
                            text: "list.offlineSaved", systemImage: "wifi.slash", identifier: "task.offlineNotice"
                        )
                        .padding(.horizontal, -20)
                    }
                    if !task.canDrive {
                        Text("task.readOnly").font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                            .accessibilityIdentifier("task.readOnly")
                    }
                    header(task)
                    if !task.thread.isEmpty {
                        VStack(alignment: .leading, spacing: 16) {
                            ForEach(Array(task.thread.enumerated()), id: \.offset) { _, entry in
                                TaskEntryView(entry: entry, openLink: openWeb)
                            }
                        }
                        .accessibilityIdentifier("task.body")
                    }
                    pullRequest(task)
                    links(task)
                    earlyWarning
                    questions(task)
                }
                .padding(.horizontal, 20).padding(.vertical, 12)
                .frame(maxWidth: 720, alignment: .leading).frame(maxWidth: .infinity)
            }
            .refreshable { await model.retry() }
        } else {
            switch model.load {
            case .failed(.notFound):
                StateMessage(
                    title: "task.notFound.title", detail: "task.notFound.detail", systemImage: "questionmark.folder",
                    identifier: "task.notFound",
                    actions: {
                        Button(action: back) { Text("task.notFound.back") }.buttonStyle(SecondaryButtonStyle())
                            .accessibilityIdentifier("task.notFound.back")
                    })
            case .failed(let problem):
                StateMessage(
                    title: Text("task.error"), detail: ProblemCopy.message(problem, offline: "task.offline"),
                    systemImage: problem == .offline ? "wifi.slash" : "exclamationmark.triangle",
                    identifier: "task.error",
                    actions: { RetryButton(identifier: "task.retry") { Task { await model.retry() } } })
            case .slow:
                StateMessage(
                    title: "list.slow", systemImage: "hourglass", identifier: "task.slow",
                    actions: { RetryButton(identifier: "task.retry") { Task { await model.retry() } } })
            default:
                RowSkeleton(rowCount: 4, identifier: "task.loading")
            }
        }
    }

    // MARK: Parts

    private func header(_ task: AppTask) -> some View {
        let needsYou = model.decision == .decide || task.task.status == .awaitingClarification
        return VStack(alignment: .leading, spacing: 6) {
            Text(TaskRowView.statusTitle(task.task.status, serverLabel: task.task.statusLabel)).font(
                .subheadline.weight(.semibold)
            )
            .foregroundStyle(needsYou ? Color.yakAction : Color.yakMuted)
            .accessibilityIdentifier("task.status")
            Text(task.task.headline ?? task.task.summary).font(.title2.bold())
                .fixedSize(horizontal: false, vertical: true).accessibilityAddTraits(.isHeader)
                .accessibilityIdentifier("task.title")
            Text(meta(task)).font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                .fixedSize(horizontal: false, vertical: true)
        }
    }

    private func meta(_ task: AppTask) -> String {
        let detail = task.task
        if let by = detail.startedBy {
            return String(localized: "task.meta.by \(detail.repo) \(detail.sourceLabel) \(by)")
        }
        return String(localized: "task.meta \(detail.repo) \(detail.sourceLabel)")
    }

    @ViewBuilder private func pullRequest(_ task: AppTask) -> some View {
        if let pr = task.task.pr {
            let title = pr.number.map { String(localized: "task.pr.number \($0)") } ?? String(localized: "task.pr")
            let detail = task.coOwner?.stat.map {
                String(localized: "task.pr.stat \($0.additions) \($0.deletions)")
            }
            if let url = WebLinkRule.safeURL(pr.url) {
                Button {
                    openWeb(url)
                } label: {
                    row(title: title, detail: detail, isLink: true)
                }
                .buttonStyle(.plain).accessibilityIdentifier("task.pullRequest")
            } else {
                row(title: title, detail: detail, isLink: false).accessibilityIdentifier("task.pullRequest")
            }
        }
    }

    @ViewBuilder private func links(_ task: AppTask) -> some View {
        if task.threadId != nil {
            Button {
                model.openThread()
                didOpenThread()
            } label: {
                row(
                    title: String(localized: "task.openThread"), detail: String(localized: "task.openThread.detail"),
                    isLink: true)
            }
            .buttonStyle(.plain).accessibilityIdentifier("task.openThread")
        }
        if let walkthrough = task.walkthrough {
            switch walkthrough.status {
            case "rendering":
                row(title: String(localized: "task.walkthrough.rendering"), detail: nil, isLink: false)
                    .accessibilityIdentifier("task.walkthrough")
            case "failed":
                row(title: String(localized: "task.walkthrough.failed"), detail: walkthrough.error, isLink: false)
                    .accessibilityIdentifier("task.walkthrough")
            default:
                if model.playableVideoURL != nil, let playback {
                    Button {
                        model.playWalkthrough(with: playback)
                    } label: {
                        row(title: String(localized: "task.walkthrough.title"), detail: nil, isLink: true)
                    }
                    .buttonStyle(.plain).accessibilityIdentifier("task.walkthrough")
                } else if let webURL = WebLinkRule.safeURL(walkthrough.videoUrl) {
                    Button {
                        openWeb(webURL)
                    } label: {
                        row(title: String(localized: "task.walkthrough.web"), detail: nil, isLink: true)
                    }
                    .buttonStyle(.plain).accessibilityIdentifier("task.walkthrough")
                } else {
                    row(title: String(localized: "task.walkthrough.unavailable"), detail: nil, isLink: false)
                        .accessibilityIdentifier("task.walkthrough")
                }
            }
        }
    }

    @ViewBuilder private var earlyWarning: some View {
        switch model.earlyWarning {
        case .none: EmptyView()
        case .pick:
            Button {
                isTagging = true
            } label: {
                row(
                    title: String(localized: "task.earlyWarning"),
                    detail: String(localized: "task.earlyWarning.detail"), isLink: true)
            }
            .buttonStyle(.plain).accessibilityIdentifier("task.earlyWarning")
        case .asked(let name):
            Text("task.asked \(name)").font(.subheadline.weight(.medium)).foregroundStyle(Color.yakMuted)
                .accessibilityIdentifier("task.asked")
        }
    }

    @ViewBuilder private func questions(_ task: AppTask) -> some View {
        if let flow = model.questionFlow {
            TaskQuestionCard(model: model, flow: flow)
        }
    }

    private func row(title: String, detail: String?, isLink: Bool) -> some View {
        HStack(spacing: 12) {
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: title).font(.body).foregroundStyle(.primary).multilineTextAlignment(.leading)
                if let detail {
                    Text(verbatim: detail).font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                        .multilineTextAlignment(.leading)
                }
            }
            Spacer(minLength: 4)
            if isLink {
                Image(systemName: "chevron.right").font(.footnote.weight(.semibold)).foregroundStyle(Color.yakMuted)
                    .accessibilityHidden(true)
            }
        }
        .padding(12).frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
        .background(Color.primary.opacity(0.06), in: .rect(cornerRadius: 12))
        .contentShape(.rect)
    }

    private var webURL: URL {
        instanceURL.appending(path: "tasks").appending(path: model.task?.task.id ?? model.taskID)
    }

    // MARK: Menu

    private var menu: some View {
        Menu {
            ForEach(model.menu) { item in
                if let action = item.action {
                    Button(role: item.isDestructive ? .destructive : nil) {
                        if case .cancel = action {
                            isConfirmingCancel = true
                        } else {
                            Task { await model.perform(action) }
                        }
                    } label: {
                        Text(Self.title(of: item))
                    }
                }
            }
            Button {
                openWeb(webURL)
            } label: {
                Text("task.menu.openWeb")
            }
        } label: {
            Image(systemName: "ellipsis.circle").frame(minWidth: 44, minHeight: 44).contentShape(.rect)
        }
        .accessibilityLabel("task.menu.label")
        .accessibilityIdentifier("task.menu")
        .confirmationDialog("task.cancel.title", isPresented: $isConfirmingCancel, titleVisibility: .visible) {
            Button("task.cancel.confirm", role: .destructive) { Task { await model.perform(.cancel) } }
            Button("task.cancel.keep", role: .cancel) {}
        } message: {
            Text("task.cancel.message")
        }
    }

    static func title(of item: TaskMenuItem) -> LocalizedStringKey {
        switch item.action {
        case .retry: "task.menu.retry"
        case .rerunReview: "task.menu.rerunReview"
        case .retryRender: "task.menu.retryRender"
        case .reroute(let repo): "task.menu.reroute \(repo)"
        case .cancel: "task.menu.cancel"
        default: "task.menu.label"
        }
    }
}
