import SwiftUI
import YakKit

/// The one place the share sheet gets its words. The catalog `Share.xcstrings` is compiled into the app and the
/// share extension.
enum ShareText {
    static func resource(_ key: String.LocalizationValue) -> LocalizedStringResource {
        LocalizedStringResource(key, table: "Share")
    }
    static func text(_ key: String.LocalizationValue) -> Text { Text(resource(key)) }
    static func string(_ key: String.LocalizationValue) -> String { String(localized: resource(key)) }
}

/// The share sheet. Its Send follows the board's accent; it is not a thread screen.
struct ShareScreen: View {
    let model: ShareExtensionModel
    /// Closes the sheet: true after a successful send.
    let finish: (Bool) -> Void
    @FocusState private var isTyping: Bool

    private static let accent = Color(red: 0.74, green: 0.33, blue: 0.16)

    var body: some View {
        VStack(spacing: 0) {
            topRow
            Divider()
            ScrollView {
                VStack(alignment: .leading, spacing: 16) { content }
                    .padding(16).frame(maxWidth: .infinity, alignment: .leading)
            }
            .scrollDismissesKeyboard(.interactively)
        }
        .background(Color(.systemBackground))
        .sensoryFeedback(.error, trigger: model.saveFailureCount)
        .interactiveDismissDisabled(model.phase == .saving)
    }

    private var topRow: some View {
        HStack {
            Button {
                model.cancel()
                finish(false)
            } label: {
                ShareText.text("share.cancel").foregroundStyle(.primary).frame(minWidth: 44, minHeight: 44)
            }
            .buttonStyle(.plain)
            .disabled(model.phase == .saving)
            .accessibilityIdentifier("share.cancel")
            Spacer()
            instanceTitle
            Spacer()
            if showsSend {
                Button {
                    Task { if await model.send() { finish(true) } }
                } label: {
                    Group {
                        if model.phase == .saving { ProgressView() } else { ShareText.text("share.send") }
                    }
                    .frame(minWidth: 44, minHeight: 44)
                }
                .buttonStyle(.borderedProminent).tint(Self.accent).controlSize(.small)
                .disabled(!model.canSend)
                .accessibilityIdentifier("share.send")
            } else {
                Color.clear.frame(width: 44, height: 44)
            }
        }
        .padding(.horizontal, 12).padding(.vertical, 4)
    }

    /// The instance's name; a menu of the instances that can send when there are several.
    @ViewBuilder private var instanceTitle: some View {
        let name = model.instance?.name ?? "Yak"
        if model.readyInstances.count > 1, model.phase == .ready {
            Menu {
                ForEach(model.readyInstances) { candidate in
                    Button {
                        Task { await model.choose(candidate) }
                    } label: {
                        if candidate.id == model.instance?.id { Image(systemName: "checkmark") }
                        Text(verbatim: candidate.name)
                    }
                }
            } label: {
                HStack(spacing: 4) {
                    Text(verbatim: name).font(.headline).lineLimit(1)
                    Image(systemName: "chevron.down").font(.caption.weight(.bold)).accessibilityHidden(true)
                }
                .foregroundStyle(.primary).frame(minHeight: 44)
            }
            .accessibilityLabel(ShareText.text("share.instance.menu"))
            .accessibilityValue(name)
            .accessibilityIdentifier("share.instance")
        } else {
            Text(verbatim: name).font(.headline).accessibilityIdentifier("share.instance")
        }
    }

    private var showsSend: Bool {
        switch model.phase {
        case .noInstance, .signedOut, .unreadable: false
        default: true
        }
    }

    @ViewBuilder private var content: some View {
        switch model.phase {
        case .noInstance:
            empty(title: ShareText.text("share.noInstance.title"), detail: ShareText.text("share.noInstance.body"))
        case .signedOut(let name):
            empty(
                title: Text(ShareText.resource("share.signedOut.title \(name)")),
                detail: ShareText.text("share.signedOut.body"))
        case .unreadable:
            VStack(spacing: 12) {
                empty(title: ShareText.text("share.unreadable.title"), detail: ShareText.text("share.unreadable.body"))
                Button {
                    Task { await model.retry() }
                } label: {
                    ShareText.text("share.retry").frame(minHeight: 44)
                }
                .buttonStyle(.bordered).accessibilityIdentifier("share.retryStart")
            }
        case .loading: ProgressView().frame(maxWidth: .infinity).padding(.top, 40)
        case .ready, .saving: sheet
        }
    }

    private func empty(title: Text, detail: Text) -> some View {
        VStack(spacing: 8) {
            Image(systemName: "tray").font(.largeTitle).foregroundStyle(.secondary).accessibilityHidden(true)
            title.font(.headline).multilineTextAlignment(.center)
            detail.font(.subheadline).foregroundStyle(.primary).multilineTextAlignment(.center)
        }
        .frame(maxWidth: .infinity).padding(.top, 40).accessibilityElement(children: .combine)
        .accessibilityIdentifier("share.notice")
    }

