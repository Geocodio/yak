import Foundation

extension StubControl {
    /// Lets the held answer of the `search-slow` scenario go.
    func releaseSearch() throws { try post("search/release", "") }

    /// Makes `search-failing` and `search-limited` answer again.
    func relentSearch() throws { try post("search/relent", "") }

    /// The queries the search route answered, one per line.
    func searchLog() -> [String] {
        ((try? get("search/log")) ?? "").split(separator: "\n").map(String.init)
    }
}
