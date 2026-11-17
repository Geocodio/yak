import SwiftUI
import YakKit

/// The sheet that sets who can read the open thread. Only the owner reaches it.
struct ShareSheet: View {
    let session: InstanceSession
    let threadID: String
    let onSaved: @MainActor () async -> Void

    @Environment(\.dismiss) private var dismiss
    @State private var model: ShareModel?
    @State private var detent: PresentationDetent = .large

    var body: some View {
        NavigationStack {
            Group {
                if let model {
                    ShareSheetForm(model: model, close: { dismiss() })
                } else {
                    ProgressView().frame(maxWidth: .infinity, maxHeight: .infinity)
                }
            }
            .toolbar(.hidden, for: .navigationBar)
        }
        .presentationDetents([.medium, .large], selection: $detent)
        .presentationBackground(Color(.systemGroupedBackground))
        .interactiveDismissDisabled(model?.isSaving ?? false)
        .accessibilityIdentifier("share.sheet")
        .task { await run() }
    }

    private func run() async {
        guard let actions = await session.overviewActions() else { return }
        let next = ShareModel(threadID: threadID, actions: actions, clock: SystemClock())
        next.onSaved = onSaved
        model = next
        await next.start()
        while !Task.isCancelled { try? await Task.sleep(for: .seconds(3600)) }
        next.stop()
    }
}

