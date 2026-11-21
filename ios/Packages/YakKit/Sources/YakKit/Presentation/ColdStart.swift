public enum ColdStartDestination: Equatable, Sendable {
    case thread(String)
    case drawer(ThreadListFilter)
    case newThread
}

public enum ColdStart {
    /// `mine` is the list for filter `.mine` without a search (cached or fresh). A `.task` or `.pair` link does
    /// not change the destination; the shell handles it.
    public static func destination(mine list: ThreadList, restoredThreadID: String?, link: DeepLink?)
        -> ColdStartDestination
    {
        switch link {
        case .thread(_, let id, _): return .thread(id)
        case .newThread: return .newThread
        default: break
        }
        if list.needsYou.count == 1, case .thread(let row) = list.needsYou[0] { return .thread(row.id) }
        if !list.needsYou.isEmpty { return .drawer(.mine) }
        if let restoredThreadID { return .thread(restoredThreadID) }
        if !list.starred.isEmpty || !list.threads.isEmpty { return .drawer(.mine) }
        return list.hasAnyThreads ? .drawer(.everyone) : .newThread
    }
}
