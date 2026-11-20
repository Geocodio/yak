import SwiftUI
import UIKit
import YakKit

// MARK: Building blocks

/// A group heading over a rounded card of rows. The identifier is `overview.group.<kind>`.
struct OverviewGroupCard<Rows: View>: View {
    let kind: OverviewGroupKind
    let title: LocalizedStringKey
    @ViewBuilder let rows: Rows

    var body: some View {
        VStack(alignment: .leading, spacing: Design.Space.small) {
            Text(title).font(.yakGroupHeading).foregroundStyle(Color.yakMuted)
                .padding(.horizontal, Design.Space.medium).accessibilityAddTraits(.isHeader)
            VStack(alignment: .leading, spacing: 0) { rows }
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Color.yakCard, in: .rect(cornerRadius: Design.Radius.group))
        }
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("overview.group.\(kind.rawValue)")
    }
}

/// One row: a title with an optional line under it and a trailing control. Text wraps instead of truncating.
struct OverviewRow<Subtitle: View, Trailing: View>: View {
    let title: Text
    var isMonospaced = false
    @ViewBuilder let subtitle: Subtitle
    @ViewBuilder let trailing: Trailing

    var body: some View {
        HStack(alignment: .center, spacing: 12) {
            VStack(alignment: .leading, spacing: 2) {
                title.font(isMonospaced ? .callout.monospaced() : .body)
                    .fixedSize(horizontal: false, vertical: true).textSelection(.enabled)
                subtitle.font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                    .fixedSize(horizontal: false, vertical: true)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            trailing.layoutPriority(1)
        }
        .padding(.horizontal, Design.Space.rowInset).padding(.vertical, 10)
        .frame(minHeight: 44)
        .accessibilityElement(children: .contain)
    }
}

struct RowDivider: View {
    var body: some View { Divider().padding(.leading, Design.Space.rowInset) }
}

/// A copy button that says "Copied" in place for two seconds. It plays no haptic.
struct CopyButton: View {
    let text: String
    let key: String
    let label: LocalizedStringKey
    let identifier: String
    let model: OverviewModel

    var body: some View {
        let isCopied = model.copiedKey == key
        Button {
            UIPasteboard.general.string = text
            model.noteCopied(key)
        } label: {
            Group {
                if isCopied {
                    Label("overview.copied", systemImage: "checkmark")
                } else {
                    Image(systemName: "doc.on.doc")
                }
            }
            .font(.subheadline.weight(.medium)).frame(minWidth: 44, minHeight: 44).contentShape(.rect)
        }
        .buttonStyle(.plain).foregroundStyle(.primary)
        .accessibilityLabel(isCopied ? Text("overview.copied") : Text(label))
        .accessibilityIdentifier(identifier)
    }
}

/// Rows shaped like the real ones, shown while a group waits. Reduce Motion keeps them still.
struct SkeletonRows: View {
    var count = 2
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var isDim = false

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            ForEach(0..<count, id: \.self) { index in
                VStack(alignment: .leading, spacing: 6) {
                    RoundedRectangle(cornerRadius: 4).fill(Color.primary.opacity(0.12)).frame(width: 180, height: 14)
                    RoundedRectangle(cornerRadius: 4).fill(Color.primary.opacity(0.08)).frame(width: 110, height: 10)
                }
                .padding(.horizontal, 16).padding(.vertical, 12)
                if index < count - 1 { RowDivider() }
            }
        }
        .opacity(isDim ? 0.55 : 1)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(Text("load.loading"))
        .accessibilityIdentifier("overview.skeleton")
        .onAppear {
            guard !StillMotion.isOn(reduceMotion: reduceMotion) else { return }
            withAnimation(Motion.pulse) { isDim = true }
        }
    }
}

/// What a group shows in place of values it does not have: nothing, skeleton rows, or the problem with a way out.
enum GroupLoad: Equatable {
    case ready
    case loading
    case slow
    case failed(ThreadProblem)

    static func of<Value>(_ resource: ResourceState<Value>?, couldNotOpen: Bool) -> GroupLoad {
        if couldNotOpen, resource?.value == nil {
            return .failed(.server(code: "unavailable", message: String(localized: "overview.couldNotOpen")))
        }
        guard let resource else { return .loading }
        if resource.value != nil { return .ready }
        switch resource.load {
        case .slow: return .slow
        case .failed(let problem): return .failed(problem)
        case .loading, .ready: return .loading
        }
    }
}

struct GroupNotice: View {
    let load: GroupLoad
    let showsSkeleton: Bool
    let retry: () -> Void
    let openThreads: () -> Void

