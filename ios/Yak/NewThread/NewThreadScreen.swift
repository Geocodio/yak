import PhotosUI
import SwiftUI
import UniformTypeIdentifiers
import YakKit

/// The screen that starts a thread: what to work on, the repository, mode and model chips above the composer, and
/// every state the repositories and the send can be in. It keeps the model while it is on screen and stops it when
/// it goes, which saves the draft.
struct NewThreadScreen: View {
    let app: AppModel
    let session: InstanceSession
    let scene: SceneModel
    let instanceURL: URL
    let needsYouCount: Int
    let isSessionActive: Bool
    let isCompactWidth: Bool
    let openWeb: (URL) -> Void

    @State private var model: NewThreadModel?
    @State private var modelOpenCount = 0
    /// The stop of the model that went before, so the next one reads the draft it saved.
    @State private var stopping: Task<Void, Never>?

    private struct Opening: Hashable {
        let isSessionActive: Bool
        let openCount: Int
    }

    var body: some View {
        Group {
            if let model {
                NewThreadContent(
                    model: model, app: app, needsYouCount: needsYouCount, isCompactWidth: isCompactWidth,
                    isDrawerOpen: scene.state.isDrawerOpen,
                    openDrawer: { scene.setDrawerOpen(true) }, openWeb: { openWeb(instanceURL) })
            } else {
                Color(.systemBackground)
            }
        }
        .task(id: Opening(isSessionActive: isSessionActive, openCount: scene.newThreadOpenCount)) { await run() }
    }

    /// The prefill and the item of a link or of the drawer's "Not sent yet" row are read once, when the screen opens.
    private func run() async {
        await stopping?.value
        // A model that never started only holds what the person typed while the session was not active; it stays.
        let next: NewThreadModel
        if let model, !model.hasStarted, modelOpenCount == scene.newThreadOpenCount {
            next = model
        } else {
            next = NewThreadModel(
                session: session, scene: scene, prefill: scene.newThreadPrefill, item: scene.newThreadItem)
            model = next
            modelOpenCount = scene.newThreadOpenCount
        }
        next.isCompactWidth = isCompactWidth
        guard isSessionActive else { return }
        await next.start()
        // Waits until the task is cancelled, which is how the screen leaves.
        while !Task.isCancelled { try? await Task.sleep(for: .seconds(3600)) }
        // The stop runs in a task of its own, so the cancellation of this one cannot cut it short.
        let stop = Task { await next.stop() }
        stopping = stop
        await stop.value
    }
}

private struct NewThreadContent: View {
    @Bindable var model: NewThreadModel
    let app: AppModel
    let needsYouCount: Int
    let isCompactWidth: Bool
    let isDrawerOpen: Bool
    let openDrawer: () -> Void
    let openWeb: () -> Void

    @State private var showsMore = false
    @State private var showsModels = false
    @FocusState private var isFieldFocused: Bool
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @Environment(\.verticalSizeClass) private var verticalSizeClass

    private var scrollsBottom: Bool { dynamicTypeSize.isAccessibilitySize || verticalSizeClass == .compact }
    private var hasNoRepositories: Bool { model.load == .ready && model.options?.repos.isEmpty == true }
    private var isOffline: Bool { !app.network.isOnline || model.isOffline }