private struct ShareSheetForm: View {
    @Bindable var model: ShareModel
    let close: () -> Void

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                if let problem = model.problem {
                    card {
                        Text(problem).font(.subheadline.weight(.medium)).foregroundStyle(Color.yakWarning)
                            .fixedSize(horizontal: false, vertical: true)
                            .accessibilityIdentifier("share.problem")
                    }
                }
                switch model.load {
                case .ready: choices
                case .loading: skeleton
                case .slow: slow
                case .failed(let problem): failed(problem)
                }
            }
            .padding(.horizontal, 16).padding(.bottom, 24)
        }
        .scrollDismissesKeyboard(.interactively)
        .background(Color(.systemGroupedBackground))
        .safeAreaInset(edge: .top, spacing: 0) {
            SheetHeader(title: "share.title") {
                Button("overview.cancel") { close() }.yakShortcut(.cancel).disabled(model.isSaving)
                    .foregroundStyle(Color.primary).accessibilityIdentifier("share.cancel")
            } confirm: {
                Button {
                    Task { if await model.save() { close() } }
                } label: {
                    if model.isSaving { ProgressView() } else { Text("share.save") }
                }
                .fontWeight(.semibold).foregroundStyle(Color.primary)
                .disabled(!model.canSave).accessibilityIdentifier("share.save")
            }
            .background(Color(.systemGroupedBackground))
        }
        .sensoryFeedback(.error, trigger: model.refusalCount)
    }

    /// A rounded group of rows on the grouped background.
    private func card<Content: View>(@ViewBuilder _ content: () -> Content) -> some View {
        VStack(alignment: .leading, spacing: 0) { content() }
            .padding(.horizontal, 16).padding(.vertical, 4)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Color(.secondarySystemGroupedBackground), in: .rect(cornerRadius: 20))
    }

    // MARK: Choices

    @ViewBuilder private var choices: some View {
        if let form = model.form {
            card {
                modeRow(.onlyMe, "only_me", "share.mode.onlyMe", "share.mode.onlyMe.detail")
                Divider()
                modeRow(.people, "people", "share.mode.people", "share.mode.people.detail")
                Divider()
                modeRow(.everyone, "everyone", "share.mode.everyone", "share.mode.everyone.detail")
            }
            if form.mode == .people { people(form) }
            if !model.secretFiles.isEmpty {
                Text("share.never \(model.secretFiles.joined(separator: ", "))")
                    .font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                    .padding(.horizontal, 16)
                    .accessibilityIdentifier("share.never")
            }
        }
    }

    private func modeRow(_ mode: ShareMode, _ key: String, _ title: LocalizedStringKey, _ detail: LocalizedStringKey)
        -> some View
    {
        let isChosen = model.form?.mode == mode
        return Button {
            model.setMode(mode)
        } label: {
            HStack(alignment: .top, spacing: 12) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(title).foregroundStyle(.primary)
                    Text(detail).font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if isChosen {
                    Image(systemName: "checkmark").foregroundStyle(Color.accentColor).accessibilityHidden(true)
                }
            }
            .padding(.vertical, 8).frame(minHeight: 44).contentShape(.rect)
        }
        .buttonStyle(.plain).disabled(model.isSaving)
        .accessibilityAddTraits(isChosen ? [.isSelected] : [])
        .accessibilityIdentifier("share.mode.\(key)")
    }

    @ViewBuilder private func people(_ form: ShareForm) -> some View {
        card {
            if model.showsSearch {
                TextField("share.search", text: $model.search).textInputAutocapitalization(.never)
                    .autocorrectionDisabled().frame(minHeight: 44).accessibilityIdentifier("share.search")
                Divider()
            }
            if model.hasNobodyElse {
                Text("share.nobody").font(.subheadline.weight(.medium)).foregroundStyle(Color.yakMuted)
                    .frame(minHeight: 44).accessibilityIdentifier("share.nobody")
            }
            let people = model.visiblePeople
            ForEach(Array(people.enumerated()), id: \.element.id) { index, person in
                if index > 0 { Divider() }
                personRow(person, form: form)
            }
        }
        if form.problem == .pickSomeone {
            Text("share.pickSomeone").foregroundStyle(Color.yakWarning).padding(.horizontal, 16)
                .accessibilityIdentifier("share.pickSomeone")
        }
    }

    @ViewBuilder private func personRow(_ person: SharePerson, form: ShareForm) -> some View {
        if form.isSelectable(person) {
            let isPicked = form.selected.contains(person.id)
            Button {
                model.toggle(person.id)
            } label: {
                HStack {
                    Text(person.name).foregroundStyle(.primary).frame(maxWidth: .infinity, alignment: .leading)
                    if isPicked {
                        Image(systemName: "checkmark").foregroundStyle(Color.accentColor).accessibilityHidden(true)
                    }
                }
                .frame(minHeight: 44).contentShape(.rect)
            }
            .buttonStyle(.plain).disabled(model.isSaving)
            .accessibilityAddTraits(isPicked ? [.isSelected] : [])
            .accessibilityIdentifier("share.person.\(person.id.value)")
        } else {
            // The owner always has access, so this row is a fact and not a control.
            HStack {
                Text(person.name).frame(maxWidth: .infinity, alignment: .leading)
                Text("share.owner").font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
            }
            .frame(minHeight: 44)
            .accessibilityElement(children: .combine)
            .accessibilityIdentifier("share.person.\(person.id.value)")
        }
    }

    // MARK: States

    @ViewBuilder private var skeleton: some View {
        if model.showsSkeleton {
            card {
                VStack(spacing: 12) {
                    ForEach(0..<3, id: \.self) { _ in
                        RoundedRectangle(cornerRadius: 5).fill(Color(.tertiarySystemFill)).frame(height: 32)
                    }
                }
                .padding(.vertical, 8)
                .accessibilityElement(children: .ignore).accessibilityLabel("share.loading")
                .accessibilityIdentifier("share.loading")
            }
        }
    }

    private var slow: some View {
        card {
            Text("share.slow").font(.subheadline.weight(.medium)).foregroundStyle(Color.yakMuted)
            Button("common.retry") { Task { await model.retry() } }.frame(minHeight: 44)
                .accessibilityIdentifier("share.retry")
        }
        .accessibilityIdentifier("share.slow")
    }

    private func failed(_ problem: ThreadProblem) -> some View {
        card {
            Text(problem == .offline ? "share.offline" : "share.loadFailed").font(.subheadline.weight(.medium))
                .fixedSize(horizontal: false, vertical: true)
            if case .server(_, let message) = problem, !message.isEmpty {
                Text(message).font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
            }
            Button("common.retry") { Task { await model.retry() } }.frame(minHeight: 44)
                .accessibilityIdentifier("share.retry")
        }
        .accessibilityIdentifier("share.error")
    }
}