    var body: some View {
        switch load {
        case .ready: EmptyView()
        case .loading:
            if showsSkeleton { SkeletonRows() }
        case .slow:
            notice(Text("overview.slow"), identifier: "overview.slow") { retryButton }
        case .failed(.offline):
            notice(Text("overview.offline.group"), identifier: "overview.error") { retryButton }
        case .failed(.notFound):
            notice(Text("load.notFound.title"), identifier: "overview.notFound") {
                Button(action: openThreads) { Text("load.openThreads") }
                    .buttonStyle(.bordered).tint(.primary).accessibilityIdentifier("overview.openThreads")
            }
        case .failed(.server(_, let message)):
            notice(Text(message), identifier: "overview.error") { retryButton }
        case .failed:
            notice(Text("overview.couldNotOpen"), identifier: "overview.error") { retryButton }
        }
    }

    private var retryButton: some View {
        Button(action: retry) { Text("common.retry") }
            .buttonStyle(.bordered).tint(.primary).accessibilityIdentifier("overview.retry")
    }

    private func notice<Action: View>(_ text: Text, identifier: String, @ViewBuilder action: () -> Action)
        -> some View
    {
        VStack(alignment: .leading, spacing: 10) {
            text.font(.subheadline).fixedSize(horizontal: false, vertical: true)
            action()
        }
        .padding(16).frame(maxWidth: .infinity, alignment: .leading)
        .accessibilityElement(children: .contain).accessibilityIdentifier(identifier)
    }
}

// MARK: Status

struct StatusGroupView: View {
    let status: StatusGroup

    var body: some View {
        OverviewGroupCard(kind: .status, title: "overview.group.status") {
            OverviewRow(title: sandboxText) {
                if let slots = status.slots {
                    Text("overview.status.slots \(slots.used) \(slots.limit)")
                } else if let minutes = status.sleepsAfterMinutes {
                    Text("overview.status.sleeps \(minutes)")
                }
            } trailing: {
                Circle().fill(color).frame(width: 10, height: 10).accessibilityHidden(true)
            }
            .accessibilityIdentifier("overview.status")
        }
    }

    private var sandboxText: Text {
        switch status.sandbox {
        case .running: Text("overview.sandbox.running")
        case .starting: Text("overview.sandbox.starting")
        case .paused: Text("overview.sandbox.paused")
        case .stopped: Text("overview.sandbox.stopped")
        case .destroying: Text("overview.sandbox.destroying")
        case .destroyed: Text("overview.sandbox.destroyed")
        case .none: Text("overview.sandbox.none")
        default: Text("overview.sandbox.other \(status.sandbox.rawValue)")
        }
    }

    /// Good is green, attention orange and everything else a neutral gray, never the rust accent.
    private var color: Color {
        switch status.tone {
        case .good: .green
        case .attention: .orange
        case .neutral: Color(.systemGray2)
        }
    }
}

// MARK: Links

struct LinksGroupView: View {
    let links: LinksGroup
    let load: GroupLoad
    let model: OverviewModel
    let openURL: (URL) -> Void
    let showOutput: (RepositoryRow) -> Void
    let retry: () -> Void
    let openThreads: () -> Void

    var body: some View {
        let rows = makeRows()
        OverviewGroupCard(kind: .links, title: "overview.group.links") {
            ForEach(rows.indices, id: \.self) { index in
                if index > 0 { RowDivider() }
                rows[index]
            }
        }
    }

    /// The group's rows in order; the divider goes between them, never after the last.
    private func makeRows() -> [AnyView] {
        var rows: [AnyView] = []
        if let host = links.previewHost {
            rows.append(
                AnyView(
                    OverviewRow(title: Text(host), isMonospaced: true) {
                        Text("overview.links.preview")
                    } trailing: {
                        CopyButton(
                            text: host, key: "preview", label: "overview.copyPreview.label",
                            identifier: "overview.copyPreview", model: model)
                    }))
        }
        if let branch = links.branch {
            rows.append(
                AnyView(
                    OverviewRow(title: Text(branch), isMonospaced: true) {
                        Text("overview.links.branch")
                    } trailing: {
                        CopyButton(
                            text: branch, key: "branch", label: "overview.copyBranch.label",
                            identifier: "overview.copyBranch", model: model)
                    }))
        }
        if let pullRequest = links.pullRequest {
            rows.append(
                AnyView(
                    linkRow(
                        title: Text(
                            "overview.links.pullRequestNumber \(pullRequest.number) \(pullRequestState(pullRequest))"),
                        subtitle: Text("overview.links.pullRequest"), url: pullRequest.url,
                        identifier: "overview.pullRequest")))
        }
        if let deployment = links.deployment {
            rows.append(
                AnyView(
                    linkRow(
                        title: Text(deployment.url), subtitle: Text(deployment.status),
                        url: deployment.url, identifier: "overview.deployment")))
        }
        for repository in links.repositories { rows.append(AnyView(repositoryRow(repository))) }
        if links.isEmpty, load == .ready {
            rows.append(
                AnyView(
                    Text("overview.links.empty").font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                        .fixedSize(horizontal: false, vertical: true)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(16).accessibilityIdentifier("overview.links.empty")))
        }
        if load != .ready {
            rows.append(
                AnyView(
                    GroupNotice(
                        load: load, showsSkeleton: model.showsSkeleton, retry: retry, openThreads: openThreads)))
        }
        return rows
    }