    var body: some View {
        NavigationStack {
            ScrollViewReader { proxy in
                ScrollView {
                    VStack(spacing: 20) {
                        header
                        middle
                        // At accessibility sizes and in a short window the bar is taller than the room, so it scrolls with the content.
                        if scrollsBottom, !hasNoRepositories { bottom.id(Self.barID) }
                    }
                    .frame(maxWidth: 560).frame(maxWidth: .infinity)
                    .padding(.horizontal, 24).padding(.top, 32).padding(.bottom, 16)
                }
                // The field and the send button stay above the keyboard while the person types.
                .onChange(of: isFieldFocused) { scrollBarIntoView(proxy) }
                .onChange(of: model.text) { scrollBarIntoView(proxy) }
                .onReceive(NotificationCenter.default.publisher(for: UIResponder.keyboardDidShowNotification)) { _ in
                    scrollBarIntoView(proxy)
                }
            }
            .scrollDismissesKeyboard(.interactively)
            .scrollBounceBehavior(.basedOnSize)
            .background(Color(.systemBackground))
            .safeAreaInset(edge: .bottom, spacing: 0) {
                if !scrollsBottom, !hasNoRepositories { bottom }
            }
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                if isCompactWidth {
                    ToolbarItem(placement: .topBarLeading) { threadsButton }
                }
                ToolbarItem(placement: .principal) {
                    Text("shell.newThreadTitle").font(.headline).accessibilityAddTraits(.isHeader)
                }
                ToolbarItem(placement: .topBarTrailing) { moreButton }
            }
        }
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("newThread")
        .opacity(isCompactWidth && isDrawerOpen ? 0 : 1)
        .animation(.easeOut(duration: 0.2), value: isDrawerOpen)
        .sheet(isPresented: $showsMore, onDismiss: focusFieldWhenFree) { MoreOptionsSheet(model: model) }
        .sheet(isPresented: $showsModels, onDismiss: focusFieldWhenFree) { NewThreadModelPicker(model: model) }
        .sensoryFeedback(.warning, trigger: model.refusedSendCount)
        .task(id: model.load == .ready || model.load == .loading) { focusFieldWhenFree() }
        .onChange(of: model.pending == nil) { focusFieldWhenFree() }
        .onChange(of: isDrawerOpen) { _, isOpen in
            if isOpen { isFieldFocused = false } else { focusFieldWhenFree() }
        }
        .onChange(of: app.network.isOnline) { _, isOnline in
            if isOnline, model.options == nil { Task { await model.retry() } }
        }
    }

    private static let barID = "newThread.barAnchor"

    private func scrollBarIntoView(_ proxy: ScrollViewProxy) {
        guard scrollsBottom else { return }
        withAnimation(.smooth(duration: 0.2)) { proxy.scrollTo(Self.barID, anchor: .bottom) }
    }

    private func focusFieldWhenFree() {
        if !model.isLocked, !hasNoRepositories, !isDrawerOpen { isFieldFocused = true }
    }

    // MARK: Middle

    private var header: some View {
        VStack(spacing: 6) {
            Text("newThread.title").font(.title2.bold()).multilineTextAlignment(.center)
                .fixedSize(horizontal: false, vertical: true).accessibilityAddTraits(.isHeader)
            Text("newThread.subtitle").font(.subheadline).foregroundStyle(Color.yakMuted)
                .multilineTextAlignment(.center).fixedSize(horizontal: false, vertical: true)
        }
        .padding(.top, hasNoRepositories ? 0 : 8)
    }

    @ViewBuilder private var middle: some View {
        if hasNoRepositories {
            emptyState
        } else {
            switch model.load {
            case .slow: slowCard
            case .failed(let problem) where problem != .offline: failedCard(problem)
            default: EmptyView()
            }
        }
    }

    private var emptyState: some View {
        VStack(spacing: 8) {
            Image(systemName: "shippingbox").font(.largeTitle).foregroundStyle(Color.yakMuted)
                .accessibilityHidden(true).padding(.bottom, 4)
            Text("newThread.empty.title").font(.headline)
            Text("newThread.empty.body").font(.subheadline).foregroundStyle(Color.yakMuted)
                .multilineTextAlignment(.center).fixedSize(horizontal: false, vertical: true)
            Button(action: openWeb) {
                Text("newThread.empty.openWeb").fixedSize(horizontal: false, vertical: true)
            }
            .buttonStyle(.bordered).tint(.primary).controlSize(.large).padding(.top, 8)
            .accessibilityIdentifier("newThread.openWeb")
        }
        .padding(.top, 24).frame(maxWidth: .infinity)
        .accessibilityElement(children: .contain).accessibilityIdentifier("newThread.empty")
    }

    private var slowCard: some View {
        VStack(alignment: .leading, spacing: 12) {
            VStack(alignment: .leading, spacing: 4) {
                Text("load.slow.title").font(.headline).fixedSize(horizontal: false, vertical: true)
                Text("newThread.slow.body").font(.subheadline).foregroundStyle(Color.yakMuted)
                    .fixedSize(horizontal: false, vertical: true)
            }
            retryButton
        }
        .padding(16).frame(maxWidth: .infinity, alignment: .leading)
        .background(Color.primary.opacity(0.05), in: .rect(cornerRadius: 18))
        .accessibilityElement(children: .contain).accessibilityIdentifier("newThread.slow")
    }

    private func failedCard(_ problem: ThreadProblem) -> some View {
        VStack(spacing: 12) {
            Image(systemName: "exclamationmark.triangle").font(.title).foregroundStyle(Color.yakMuted)
                .accessibilityHidden(true)
            Text("newThread.failed").multilineTextAlignment(.center).fixedSize(horizontal: false, vertical: true)
            Text("newThread.failed.keep").font(.subheadline).foregroundStyle(Color.yakMuted)
                .multilineTextAlignment(.center).fixedSize(horizontal: false, vertical: true)
            retryButton
        }
        .padding(16).frame(maxWidth: .infinity)
        .accessibilityElement(children: .contain).accessibilityIdentifier("newThread.failed")
    }

    private var retryButton: some View {
        Button {
            Task { await model.retry() }
        } label: {
            Text("common.retry").fixedSize(horizontal: false, vertical: true)
        }
        .buttonStyle(.bordered).tint(.primary).controlSize(.large)
        .accessibilityIdentifier("newThread.retry")
    }

    // MARK: Bottom

    private var bottom: some View {
        VStack(spacing: 8) {
            if isOffline && model.pending == nil { offlineNotice }
            if model.isOffline && model.options == nil && model.pending == nil { retryButton }
            if let pending = model.pending { PendingRow(model: model, item: pending) }
            if let line = chipProblem { problemLine(line) }
            chips
            VStack(spacing: 6) {
                if !model.attachments.isEmpty { NewThreadAttachmentStrip(model: model) }
                if let failure = model.attachmentFailure { problemLine(Self.attachmentText(failure)) }
                composer
            }
            .padding(.horizontal, 10).padding(.vertical, 8)
            .background { BarSurface() }
            .accessibilityElement(children: .contain).accessibilityIdentifier("newThread.bar")
            if let line = textProblem { problemLine(line) }
            if let failure = model.sendFailure { problemLine(Self.sendFailureText(failure)) }
        }
        .padding(.horizontal, 8).padding(.bottom, 4)
        .frame(maxWidth: 560).frame(maxWidth: .infinity)
        .animation(.smooth(duration: 0.25), value: model.pending == nil)
    }

    private var offlineNotice: some View {
        Label {
            Text("newThread.offline").font(.footnote).fixedSize(horizontal: false, vertical: true)
        } icon: {
            Image(systemName: "wifi.slash").accessibilityHidden(true)
        }
        .foregroundStyle(Color.yakMuted)
        .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 12).padding(.vertical, 4)
        .accessibilityElement(children: .combine).accessibilityIdentifier("newThread.offline")
    }

    private func problemLine(_ text: String) -> some View {
        Label {
            Text(text).font(.footnote).fixedSize(horizontal: false, vertical: true)
        } icon: {
            Image(systemName: "exclamationmark.circle.fill").accessibilityHidden(true)
        }
        .foregroundStyle(Color.yakDanger)
        .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 12)
        .accessibilityElement(children: .combine).accessibilityIdentifier("newThread.problem")
    }

    private var chipProblem: String? {
        guard let problem = model.problem, problem != .noText else { return nil }
        return Self.text(for: problem)
    }

    private var textProblem: String? {
        model.problem == .noText ? Self.text(for: .noText) : nil
    }

    // MARK: Chips

    @ViewBuilder private var chips: some View {
        if model.load == .loading || (model.load == .slow && model.options == nil) {
            if model.form.repo == nil || model.options == nil { LoadingChips() } else { chipRow }
        } else {
            chipRow
        }
    }

    private var chipRow: some View {
        let content = Group {
            repoChip
            modeChip
            modelChip
        }
        return Group {
            if dynamicTypeSize.isAccessibilitySize {
                VStack(alignment: .leading, spacing: 8) { content }.frame(maxWidth: .infinity, alignment: .leading)
            } else {
                ScrollView(.horizontal) { HStack(spacing: 8) { content }.padding(.horizontal, 4) }
                    .scrollIndicators(.hidden)
            }
        }
        .tint(.primary)
        .disabled(model.isLocked)
        .accessibilityElement(children: .contain)
    }

    private var repoChip: some View {
        Menu {
            if let options = model.options {
                ForEach(options.repos, id: \.slug) { repository in
                    Button {
                        model.selectRepository(repository.slug)
                    } label: {
                        if repository.slug == model.form.repo { Image(systemName: "checkmark") }
                        Text(repository.name)
                        if repository.setupStatus != "ready" {
                            Text(Self.setupText(repository.setupStep))
                        }
                    }
                }
            }
        } label: {
            ChipLabel(
                systemImage: "shippingbox",
                text: model.form.repo.map { Text(verbatim: Self.repositoryName(model.options)($0)) }
                    ?? Text("newThread.repo.pick"))
        }
        .disabled(model.options == nil)
        .accessibilityLabel(Text("newThread.repo.label"))
        .accessibilityValue(model.form.repo.map(Self.repositoryName(model.options)) ?? "")
        .accessibilityIdentifier("newThread.repo")
    }

    private var modeChip: some View {
        Menu {
            Picker("newThread.mode.label", selection: $model.form.mode) {
                Text("newThread.mode.fix").tag(NewThreadMode.fix)
                Text("newThread.mode.research").tag(NewThreadMode.research)
            }
        } label: {
            ChipLabel(
                systemImage: model.form.mode == .fix ? "wrench.and.screwdriver" : "magnifyingglass",
                text: model.form.mode == .fix ? Text("newThread.mode.fix") : Text("newThread.mode.research"))
        }
        .accessibilityLabel(Text("newThread.mode.label"))
        .accessibilityIdentifier("newThread.mode")
    }

    private var modelChip: some View {
        Button {
            isFieldFocused = false
            showsModels = true
        } label: {
            ChipLabel(systemImage: "cpu", text: modelText)
        }
        .disabled(model.options == nil)
        .accessibilityLabel(Text("newThread.model.label"))
        .accessibilityValue(modelText)
        .accessibilityIdentifier("newThread.model")
    }

    private var modelText: Text {
        guard let value = model.form.model else { return Text("newThread.model.default") }
        return Text(verbatim: ModelChoices.label(for: value, in: model.options?.models ?? []) ?? value)
    }

    // MARK: Composer

    @State private var showsPhotos = false
    @State private var showsFiles = false
    @State private var pickedPhotos: [PhotosPickerItem] = []

    private var composer: some View {
        Group {
            if dynamicTypeSize.isAccessibilitySize {
                VStack(alignment: .leading, spacing: 4) {
                    field
                    HStack {
                        plusMenu
                        Spacer(minLength: 0)
                        sendButton
                    }
                }
            } else {
                HStack(alignment: .bottom, spacing: 8) {
                    plusMenu
                    field
                    sendButton
                }
            }
        }
        .photosPicker(isPresented: $showsPhotos, selection: $pickedPhotos, maxSelectionCount: 8, matching: .images)
        .onChange(of: pickedPhotos) { importPhotos() }
        .fileImporter(isPresented: $showsFiles, allowedContentTypes: [.item], allowsMultipleSelection: true) {
            importFiles($0)
        }
    }

    private var plusMenu: some View {
        Menu {
            Button {
                showsPhotos = true
            } label: {
                Label("newThread.plus.photo", systemImage: "photo")
            }
            Button {
                showsFiles = true
            } label: {
                Label("newThread.plus.file", systemImage: "doc")
            }
        } label: {
            Image(systemName: "plus").font(.title3).frame(minWidth: 44, minHeight: 44).contentShape(.rect)
        }
        .tint(.primary).disabled(model.isLocked)
        .accessibilityLabel("newThread.plus").accessibilityIdentifier("newThread.plus")
    }

    private var field: some View {
        TextField("newThread.placeholder", text: $model.text, axis: .vertical)
            .lineLimit(1...6).focused($isFieldFocused).disabled(model.isLocked)
            .frame(minHeight: 44, alignment: .center)
            .accessibilityIdentifier("newThread.field")
    }

    private var sendButton: some View {
        Button {
            Task { await model.send() }
        } label: {
            Group {
                if model.isSending {
                    ProgressView().controlSize(.small)
                } else {
                    Image(systemName: "arrow.up").font(.system(size: 17, weight: .bold))
                }
            }
            .frame(width: 36, height: 36)
            .foregroundStyle(Color(.systemBackground))
            .background(Color.primary, in: .circle)
            .frame(minWidth: 44, minHeight: 44).contentShape(.rect)
        }
        .disabled(model.isLocked)
        .opacity(model.isLocked ? 0.4 : 1)
        .keyboardShortcut(.return, modifiers: .command)
        .accessibilityLabel("newThread.send").accessibilityIdentifier("newThread.send")
        .accessibilityShowsLargeContentViewer { Label("newThread.send", systemImage: "arrow.up") }
    }

    private func importPhotos() {
        let items = pickedPhotos
        pickedPhotos = []
        guard !items.isEmpty else { return }
        Task {
            for item in items {
                let type = item.supportedContentTypes.first
                guard let data = try? await item.loadTransferable(type: Data.self) else {
                    model.reportUnreadableAttachment()
                    continue
                }
                let ext = type?.preferredFilenameExtension ?? "jpg"
                await model.attach(
                    data, fileName: "photo.\(ext)", contentType: type?.preferredMIMEType ?? "image/jpeg", isImage: true)
            }
        }
    }

    private func importFiles(_ result: Result<[URL], any Error>) {
        guard case .success(let urls) = result else {
            model.reportUnreadableAttachment()
            return
        }
        Task {
            for url in urls {
                let opened = url.startAccessingSecurityScopedResource()
                defer { if opened { url.stopAccessingSecurityScopedResource() } }
                let data = await Task.detached { try? Data(contentsOf: url) }.value
                guard let data else {
                    model.reportUnreadableAttachment()
                    continue
                }
                let type = UTType(filenameExtension: url.pathExtension)
                await model.attach(
                    data, fileName: url.lastPathComponent,
                    contentType: type?.preferredMIMEType ?? "application/octet-stream",
                    isImage: type?.conforms(to: .image) ?? false)
            }
        }
    }

    // MARK: Toolbar

    private var threadsButton: some View {
        ThreadsButton(needsYouCount: needsYouCount, action: openDrawer)
    }

    private var moreButton: some View {
        Button {
            isFieldFocused = false
            showsMore = true
        } label: {
            Image(systemName: "ellipsis").frame(minWidth: 44, minHeight: 44).contentShape(.rect)
        }
        .accessibilityLabel("newThread.more").accessibilityIdentifier("newThread.more")
    }

    // MARK: Copy

    static func repositoryName(_ options: NewThreadOptions?) -> (String) -> String {
        { slug in options?.repos.first { $0.slug == slug }?.name ?? slug }
    }

    static func setupText(_ step: String?) -> String {
        step.map { String(localized: "newThread.setting \($0)") } ?? String(localized: "newThread.settingUp")
    }

    static func text(for problem: NewThreadProblem) -> String {
        switch problem {
        case .noText: String(localized: "newThread.problem.noText")
        case .noRepository: String(localized: "newThread.problem.noRepository")
        case .optionsNotLoaded: String(localized: "newThread.problem.notLoaded")
        case .repositoryNotReady(let slug, let step?):
            String(localized: "newThread.problem.notReady \(slug) \(step)")
        case .repositoryNotReady(let slug, nil): String(localized: "newThread.problem.notReadyNoStep \(slug)")
        case .accountUnusable(let name): String(localized: "newThread.problem.account \(name)")
        case .extraRepositoryRepeated(let slug): String(localized: "newThread.problem.repeated \(slug)")
        case .extraRepositoryRefused(let slug, let reason?):
            String(localized: "newThread.problem.refused \(slug) \(reason)")
        case .extraRepositoryRefused(let slug, nil): String(localized: "newThread.problem.refusedNoReason \(slug)")
        }
    }

    static func attachmentText(_ failure: NewThreadModel.AttachmentFailure) -> String {
        switch failure {
        case .tooMany(let maximum): String(localized: "newThread.attachment.tooMany \(maximum)")
        case .tooLarge(let bytes):
            String(
                localized:
                    "newThread.attachment.tooLarge \(ByteCountFormatter.string(fromByteCount: Int64(bytes), countStyle: .file))"
            )
        case .unreadable: String(localized: "newThread.attachment.unreadable")
        case .notSaved: String(localized: "newThread.attachment.notSaved")
        }
    }

    static func sendFailureText(_ failure: SendProblem) -> String {
        switch failure {
        case .notActive: String(localized: "newThread.send.notActive")
        case .notSaved: String(localized: "newThread.send.notSaved")
        }
    }
}

