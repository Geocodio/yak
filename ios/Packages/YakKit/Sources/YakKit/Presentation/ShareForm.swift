import Foundation

public enum ShareFormProblem: Equatable, Sendable { case pickSomeone, unknownMode }

public struct ShareForm: Equatable, Sendable {
    public var mode: ShareMode
    public let people: [SharePerson]
    public private(set) var selected: Set<LenientID>
    private let original: ThreadShare

    public init(share: ThreadShare) {
        mode = share.mode
        people = share.people
        selected = Set(share.userIds)
        original = share
    }

    /// The owner always has access, so the owner's row cannot be unpicked.
    public func isSelectable(_ person: SharePerson) -> Bool { person.role != "Owner" }

    public mutating func toggle(_ id: LenientID) {
        guard let person = people.first(where: { $0.id == id }), isSelectable(person) else { return }
        if selected.contains(id) { selected.remove(id) } else { selected.insert(id) }
    }

    public func filtered(by search: String) -> [SharePerson] {
        let term = search.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !term.isEmpty else { return people }
        return people.filter {
            $0.name.range(of: term, options: [.caseInsensitive, .diacriticInsensitive]) != nil
        }
    }

    public var hasChanges: Bool {
        if mode != original.mode { return true }
        return mode == .people && selected != Set(original.userIds)
    }

    public var problem: ShareFormProblem? {
        guard [ShareMode.onlyMe, .people, .everyone].contains(mode) else { return .unknownMode }
        return mode == .people && selected.isEmpty ? .pickSomeone : nil
    }

    public var canSave: Bool { hasChanges && problem == nil }

    public var request: (mode: ShareMode, userIDs: [Int]) {
        guard mode == .people else { return (mode, []) }
        return (mode, selected.compactMap { Int($0.value) }.sorted())
    }
}
