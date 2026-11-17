import SwiftUI
import YakKit

/// The sheet that adds a sub-thread to the open thread.
struct AddSubThreadSheet: View {
    let session: InstanceSession
    let parentID: String
    let onOpenThread: (String) -> Void

    @Environment(\.dismiss) private var dismiss
    @Environment(\.scenePhase) private var scenePhase
    @State private var model: AddSubThreadModel?

    var body: some View {
        NavigationStack {
            Group {
                if let model {
                    AddSubThreadForm(model: model, close: { dismiss() }, openThread: onOpenThread)
                } else {
                    ProgressView().frame(maxWidth: .infinity, maxHeight: .infinity)
                }
            }
            .toolbar(.hidden, for: .navigationBar)
        }
        .presentationBackground(Color(.systemGroupedBackground))
        .presentationDetents([.medium, .large])
        .interactiveDismissDisabled(model?.isSending ?? false)
        .accessibilityIdentifier("addSubThread.sheet")
        .task { await run() }
        .onChange(of: scenePhase) { if scenePhase != .active { Task { await model?.flush() } } }
    }

    private func run() async {
        guard let actions = await session.overviewActions() else { return }
        let next = AddSubThreadModel(parentID: parentID, actions: actions, clock: SystemClock())
        model = next
        await next.start()
        while !Task.isCancelled { try? await Task.sleep(for: .seconds(3600)) }
        await Task {
            await next.flush()
            next.stop()
        }.value
    }
}

private struct AddSubThreadForm: View {
    @Bindable var model: AddSubThreadModel
    let close: () -> Void
    let openThread: (String) -> Void

    private enum Field: Hashable { case name, brief }

    @FocusState private var focus: Field?
    @State private var touched: Set<String> = []
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    private var isLocked: Bool { model.isSending || model.isUnsure }

    var body: some View {
        Form {
            if let sentence = model.refusalSentence {
                Section {
                    notice(sentence, identifier: "addSubThread.refused")
                    openParentButton
                }
            }
            Section {
                nameField
                briefField
            }
            Section {
                kindPicker
                modelPicker
                footer
            }
            Section {
                if model.isUnsure {
                    notice(String(localized: "addSubThread.unsure"), identifier: "addSubThread.unsure")
                }
                if let problem = model.problem {
                    notice(problem, identifier: "addSubThread.problem")
                    if model.refusalSentence == nil { openParentButton }
                }
            }
            .listRowBackground(Color.clear)
        }
        .scrollDismissesKeyboard(.interactively)
        .safeAreaInset(edge: .top, spacing: 0) {
            SheetHeader(title: "addSubThread.title") {
                Button("addSubThread.cancel") {
                    Task {
                        await model.flush()
                        close()
                    }
                }
                .yakShortcut(.cancel).disabled(model.isSending)
                .foregroundStyle(Color.primary)
                .accessibilityIdentifier("addSubThread.cancel")
            } confirm: {
                addButton
            }
            .background(Color(.systemGroupedBackground))
        }
        .sensoryFeedback(.error, trigger: model.refusalCount)
        .task {
            try? await Task.sleep(for: .milliseconds(400))
            if !isLocked { focus = .name }
        }
    }

    @ViewBuilder private var openParentButton: some View {
        if model.offersParent, let parent = model.parentToOpen {
            Button("addSubThread.openParent") {
                close()
                openThread(parent)
            }
            .accessibilityIdentifier("addSubThread.openParent")
        }
    }

    private var addButton: some View {
        Button {
            Task { if await model.add() { close() } }
        } label: {
            if model.isSending { ProgressView() } else { Text("addSubThread.add") }
        }
        .yakShortcut(.addSubThread).disabled(!model.canAdd)
        .fontWeight(.semibold).foregroundStyle(Color.primary)
        .accessibilityIdentifier("addSubThread.add")
    }

    // MARK: Fields

    private var nameField: some View {
        VStack(alignment: .leading, spacing: 4) {
            TextField("addSubThread.name.label", text: $model.draft.name, prompt: Text("addSubThread.name.prompt"))
                .focused($focus, equals: .name).submitLabel(.next).onSubmit { focus = .brief }
                .textInputAutocapitalization(.never).autocorrectionDisabled()
                .disabled(isLocked).accessibilityIdentifier("addSubThread.name")
            if model.draft.trimmedName.count >= 50 {
                Text("addSubThread.name.count \(model.draft.trimmedName.count) \(SubThreadDraft.maximumNameLength)")
                    .font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
            }
            error(for: "name", local: [.noName, .nameTooLong])
        }
        .onChange(of: focus) { old, _ in if old == .name { touched.insert("name") } }
    }