/// A chip above the composer: an icon and a short value, at least 44 points tall.
private struct ChipLabel: View {
    let systemImage: String
    let text: Text

    var body: some View {
        HStack(spacing: 6) {
            Image(systemName: systemImage).font(.footnote).accessibilityHidden(true)
            text.font(.subheadline.weight(.medium)).lineLimit(1)
            Image(systemName: "chevron.down").font(.caption2.weight(.bold)).foregroundStyle(Color.yakMuted)
                .accessibilityHidden(true)
        }
        .foregroundStyle(.primary)
        .padding(.horizontal, 12).frame(minHeight: 44)
        .background(Color(.tertiarySystemFill), in: .capsule)
        .contentShape(.capsule)
    }
}

/// The chips as shapes: nothing for the first 300 ms, then three capsules where the chips will be.
private struct LoadingChips: View {
    @State private var isShown = false
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        HStack(spacing: 8) {
            ForEach([112, 76, 96], id: \.self) { width in
                Capsule().fill(Color(.tertiarySystemFill)).frame(width: CGFloat(width), height: 44)
            }
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 4)
        .opacity(isShown ? 1 : 0)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("newThread.loading").accessibilityIdentifier("newThread.loading")
        .task {
            do { try await Task.sleep(for: .milliseconds(300)) } catch { return }
            withAnimation(reduceMotion ? nil : .easeIn(duration: 0.2)) { isShown = true }
        }
    }
}

