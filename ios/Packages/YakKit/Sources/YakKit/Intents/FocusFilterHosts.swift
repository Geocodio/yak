import Foundation

public enum FocusFilterHosts {
    /// nil: no filter (no choice made). Otherwise the lowercase hosts of the chosen, still paired instances:
    /// the paired address and the server's own address (`canonicalURL`). An empty choice allows nothing.
    public static func allowed(instances: [Instance], chosen: [UUID]?) -> Set<String>? {
        guard let chosen else { return nil }
        let wanted = Set(chosen)
        var hosts: Set<String> = []
        for instance in instances where wanted.contains(instance.id) {
            for url in [instance.url, instance.canonicalURL] {
                if let host = url?.host(percentEncoded: false)?.lowercased(), !host.isEmpty { hosts.insert(host) }
            }
        }
        return hosts
    }
}
