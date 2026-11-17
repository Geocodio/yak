import SwiftUI
import YakKit

/// Files' row in the thread's bottom bar: previous and next file, the position pill that opens the file list, and
/// the Open PR button.
struct FilesBar: View {
    let thread: ThreadModel
    @State private var isListing = false
    @State private var isShowingCard = false
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    private var model: FilesModel { thread.files }

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            if let line = notice {
                Text(verbatim: line).font(.footnote.weight(.medium)).foregroundStyle(Color.yakDanger)
                    .fixedSize(horizontal: false, vertical: true).accessibilityIdentifier("files.open.notice")
            }
            if dynamicTypeSize.isAccessibilitySize {
                VStack(alignment: .leading, spacing: 8) {
                    pager
                    OpenPullRequestButton(model: model) { isShowingCard = true }
                }
            } else {
                HStack(spacing: 8) {
                    pager
                    Spacer(minLength: 4)
                    OpenPullRequestButton(model: model) { isShowingCard = true }
                }
            }
        }
        .sensoryFeedback(.success, trigger: model.successCount)
        .sensoryFeedback(.error, trigger: model.errorCount)
        .sheet(isPresented: $isListing) { FileListSheet(model: model, close: { isListing = false }) }
        .sheet(isPresented: $isShowingCard) { PullRequestCardSheet(thread: thread) }
        .sheet(isPresented: Bindable(model).isConfirmingPrivatePublish) { PrivatePublishSheet(model: model) }
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("files.bar")
    }

    private var notice: String? {
        if case .failed(let message) = model.openPullRequest, model.openNotice == nil { return message }
        return model.openNotice
    }

    private var position: (index: Int, count: Int)? { model.snapshot?.pager.position }

    private var pager: some View {
        HStack(spacing: 4) {
            Button {
                Task { await model.previous() }
            } label: {
                Image(systemName: "chevron.left").frame(minWidth: 44, minHeight: 44)
            }
            .disabled(!(model.snapshot?.pager.canGoBack ?? false))
            .accessibilityLabel(Text("files.previous")).accessibilityIdentifier("files.previous")
            .yakShortcut(.previousFile)
            Button {
                isListing = true
            } label: {
                pill
            }
            .disabled(position == nil)
            .accessibilityLabel(pillLabel).accessibilityIdentifier("files.list")
            Button {
                Task { await model.next() }
            } label: {
                Image(systemName: "chevron.right").frame(minWidth: 44, minHeight: 44)
            }
            .disabled(!(model.snapshot?.pager.canGoForward ?? false))
            .accessibilityLabel(Text("files.next")).accessibilityIdentifier("files.next")
            .yakShortcut(.nextFile)
        }
        .foregroundStyle(.primary)
    }

    @ViewBuilder private var pill: some View {
        if let position {
            Text("files.position \(position.index) \(position.count)")
        } else {
            Text(verbatim: model.fileCountLabel)
        }
    }

    private var pillLabel: Text {
        if let position { return Text("files.position.label \(position.index) \(position.count)") }
        return Text(verbatim: model.fileCountLabel)
    }
}
