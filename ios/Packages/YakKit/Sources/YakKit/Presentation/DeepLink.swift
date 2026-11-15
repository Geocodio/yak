import Foundation

public enum DeepLink: Equatable, Sendable {
    case pair(PairingLink)
    case thread(instance: URL, id: String)
    case task(instance: URL, id: String)
    case newThread(instance: URL, repo: String?, text: String?)

    /// `InstanceAddress.normalize`, but only for an address typed with https (a link never downgrades).
    public static func normalizeHTTPS(_ input: String) -> URL? {
        input.lowercased().hasPrefix("https://") ? InstanceAddress.normalize(input) : nil
    }

    /// `normalize` is `normalizeHTTPS` in Release; the app's Debug test mode passes its relaxed one.
    public init?(url: URL, normalize: (String) -> URL? = DeepLink.normalizeHTTPS) {
        guard url.scheme?.lowercased() == "yak", let kind = url.host?.lowercased() else { return nil }
        if kind == "pair" {
            guard let link = PairingLink(url: url) else { return nil }
            self = .pair(link)
            return
        }
        let items = URLComponents(url: url, resolvingAgainstBaseURL: false)?.queryItems ?? []
        func value(_ name: String) -> String? { items.first { $0.name == name }?.value }
        guard let address = value("instance"), let instance = normalize(address) else { return nil }
        switch kind {
        case "thread", "task":
            guard let id = value("id"), Self.isValidID(id) else { return nil }
            self = kind == "thread" ? .thread(instance: instance, id: id) : .task(instance: instance, id: id)
        case "new":
            let repo = value("repo").flatMap { Self.isValidRepo($0) ? $0 : nil }
            let trimmed = value("text")?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
            self = .newThread(
                instance: instance, repo: repo, text: trimmed.isEmpty ? nil : String(trimmed.prefix(4000)))
        default: return nil
        }
    }

    private static func isValidID(_ id: String) -> Bool {
        id.count == 8 && id.allSatisfy { $0.isASCII && ($0.isLetter || $0.isNumber) }
    }

    private static func isValidRepo(_ repo: String) -> Bool {
        let sides = repo.split(separator: "/", omittingEmptySubsequences: false)
        return sides.count == 2
            && sides.allSatisfy {
                !$0.isEmpty && $0.allSatisfy { $0.isASCII && ($0.isLetter || $0.isNumber || "._-".contains($0)) }
            }
    }
}
