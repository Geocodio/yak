import SwiftUI
import YakKit

/// One file on its own screen: its header, then its rows or the state that stands in for them.
struct FilePageView: View {
    let file: DiffFileSummary
    let state: FilePageState?
    let isViewed: Bool
    let toggleViewed: () -> Void
    let retry: () -> Void
    let openWeb: () -> Void
    /// Opens this file in the Files editor; nil when the person cannot edit it from here.
    var edit: (() -> Void)?

    /// Editing starts from a diff only for a file that still exists, is text and fits the diff: a deleted, binary or
    /// too large file stays a diff, and so does a page whose file is gone.
    static func canEdit(
        file: DiffFileSummary, state: FilePageState?, canDrive: Bool, filesAvailable: Bool, primaryRepo: String?
    ) -> Bool {
        // The editor's `.repo` scope shows the thread's primary repository only.
        guard canDrive, filesAvailable, file.repo == primaryRepo, file.status != .deleted, !file.isBinary,
            !file.isTooLarge
        else { return false }
        switch state {
        case .binary, .tooLarge, .gone: return false
        default: return true
        }
    }

    var body: some View {
        ScrollView {
            // The server bounds a patch, so the rows are laid out together; the audit cannot measure a lazy stack's text.
            VStack(alignment: .leading, spacing: 0) {
                header.padding(.horizontal, 16).padding(.bottom, 12)
                content
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .accessibilityElement(children: .contain)
    }

    private var name: String { (file.path as NSString).lastPathComponent }
    private var folder: String {
        let parent = (file.path as NSString).deletingLastPathComponent
        return parent.isEmpty ? file.repo : "\(file.repo) / \(parent)"
    }

    private var header: some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack(alignment: .firstTextBaseline) {
                Text(verbatim: name).font(.title3.monospaced().weight(.semibold))
                    .fixedSize(horizontal: false, vertical: true).accessibilityLabel(Text("files.file.label \(name)"))
                    .accessibilityAddTraits(.isHeader).accessibilityIdentifier("files.file.title")
                Spacer(minLength: 8)
                counts
            }
            Text(verbatim: folder).font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                .fixedSize(horizontal: false, vertical: true)
            if let old = file.oldPath, file.status == .renamed {
                Text("files.renamedFrom \(old)").font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                    .fixedSize(horizontal: false, vertical: true)
            }
            HStack(spacing: 16) {
                viewedButton
                if let edit {
                    Button(action: edit) {
                        Label {
                            Text("files.page.edit")
                        } icon: {
                            Image(systemName: "pencil").accessibilityHidden(true)
                        }
                        .font(.footnote.weight(.semibold)).frame(minHeight: 44)
                    }
                    .foregroundStyle(.primary).accessibilityIdentifier("files.page.edit")
                }
            }
        }
    }

    private var viewedButton: some View {
        Button(action: toggleViewed) {
            Label {
                Text(isViewed ? "files.viewed.on" : "files.viewed.off")
            } icon: {
                Image(systemName: isViewed ? "checkmark.circle.fill" : "circle").accessibilityHidden(true)
            }
            .font(.footnote.weight(.semibold)).frame(minHeight: 44)
        }
        .foregroundStyle(.primary)
        .accessibilityLabel(Text(isViewed ? "files.viewed.label.on" : "files.viewed.label.off"))
        .accessibilityIdentifier("files.viewed")
    }

    @ViewBuilder private var counts: some View {
        if let additions = file.additions, let deletions = file.deletions {
            HStack(spacing: 6) {
                Text(verbatim: "+\(additions)").foregroundStyle(Color.diffAdded)
                Text(verbatim: "\u{2212}\(deletions)").foregroundStyle(Color.diffRemoved)
            }
            .font(.footnote.monospacedDigit().weight(.semibold)).fixedSize()
            .accessibilityElement(children: .combine).accessibilityLabel(
                Text("files.counts.label \(additions) \(deletions)"))
        }
    }

    @ViewBuilder private var content: some View {
        switch state {
        case .ready(let patch):
            ForEach(patch.rows) { DiffRowView(row: $0) }
        case .loading, nil:
            RowSkeleton(rowCount: 12, identifier: "files.page.loading").frame(minHeight: 320)
        case .slow:
            message("files.page.slow", detail: nil, identifier: "files.page.slow") {
                RetryButton(identifier: "files.page.retry", action: retry)
            }
        case .noContentChanges:
            message("files.page.noContent", detail: nil, identifier: "files.page.noContent") { EmptyView() }
        case .binary:
            message("files.page.binary", detail: nil, identifier: "files.page.binary") { webButton }
        case .tooLarge:
            message("files.page.tooLarge", detail: nil, identifier: "files.page.tooLarge") { webButton }
        case .gone:
            message("files.page.gone", detail: nil, identifier: "files.page.gone") { EmptyView() }
        case .notSaved:
            message("files.page.notSaved", detail: "files.page.notSaved.detail", identifier: "files.page.notSaved") {
                EmptyView()
            }
        case .failed(let problem):
            VStack(spacing: 10) {
                ProblemCopy.message(problem, offline: "files.error.offline").font(.subheadline.weight(.medium))
                    .multilineTextAlignment(.center)
                RetryButton(identifier: "files.page.retry", action: retry)
            }
            .padding(24).frame(maxWidth: .infinity).accessibilityIdentifier("files.page.failed")
        }
    }

    private var webButton: some View {
        Button(action: openWeb) { Text("files.page.openWeb").fixedSize(horizontal: false, vertical: true) }
            .buttonStyle(SecondaryButtonStyle()).accessibilityIdentifier("files.page.openWeb")
    }

    private func message<Actions: View>(
        _ title: LocalizedStringKey, detail: LocalizedStringKey?, identifier: String,
        @ViewBuilder actions: () -> Actions
    ) -> some View {
        VStack(spacing: 10) {
            Text(title).font(.headline).multilineTextAlignment(.center)
            if let detail {
                Text(detail).font(.subheadline.weight(.medium)).foregroundStyle(Color.yakMuted)
                    .multilineTextAlignment(.center)
            }
            actions()
        }
        .fixedSize(horizontal: false, vertical: true)
        .padding(24).frame(maxWidth: .infinity).accessibilityIdentifier(identifier)
    }
}