    private func pullRequestState(_ pullRequest: ThreadEnvironment.PullRequest) -> String {
        switch pullRequest.state {
        case "open": String(localized: "overview.pullRequest.open")
        case "merged": String(localized: "overview.pullRequest.merged")
        case "closed": String(localized: "overview.pullRequest.closed")
        default: pullRequest.state
        }
    }

    /// The address a link row opens; nil leaves the row plain.
    static func linkTarget(_ url: String) -> URL? { WebLinkRule.safeURL(url) }

    @ViewBuilder
    private func linkRow(title: Text, subtitle: Text, url: String, identifier: String) -> some View {
        if let target = Self.linkTarget(url) {
            Button {
                openURL(target)
            } label: {
                OverviewRow(title: title, isMonospaced: false) {
                    subtitle
                } trailing: {
                    Image(systemName: "chevron.right").font(.footnote.weight(.semibold)).foregroundStyle(Color.yakMuted)
                        .accessibilityHidden(true)
                }
                .contentShape(.rect)
            }
            .buttonStyle(.plain).accessibilityElement(children: .combine).accessibilityIdentifier(identifier)
        } else {
            OverviewRow(title: title) {
                subtitle
            } trailing: {
                EmptyView()
            }
            .accessibilityIdentifier(identifier)
        }
    }

    @ViewBuilder
    private func repositoryRow(_ repository: RepositoryRow) -> some View {
        let isRetrying = model.runningAction == .retry(repository.id)
        let row = OverviewRow(title: Text(repository.repo)) {
            VStack(alignment: .leading, spacing: 2) {
                Text(subtitle(of: repository))
                if let error = repository.error, repository.status == .failed {
                    Text(error).foregroundStyle(Color.yakWarning)
                }
            }
        } trailing: {
            if repository.canRetry {
                Button {
                    Task { await model.retryRepositorySetup(repository.id) }
                } label: {
                    if isRetrying { ProgressView().frame(minWidth: 44, minHeight: 44) } else { Text("common.retry") }
                }
                .buttonStyle(.bordered).tint(.primary).controlSize(.regular)
                .disabled(isRetrying || model.isOffline)
                .accessibilityHint(model.isOffline ? Text("overview.notOffline") : Text(""))
                .accessibilityIdentifier("overview.retry.\(repository.id.value)")
            } else if repository.status.isInProgress {
                ProgressView().accessibilityLabel(Text("overview.repo.settingUp"))
            }
        }
        if repository.status == .failed, repository.output != nil {
            Button {
                showOutput(repository)
            } label: {
                row.contentShape(.rect)
            }
            .buttonStyle(.plain).accessibilityHint(Text("overview.repo.showOutput"))
            .accessibilityIdentifier("overview.repository.\(repository.id.value)")
        } else {
            row.accessibilityIdentifier("overview.repository.\(repository.id.value)")
        }
    }

    private func subtitle(of repository: RepositoryRow) -> String {
        let role =
            switch repository.role {
            case .primary: String(localized: "overview.role.primary")
            case .changes: String(localized: "overview.role.changes")
            case .context: String(localized: "overview.role.context")
            default: repository.role.rawValue
            }
        let status: String? =
            switch repository.status {
            case .ready: repository.role == .primary ? nil : String(localized: "overview.repo.ready")
            case .failed: String(localized: "overview.repo.failed")
            case .pending, .cloning, .setup: String(localized: "overview.repo.settingUp")
            default: repository.status.rawValue
            }
        return status.map { "\(role), \($0)" } ?? role
    }
}

// MARK: Access

struct AccessGroupView: View {
    let access: AccessGroup
    let model: OverviewModel