/// What the send left in the outbox, until the thread starts or the person takes it back.
private struct PendingRow: View {
    let model: NewThreadModel
    let item: OutboxItem

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            switch item.state {
            case .queued, .sending, .slow:
                HStack(spacing: 8) {
                    ProgressView().controlSize(.small).accessibilityHidden(true)
                    Text("newThread.starting").font(.subheadline.weight(.medium))
                }
                .accessibilityElement(children: .combine).accessibilityIdentifier("newThread.starting")
            case .waiting:
                note("newThread.waiting", identifier: "newThread.waiting")
                buttons(
                    ("newThread.retrySend", "newThread.retrySend", { await model.retrySend() }),
                    ("newThread.remove", "newThread.remove", { await model.remove() }))
            case .refused(let refusal):
                Text("newThread.refused \(refusal.message)").font(.subheadline).foregroundStyle(Color.yakDanger)
                    .fixedSize(horizontal: false, vertical: true).accessibilityIdentifier("newThread.refused")
                buttons(
                    ("newThread.edit", "newThread.edit", { await model.edit() }),
                    ("newThread.remove", "newThread.remove", { await model.remove() }))
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.horizontal, 12).padding(.vertical, 8)
        .background(Color.primary.opacity(0.05), in: .rect(cornerRadius: 18))
        .accessibilityElement(children: .contain)
    }

    private typealias Choice = (key: LocalizedStringKey, identifier: String, run: () async -> Void)

    /// Two actions side by side, or one above the other when the text size leaves no room.
    private func buttons(_ first: Choice, _ second: Choice) -> some View {
        ViewThatFits(in: .horizontal) {
            HStack {
                action(first.key, identifier: first.identifier, run: first.run)
                action(second.key, identifier: second.identifier, run: second.run)
            }
            VStack(alignment: .leading) {
                action(first.key, identifier: first.identifier, run: first.run)
                action(second.key, identifier: second.identifier, run: second.run)
            }
        }
    }

    private func note(_ key: LocalizedStringKey, identifier: String) -> some View {
        Text(key).font(.subheadline).fixedSize(horizontal: false, vertical: true).accessibilityIdentifier(identifier)
    }

    private func action(_ key: LocalizedStringKey, identifier: String, run: @escaping () async -> Void)
        -> some View
    {
        Button {
            Task { await run() }
        } label: {
            Text(key).fixedSize(horizontal: false, vertical: true)
        }
        .buttonStyle(.bordered).tint(.primary).accessibilityIdentifier(identifier)
    }
}