    @ViewBuilder private var sheet: some View {
        @Bindable var model = model
        Picker(selection: $model.target) {
            ShareText.text("share.target.new").tag(ShareTarget.newThread)
            ShareText.text("share.target.last").tag(ShareTarget.lastThread)
        } label: {
            ShareText.text("share.target")
        }
        .pickerStyle(.segmented).disabled(model.phase == .saving).accessibilityIdentifier("share.target")
        preview
        TextField(text: $model.typed, axis: .vertical) {
            ShareText.text("share.text.placeholder")
        }
        .lineLimit(1...6).focused($isTyping).padding(12).frame(minHeight: 44)
        .background(Color(.secondarySystemFill), in: .rect(cornerRadius: 14))
        .disabled(model.phase == .saving).accessibilityIdentifier("share.text")
        if model.target == .newThread { ShareChips(model: model).disabled(model.phase == .saving) }
        lines
    }

    private var preview: some View {
        VStack(alignment: .leading, spacing: 4) {
            if let progress = model.progress {
                HStack(spacing: 10) {
                    ProgressView()
                    Text(ShareText.resource("share.preparing \(max(progress.current, 1)) \(progress.total)"))
                }
                if progress.isSlow {
                    ShareText.text("share.stillPreparing").font(.footnote).foregroundStyle(.primary)
                }
            } else {
                Text(summary).font(.subheadline)
                if model.target == .lastThread, let last = model.context?.lastThread {
                    Text(verbatim: ShareDraft.lastThreadLabel(last)).font(.footnote).foregroundStyle(.primary)
                }
            }
        }
        .padding(12).frame(maxWidth: .infinity, alignment: .leading).frame(minHeight: 64)
        .background(Color(.secondarySystemFill), in: .rect(cornerRadius: 14))
        .accessibilityElement(children: .combine).accessibilityIdentifier("share.preview")
    }

    private var summary: String {
        let inputs = model.importResult.inputs
        let images = inputs.filter { if case .image = $0 { true } else { false } }.count
        let links = inputs.filter { if case .link = $0 { true } else { false } }.count
        var parts: [String] = []
        if images > 0 { parts.append(String(localized: ShareText.resource("share.preview.images \(images)"))) }
        if links > 0 { parts.append(String(localized: ShareText.resource("share.preview.links \(links)"))) }
        if inputs.contains(where: { if case .text = $0 { true } else { false } }) {
            parts.append(ShareText.string("share.preview.text"))
        }
        return parts.isEmpty ? ShareText.string("share.preview.nothing") : parts.joined(separator: ", ")
    }

    @ViewBuilder private var lines: some View {
        VStack(alignment: .leading, spacing: 6) {
            if model.didFailToSave {
                warning(ShareText.text("share.saveFailed"))
            }
            ForEach(Array(model.notices.enumerated()), id: \.offset) { _, notice in
                warning(noticeLine(notice))
            }
            if model.importResult.unreadable > 0 {
                warning(ShareText.text("share.notice.unreadable"))
            }
            ForEach(model.importResult.tooLarge, id: \.self) { name in
                warning(Text(ShareText.resource("share.notice.tooLarge \(name)")))
            }
            if let problem = model.problem, model.progress == nil { problemLine(problem).foregroundStyle(.primary) }
            if model.isOffline { ShareText.text("share.offline").foregroundStyle(.primary) }
        }
        .font(.footnote).accessibilityIdentifier("share.notice")
    }

    /// A warning line: an orange mark and text in the primary color, which stays readable on the sheet.
    private func warning(_ text: Text) -> some View {
        HStack(alignment: .firstTextBaseline, spacing: 6) {
            Image(systemName: "exclamationmark.triangle.fill").foregroundStyle(.orange).accessibilityHidden(true)
            text.foregroundStyle(.primary)
        }
    }

    private func noticeLine(_ notice: ShareNotice) -> Text {
        switch notice {
        case .tooManyFiles(let maximum): Text(ShareText.resource("share.notice.tooMany \(maximum) \(maximum)"))
        case .textCut(let limit): Text(ShareText.resource("share.notice.textCut \(limit)"))
        case .linksDropped: ShareText.text("share.notice.links")
        }
    }

    private func problemLine(_ problem: ShareProblem) -> Text {
        switch problem {
        case .noContext: ShareText.text("share.noContext")
        case .noLastThread: ShareText.text("share.noLast")
        case .cannotSendToLastThread(let title):
            title.map { Text(ShareText.resource("share.cannotSend \($0)")) }
                ?? ShareText.text("share.cannotSendPrivate")
        case .noRepository: ShareText.text("share.noRepository")
        case .repositoryNotReady(let slug, let step):
            Text(ShareText.resource("share.notReady \(slug) \(step ?? "")"))
        case .textTooShort(let minimum): Text(ShareText.resource("share.minText \(minimum)"))
        }
    }
}