    private var briefField: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text("addSubThread.brief.label").font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
            TextField(
                "addSubThread.brief.label", text: $model.draft.brief, prompt: Text("addSubThread.brief.prompt"),
                axis: .vertical
            )
            .lineLimit(3...8).focused($focus, equals: .brief).disabled(isLocked)
            .accessibilityIdentifier("addSubThread.brief")
            error(for: "brief", local: [.noBrief, .briefTooLong])
        }
        .onChange(of: focus) { old, _ in if old == .brief { touched.insert("brief") } }
    }

    @ViewBuilder private func error(for field: String, local: [SubThreadDraftProblem]) -> some View {
        let sentence =
            model.fieldErrors[field]
            ?? (touched.contains(field)
                ? model.draft.problem.flatMap { local.contains($0) ? AddSubThreadModel.sentence(for: $0) : nil } : nil)
        if let sentence {
            Text(sentence).font(.footnote.weight(.medium)).foregroundStyle(Color.yakWarning)
                .fixedSize(horizontal: false, vertical: true)
                .accessibilityIdentifier("addSubThread.\(field).error")
        }
    }

    private var kindOptions: some View {
        Picker("addSubThread.kind.label", selection: $model.draft.kind) {
            Text("addSubThread.kind.implement").tag(StackChildKind.implement)
            Text("addSubThread.kind.research").tag(StackChildKind.research)
            Text("addSubThread.kind.mockup").tag(StackChildKind.mockup)
            Text("addSubThread.kind.general").tag(StackChildKind.general)
        }
        .disabled(isLocked).accessibilityIdentifier("addSubThread.kind")
    }

    private var kindPicker: some View {
        VStack(alignment: .leading, spacing: 6) {
            if dynamicTypeSize.isAccessibilitySize {
                kindOptions.pickerStyle(.menu)
            } else {
                kindOptions.pickerStyle(.segmented)
            }
            if model.draft.kind == .research {
                Text("addSubThread.kind.researchNote").font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                    .fixedSize(horizontal: false, vertical: true)
            }
            error(for: "kind", local: [])
        }
    }

    private var modelPicker: some View {
        let families = model.stack?.models ?? []
        return Menu {
            Picker("addSubThread.model.label", selection: $model.draft.model) {
                Text("addSubThread.model.same").tag(String?.none)
                ForEach(
                    ModelChoices.rows(
                        families: families, selected: model.draft.model, expanded: Set(families.map(\.family)))
                ) { row in
                    Text(row.label).tag(Optional(row.id))
                }
            }
        } label: {
            HStack {
                Text("addSubThread.model.label").foregroundStyle(.primary)
                Spacer(minLength: 8)
                if model.load == .loading, model.showsSkeleton {
                    RoundedRectangle(cornerRadius: 4).fill(Color(.tertiarySystemFill)).frame(width: 80, height: 16)
                        .accessibilityHidden(true)
                } else {
                    Text(model.modelLabel ?? String(localized: "addSubThread.model.same")).foregroundStyle(
                        Color.yakMuted)
                }
                Image(systemName: "chevron.up.chevron.down").font(.footnote).foregroundStyle(Color.yakMuted)
                    .accessibilityHidden(true)
            }
            .frame(minHeight: 44).contentShape(.rect)
        }
        .tint(.primary).disabled(isLocked || families.isEmpty).accessibilityIdentifier("addSubThread.model")
    }

    @ViewBuilder private var footer: some View {
        switch model.load {
        case .ready:
            if let account = model.parentAccountName {
                Text("addSubThread.runsOn \(account)").font(.footnote.weight(.medium)).foregroundStyle(Color.primary)
            }
        case .slow:
            HStack {
                Text("common.slow")
                Button("common.retry") { Task { await model.retry() } }.accessibilityIdentifier("addSubThread.retry")
            }
        case .failed(.offline):
            Text("addSubThread.offline").font(.footnote.weight(.medium)).foregroundStyle(Color.primary)
                .accessibilityIdentifier("addSubThread.offline")
        case .failed:
            HStack {
                Text("addSubThread.stackFailed")
                Button("common.retry") { Task { await model.retry() } }.accessibilityIdentifier("addSubThread.retry")
            }
        case .loading:
            if model.showsSkeleton {
                RoundedRectangle(cornerRadius: 4).fill(Color(.tertiarySystemFill)).frame(width: 140, height: 14)
                    .accessibilityHidden(true)
            }
        }
    }

    private func notice(_ text: String, identifier: String) -> some View {
        Text(text).font(.subheadline.weight(.medium)).foregroundStyle(Color.yakWarning)
            .fixedSize(horizontal: false, vertical: true).frame(maxWidth: .infinity, alignment: .leading)
            .accessibilityIdentifier(identifier)
    }
}