/// The attachments of the draft, each with a remove button.
private struct NewThreadAttachmentStrip: View {
    let model: NewThreadModel

    var body: some View {
        ScrollView(.horizontal) {
            HStack(spacing: 8) {
                ForEach(model.attachments, id: \.path) { attachment in
                    HStack(spacing: 6) {
                        Image(systemName: attachment.contentType.hasPrefix("image/") ? "photo" : "doc")
                            .accessibilityHidden(true)
                        Text(attachment.fileName).font(.footnote).lineLimit(1)
                        Button {
                            Task { await model.removeAttachment(attachment) }
                        } label: {
                            Image(systemName: "xmark.circle.fill").frame(minWidth: 44, minHeight: 44)
                                .contentShape(.rect)
                        }
                        .disabled(model.isLocked)
                        .accessibilityLabel(Text("newThread.attachment.remove \(attachment.fileName)"))
                    }
                    .padding(.leading, 10)
                    .background(Color.primary.opacity(0.08), in: .capsule)
                }
            }
        }
        .scrollIndicators(.hidden)
        .accessibilityIdentifier("newThread.attachments")
    }
}

/// The models of the instance: each family's latest version, older versions under a chevron.
private struct NewThreadModelPicker: View {
    let model: NewThreadModel
    @State private var expanded: Set<String> = []
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            List {
                let rows = ModelChoices.rows(
                    families: model.options?.models ?? [], selected: model.form.model, expanded: expanded)
                ForEach(rows) { row in
                    HStack {
                        Button {
                            model.form.selectModel(row.id)
                            dismiss()
                        } label: {
                            HStack {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(verbatim: row.label)
                                    if let detail = row.detail {
                                        Text(verbatim: detail).font(.footnote).foregroundStyle(Color.yakMuted)
                                    }
                                }
                                Spacer(minLength: 8)
                                if row.isSelected {
                                    Image(systemName: "checkmark").accessibilityHidden(true)
                                }
                            }
                            .frame(minHeight: 44).contentShape(.rect)
                        }
                        .foregroundStyle(.primary)
                        .accessibilityAddTraits(row.isSelected ? .isSelected : [])
                        if case .family(let hasOlder, let isExpanded) = row.kind, hasOlder {
                            Button {
                                withAnimation(.smooth(duration: 0.2)) {
                                    if isExpanded {
                                        expanded.remove(familyID(of: row))
                                    } else {
                                        expanded.insert(familyID(of: row))
                                    }
                                }
                            } label: {
                                Image(systemName: isExpanded ? "chevron.up" : "chevron.down")
                                    .frame(minWidth: 44, minHeight: 44).contentShape(.rect)
                            }
                            .buttonStyle(.borderless)
                            .accessibilityLabel(Text("newThread.model.older"))
                        }
                    }
                    .padding(.leading, row.kind == .pinned ? 16 : 0)
                }
            }
            .navigationTitle("newThread.model.label")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) { Button("common.done") { dismiss() } }
            }
        }
        .presentationDetents([.medium, .large])
        .accessibilityIdentifier("newThread.modelPicker")
    }

    private func familyID(of row: ModelChoiceRow) -> String {
        model.options?.models.first { $0.latest.value == row.id }?.family ?? row.id
    }
}
