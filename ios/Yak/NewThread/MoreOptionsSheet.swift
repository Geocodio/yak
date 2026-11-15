import SwiftUI
import YakKit

/// Extra repositories for the thread, each checked against the person's access, and the account that pays for it.
struct MoreOptionsSheet: View {
    let model: NewThreadModel
    @Environment(\.dismiss) private var dismiss

    private var addable: [NewThreadRepository] {
        let taken = Set(model.form.extraRepositories.map(\.repo) + [model.form.repo].compactMap { $0 })
        return (model.options?.repos ?? []).filter { !taken.contains($0.slug) }
    }

    var body: some View {
        NavigationStack {
            List {
                repositoriesSection
                accountSection
            }
            .navigationTitle("newThread.moreTitle")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("common.done") { dismiss() }.accessibilityIdentifier("newThread.more.done")
                }
            }
        }
        .tint(.primary)
        .presentationDetents([.medium, .large])
        .accessibilityIdentifier("newThread.moreSheet")
    }

    // MARK: Repositories

    private var repositoriesSection: some View {
        Section {
            ForEach(model.form.extraRepositories, id: \.repo) { extra in
                ExtraRepositoryRow(model: model, extra: extra)
            }
            if model.options == nil {
                Text("newThread.more.notLoaded").font(.subheadline).foregroundStyle(Color.yakMuted)
            } else if addable.isEmpty && model.form.extraRepositories.isEmpty {
                Text("newThread.more.none").font(.subheadline).foregroundStyle(Color.yakMuted)
            } else if !addable.isEmpty {
                Menu {
                    ForEach(addable, id: \.slug) { repository in
                        Button(repository.name) { model.addExtraRepository(repository.slug, role: "context") }
                    }
                } label: {
                    Label("newThread.more.add", systemImage: "plus.circle").frame(minHeight: 44)
                }
                .disabled(model.isLocked)
                .accessibilityIdentifier("newThread.more.add")
            }
        } header: {
            Text("newThread.more.repositories")
        } footer: {
            Text("newThread.more.repositoriesFooter")
        }
    }

    // MARK: Account

    @ViewBuilder private var accountSection: some View {
        if let accounts = model.options?.picker.accounts, !accounts.isEmpty {
            Section {
                ForEach(accounts, id: \.id) { account in
                    Button {
                        model.form.selectAccount(account.id)
                    } label: {
                        HStack {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(verbatim: account.name)
                                if !account.usable {
                                    Text(verbatim: account.status).font(.footnote).foregroundStyle(Color.yakMuted)
                                }
                            }
                            Spacer(minLength: 8)
                            if account.id == model.form.accountID {
                                Image(systemName: "checkmark").accessibilityHidden(true)
                            }
                        }
                        .frame(minHeight: 44).contentShape(.rect)
                    }
                    .foregroundStyle(.primary)
                    .disabled(!account.usable || model.isLocked)
                    .accessibilityAddTraits(account.id == model.form.accountID ? .isSelected : [])
                }
            } header: {
                Text("newThread.more.account")
            }
        }
    }
}

private struct ExtraRepositoryRow: View {
    let model: NewThreadModel
    let extra: NewThreadDraft.ExtraRepository

    private var name: String { NewThreadContentCopy.name(of: extra.repo, in: model.options) }

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Text(verbatim: name).font(.body.weight(.medium)).lineLimit(2)
                if model.checkingAccess.contains(extra.repo) {
                    ProgressView().controlSize(.small).accessibilityLabel(Text("newThread.more.checking"))
                }
                Spacer(minLength: 8)
                Picker("newThread.more.role", selection: roleBinding) {
                    Text("newThread.more.changes").tag("changes")
                    Text("newThread.more.context").tag("context")
                }
                .pickerStyle(.menu).labelsHidden().disabled(model.isLocked)
                Button {
                    model.removeExtraRepository(extra.repo)
                } label: {
                    Image(systemName: "minus.circle.fill").foregroundStyle(Color.yakDanger)
                        .frame(minWidth: 44, minHeight: 44).contentShape(.rect)
                }
                .buttonStyle(.borderless).disabled(model.isLocked)
                .accessibilityLabel(Text("newThread.more.remove \(name)"))
            }
            if let access = model.access[extra.repo], !access.allowed {
                Text(access.reason ?? String(localized: "newThread.more.refusedNoReason"))
                    .font(.footnote).foregroundStyle(Color.yakDanger).fixedSize(horizontal: false, vertical: true)
                    .accessibilityIdentifier("newThread.more.refused")
            } else if model.accessCheckFailed.contains(extra.repo) {
                HStack {
                    Text("newThread.more.checkFailed").font(.footnote).foregroundStyle(Color.yakMuted)
                    Button("common.retry") { model.checkAccess(extra.repo) }.font(.footnote)
                        .buttonStyle(.borderless)
                }
            }
        }
        .accessibilityElement(children: .contain)
    }

    /// The role changes by taking the repository out and putting it in again, which asks for the access again.
    private var roleBinding: Binding<String> {
        Binding(
            get: { extra.role },
            set: { role in
                guard role != extra.role else { return }
                model.removeExtraRepository(extra.repo)
                model.addExtraRepository(extra.repo, role: role)
            })
    }
}

enum NewThreadContentCopy {
    static func name(of slug: String, in options: NewThreadOptions?) -> String {
        options?.repos.first { $0.slug == slug }?.name ?? slug
    }
}