    var body: some View {
        OverviewGroupCard(kind: .access, title: "overview.group.access") {
            OverviewRow(title: Text(access.sshCommand), isMonospaced: true) {
                Text("overview.access.terminal")
            } trailing: {
                CopyButton(
                    text: access.sshCommand, key: "ssh", label: "overview.copySsh.label",
                    identifier: "overview.copySsh", model: model)
            }
            RowDivider()
            OverviewRow(
                title: Text(access.runsOn == .computer ? "overview.access.computer" : "overview.access.sandbox")
            ) {
                EmptyView()
            } trailing: {
                EmptyView()
            }
        }
    }
}

// MARK: People

struct PeopleGroupView: View {
    let people: PeopleGroup
    let now: Date
    /// Opens the share sheet; nil when the person is not the owner.
    var onChange: (() -> Void)?

    var body: some View {
        OverviewGroupCard(kind: .people, title: "overview.group.people") {
            OverviewRow(title: visibilityText) {
                if let line = startedByText { line }
            } trailing: {
                if people.canChangeSharing, let onChange {
                    Button(action: onChange) {
                        Text("overview.people.change").frame(minWidth: 44, minHeight: 44).contentShape(.rect)
                    }
                    .buttonStyle(.plain).foregroundStyle(Color.accentColor)
                    .accessibilityLabel(Text("overview.people.changeLabel"))
                    .accessibilityIdentifier("overview.share")
                }
            }
            .accessibilityIdentifier("overview.visibility")
            if let account = people.account {
                RowDivider()
                OverviewRow(title: Text("overview.people.runsOn \(account.name)")) {
                    if account.isYours { Text("overview.people.yourAccount") }
                } trailing: {
                    EmptyView()
                }
                .accessibilityIdentifier("overview.account")
            }
        }
    }

    private var visibilityText: Text {
        switch people.visibility {
        case .privateToYou: Text("overview.visibility.private")
        case .sharedWithPeople(let count): Text("overview.visibility.people \(count)")
        case .sharedWithTeam: Text("overview.visibility.team")
        case .sharedWithYou: Text("overview.visibility.you")
        }
    }

    var startedByText: Text? {
        guard let startedBy = people.startedBy else { return nil }
        let name: String?
        let date: Date?
        switch startedBy {
        case .you(let at): (name, date) = (nil, at)
        case .person(let person, let at): (name, date) = (person, at)
        }
        guard let date else {
            return name.map { Text("overview.startedBy.person.unknown \($0)") }
                ?? Text("overview.startedBy.you.unknown")
        }
        let age = RelativeAge.value(from: date, now: now)
        switch (name, age) {
        case (nil, .now): return Text("overview.startedBy.you.now")
        case (nil, .minutes(let value)): return Text("overview.startedBy.you.minutes \(value)")
        case (nil, .hours(let value)): return Text("overview.startedBy.you.hours \(value)")
        case (nil, .days(let value)): return Text("overview.startedBy.you.days \(value)")
        case (nil, .date(let value)):
            return Text("overview.startedBy.you.date \(value.formatted(date: .abbreviated, time: .omitted))")
        case (let person?, .now): return Text("overview.startedBy.person.now \(person)")
        case (let person?, .minutes(let value)): return Text("overview.startedBy.person.minutes \(person) \(value)")
        case (let person?, .hours(let value)): return Text("overview.startedBy.person.hours \(person) \(value)")
        case (let person?, .days(let value)): return Text("overview.startedBy.person.days \(person) \(value)")
        case (let person?, .date(let value)):
            return Text(
                "overview.startedBy.person.date \(person) \(value.formatted(date: .abbreviated, time: .omitted))")
        }
    }
}

// MARK: Setup output

struct SetupOutputSheet: View {
    let row: RepositoryRow
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Text(row.repo).font(.headline)
                if let error = row.error { Text(error).foregroundStyle(Color.yakWarning) }
                Text(row.output ?? "").font(.callout.monospaced()).textSelection(.enabled)
                    .fixedSize(horizontal: false, vertical: true)
                    .accessibilityIdentifier("overview.setupOutput.text")
            }
            .frame(maxWidth: .infinity, alignment: .leading).padding(16)
        }
        .safeAreaInset(edge: .top, spacing: 0) {
            SheetHeader(title: "overview.setupOutput.title") {
                Color.clear.frame(width: 44, height: 44).accessibilityHidden(true)
            } confirm: {
                Button("overview.done") { dismiss() }.fontWeight(.semibold).foregroundStyle(Color.primary)
                    .accessibilityIdentifier("overview.setupOutput.done")
            }
            .background(.yakPage)
        }
        .background(.yakPage)
        .presentationBackground(Color.yakBackground)
        .accessibilityIdentifier("overview.setupOutput")
    }
}
