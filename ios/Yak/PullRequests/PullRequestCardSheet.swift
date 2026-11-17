import SwiftUI
import YakKit

/// The pull requests of a thread as a grouped list: the banner, one section per pull request, the repositories
/// that have none, and what the server expects.
struct PullRequestCardSheet: View {
    let thread: ThreadModel
    @State private var model: PullRequestCardModel
    @State private var webLink: WebLink?
    @Environment(\.dismiss) private var dismiss

    init(thread: ThreadModel) {
        self.thread = thread
        _model = State(initialValue: PullRequestCardModel(threadID: thread.threadID, session: thread.session))
    }

    var body: some View {
        NavigationStack {
            content
                .navigationTitle("pullRequest.title")
                .navigationBarTitleDisplayMode(.inline)
                .toolbar {
                    ToolbarItem(placement: .confirmationAction) {
                        Button("common.done") { dismiss() }.accessibilityIdentifier("pullRequest.done")
                    }
                    .sharedBackgroundVisibility(.hidden)
                }
        }
        .presentationDetents([.large])
        .presentationBackground(Color(.systemBackground))
        .sheet(item: $webLink) { BrowserSheet(url: $0.url) }
        .sensoryFeedback(.success, trigger: model.successCount)
        .sensoryFeedback(.error, trigger: model.errorCount)
        .task { await model.start() }
        .task(id: thread.snapshot?.show?.pullRequest?.number) {
            if let snapshot = thread.snapshot { await model.threadChanged(snapshot) }
        }
        .onDisappear { Task { await model.stop() } }
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("pullRequest.card")
    }

    @ViewBuilder private var content: some View {
        if model.panel == nil {
            switch model.load {
            case .failed(let problem):
                StateMessage(
                    title: ProblemCopy.message(problem, offline: "pullRequest.error.offline"), detail: nil,
                    systemImage: "exclamationmark.triangle", identifier: "pullRequest.error",
                    actions: { RetryButton(identifier: "pullRequest.retry") { Task { await model.reload() } } })
            case .slow:
                StateMessage(
                    title: "list.slow", systemImage: "hourglass", identifier: "pullRequest.slow",
                    actions: { RetryButton(identifier: "pullRequest.retry") { Task { await model.reload() } } })
            default:
                RowSkeleton(rowCount: 3, identifier: "pullRequest.loading")
            }
        } else if model.isEmpty {
            StateMessage(
                title: "pullRequest.empty.title", detail: "pullRequest.empty.detail",
                systemImage: "arrow.triangle.pull",
                identifier: "pullRequest.empty")
        } else {
            list
        }
    }

    private var list: some View {
        List {
            if let problem = model.problem {
                Section {
                    HStack {
                        ProblemCopy.message(problem, offline: "pullRequest.stale").font(.footnote.weight(.medium))
                        Spacer(minLength: 8)
                        Button("common.retry") { Task { await model.reload() } }.accessibilityIdentifier(
                            "pullRequest.retry")
                    }
                    .accessibilityIdentifier("pullRequest.problem")
                }
            }
            if let banner = model.banner { bannerSection(banner) }
            ForEach(model.rows) { row in section(row) }
            if !model.withoutPullRequest.isEmpty {
                Section {
                    ForEach(model.withoutPullRequest, id: \.repo) { item in
                        VStack(alignment: .leading, spacing: 2) {
                            Text(verbatim: item.repo)
                            Text(verbatim: item.note).font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                        }
                        .accessibilityElement(children: .combine)
                    }
                }
            }
            if !model.expectations.isEmpty {
                Section {
                } footer: {
                    VStack(alignment: .leading, spacing: 4) {
                        ForEach(model.expectations, id: \.self) { Text(verbatim: $0) }
                    }
                    .foregroundStyle(.primary).font(.footnote.weight(.medium))
                }
            }
        }
        .refreshable { await model.reload() }
    }

    private func bannerSection(_ banner: PullRequestBanner) -> some View {
        Section {
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: banner.title).font(.subheadline.weight(.semibold))
                Text(verbatim: banner.body).font(.footnote.weight(.medium))
            }
            .foregroundStyle(.primary)
            .listRowBackground(tint(banner.tone))
            .accessibilityElement(children: .combine).accessibilityIdentifier("pullRequest.banner")
        }
    }

    private func tint(_ tone: BannerTone) -> Color {
        switch tone {
        case .danger: Color.red.opacity(0.14)
        case .success: Color.green.opacity(0.16)
        default: Color.primary.opacity(0.07)
        }
    }

    private func section(_ row: PullRequestCardRow) -> some View {
        Section {
            VStack(alignment: .leading, spacing: 4) {
                HStack(alignment: .firstTextBaseline) {
                    Text(verbatim: row.title).font(.headline).fixedSize(horizontal: false, vertical: true)
                    Spacer(minLength: 8)
                    Text(stateWord(row.state)).font(.subheadline.weight(.medium)).foregroundStyle(Color.yakMuted)
                }
                Text(checksLine(row.checks)).font(.subheadline.weight(.medium)).foregroundStyle(Color.yakMuted)
                if case .failing(_, _, let excerpt?) = row.checks {
                    Text(verbatim: excerpt).font(.footnote.monospaced()).fixedSize(horizontal: false, vertical: true)
                }
                if let waitsFor = row.waitsFor {
                    Text(verbatim: waitsFor).font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                }
            }
            .accessibilityElement(children: .combine)
            .accessibilityIdentifier("pullRequest.row.\(row.id)")
            if let url = WebLinkRule.safeURL(row.url) {
                Button {
                    webLink = WebLink(url: url)
                } label: {
                    Text("pullRequest.openGitHub").frame(minHeight: 44)
                }
                .foregroundStyle(.primary).accessibilityIdentifier("pullRequest.open.\(row.id)")
            }
            if row.canAskToFix { fixButton(row.id) }
        }
    }

    @ViewBuilder private func fixButton(_ id: Int) -> some View {
        let state = model.fixing[id]
        Button {
            Task { await model.askToFix(id) }
        } label: {
            HStack {
                if state == .sending { ProgressView() }
                Text(state == .sent ? "pullRequest.fix.sent" : "pullRequest.fix").frame(minHeight: 44)
            }
        }
        .foregroundStyle(.primary).disabled(state == .sending || state == .sent)
        .accessibilityIdentifier("pullRequest.askToFix.\(id)")
        if case .failed(let message)? = state {
            Text(verbatim: message).font(.footnote.weight(.medium)).foregroundStyle(Color.yakDanger)
                .accessibilityIdentifier("pullRequest.fix.error.\(id)")
        }
    }

    private func stateWord(_ state: PullRequestState) -> LocalizedStringKey {
        switch state {
        case .merged: "pullRequest.state.merged"
        case .closed: "pullRequest.state.closed"
        default: "pullRequest.state.open"
        }
    }

    private func checksLine(_ checks: PullRequestCardRow.Checks) -> LocalizedStringKey {
        switch checks {
        case .none: "pullRequest.checks.none"
        case .passing: "pullRequest.checks.passed"
        case .failing(let failed, let total, _): "pullRequest.checks.failing \(failed) \(total)"
        case .running(let done, let total): "pullRequest.checks.running \(done) \(total)"
        case .other(let word): "pullRequest.checks.other \(word)"
        }
    }
}

/// A page to open in the in-app browser.
private struct WebLink: Identifiable {
    let url: URL
    var id: String { url.absoluteString }
}
