public enum NewThreadMode: String, Codable, CaseIterable, Sendable { case fix, research }

public enum NewThreadProblem: Equatable, Sendable {
    case noText, noRepository, optionsNotLoaded
    case repositoryNotReady(slug: String, step: String?)
    case accountUnusable(name: String)
    case extraRepositoryRefused(slug: String, reason: String?)
    case extraRepositoryRepeated(slug: String)
}

/// The choices on the new-thread screen and the rules that fill and check them.
public struct NewThreadForm: Equatable, Sendable {
    public private(set) var repo: String?
    public var mode: NewThreadMode
    public private(set) var model: String?
    public private(set) var effort: EffortLevel?
    public private(set) var accountID: LenientID?
    public private(set) var extraRepositories: [NewThreadDraft.ExtraRepository]

    private var hasPickedAccount: Bool
    private var hasPickedModel: Bool
    private var hasPickedEffort = false
    private let hasSavedRepository: Bool

    public init(options: NewThreadOptions?, saved: NewThreadDraft?, lastUsedRepo: String?, linkRepo: String?) {
        let candidates = [linkRepo, saved?.repo, lastUsedRepo, options?.defaultRepo, options?.repos.first?.slug]
            .compactMap { $0 }
        if let options {
            repo = candidates.first { slug in options.repos.contains { $0.slug == slug } }
        } else {
            repo = candidates.first
        }
        hasSavedRepository = saved != nil
        mode = saved.flatMap { NewThreadMode(rawValue: $0.mode) } ?? .fix
        extraRepositories = saved?.repositories ?? []
        if let saved {
            accountID = saved.claudeAccountId.map { LenientID(String($0)) }
            model = saved.model
            effort = saved.effort
            hasPickedEffort = saved.effort != nil
            hasPickedAccount = saved.claudeAccountId != nil
            hasPickedModel = saved.model != nil
        } else {
            hasPickedAccount = false
            hasPickedModel = false
        }
        if let options, let id = accountID, !options.picker.accounts.contains(where: { $0.id == id }) {
            accountID = nil
            hasPickedAccount = false
        }
        if let repo, let options, saved == nil || !hasPickedAccount { applyDefaults(for: repo, options: options) }
    }

    /// The account and model the repository starts with, for the choices the person did not make.
    private mutating func applyDefaults(for slug: String, options: NewThreadOptions) {
        let defaults = options.picker.defaultsByRepo[slug]
        let accounts = options.picker.accounts
        if !hasPickedAccount {
            if let id = defaults?.accountId, accounts.contains(where: { $0.id == id && $0.usable }) {
                accountID = id
            } else {
                accountID = accounts.first(where: \.isDefault)?.id
            }
        }
        if !hasPickedModel {
            model = defaults?.model ?? accounts.first { $0.id == accountID }?.defaultModel
        }
        if !hasPickedEffort {
            effort = defaults?.effort ?? accounts.first { $0.id == accountID }?.defaultEffort
        }
    }

    public mutating func selectRepository(_ slug: String, options: NewThreadOptions) {
        repo = slug
        extraRepositories.removeAll { $0.repo == slug }
        applyDefaults(for: slug, options: options)
    }

    /// Lowers the effort to the highest level the new model supports, or clears it when there is none.
    public mutating func selectModel(_ value: String?, families: [ModelFamily] = []) {
        model = value
        hasPickedModel = true
        if let current = effort {
            effort = current.clamped(to: ModelChoices.efforts(for: value, in: families))
        }
    }

    public mutating func selectEffort(_ value: EffortLevel?) {
        effort = value
        hasPickedEffort = true
    }

    public mutating func selectAccount(_ id: LenientID?) {
        accountID = id
        hasPickedAccount = true
    }

    /// `role` is "changes" or "context". Adding a repository that is already listed adds it again, and `problem` reports `.extraRepositoryRepeated`.
    public mutating func addExtraRepository(_ slug: String, role: String) {
        extraRepositories.append(NewThreadDraft.ExtraRepository(repo: slug, role: role))
    }

    public mutating func removeExtraRepository(_ slug: String) {
        extraRepositories.removeAll { $0.repo == slug }
    }

    /// The first thing that stops the send, or nil. Without options (offline) only the text, a saved
    /// repository and the repository itself are checked, so a saved draft can still be sent.
    public func problem(text: String, options: NewThreadOptions?, access: [String: RepositoryAccess])
        -> NewThreadProblem?
    {
        if text.allSatisfy(\.isWhitespace) { return .noText }
        guard let options else {
            if !hasSavedRepository { return .optionsNotLoaded }
            return repo == nil ? .noRepository : nil
        }
        guard let repo, let repository = options.repos.first(where: { $0.slug == repo }) else { return .noRepository }
        if repository.setupStatus != "ready" {
            return .repositoryNotReady(slug: repo, step: repository.setupStep)
        }
        if let accountID, let account = options.picker.accounts.first(where: { $0.id == accountID }), !account.usable {
            return .accountUnusable(name: account.name)
        }
        var seen: Set<String> = [repo]
        for extra in extraRepositories {
            if !seen.insert(extra.repo).inserted { return .extraRepositoryRepeated(slug: extra.repo) }
        }
        for extra in extraRepositories where access[extra.repo]?.allowed == false {
            return .extraRepositoryRefused(slug: extra.repo, reason: access[extra.repo]?.reason)
        }
        return nil
    }

    /// Nil without a repository. An account id that is not a whole number is sent as none.
    public var draft: NewThreadDraft? {
        guard let repo else { return nil }
        return NewThreadDraft(
            repo: repo, mode: mode.rawValue, repositories: extraRepositories,
            claudeAccountId: accountID.flatMap { Int($0.value) }, model: model, effort: effort)
    }
}
