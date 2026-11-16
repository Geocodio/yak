import Foundation

public struct MessageAttachmentRow: Equatable, Sendable, Identifiable {
    public enum Kind: Equatable, Sendable { case image, file }
    public let id: String
    public let kind: Kind
    /// The name, cut to 120 characters keeping the extension; an empty name reads "File".
    public let title: String
    /// "File #2, 1.2 MB", or the size alone.
    public let detail: String
    public let reference: String?
    /// Nil when the link is not one the app may open.
    public let openURL: URL?

    public init(id: String, kind: Kind, title: String, detail: String, reference: String?, openURL: URL?) {
        self.id = id
        self.kind = kind
        self.title = title
        self.detail = detail
        self.reference = reference
        self.openURL = openURL
    }
}

public enum MessageAttachmentRows {
    public static let maximumShown = 20
    static let maximumTitleLength = 120

    /// Images first, then files, each in the server's order; at most `maximumShown` rows.
    public static func make(_ attachments: [MessageAttachment], instance: URL, canonical: URL?)
        -> (rows: [MessageAttachmentRow], hiddenCount: Int)
    {
        let ordered = attachments.filter(\.isImage) + attachments.filter { !$0.isImage }
        let rows = ordered.prefix(maximumShown).map { attachment in
            let link = URL(string: attachment.url)
            let reference = attachment.reference?.isEmpty == false ? attachment.reference : nil
            let size = ByteCountFormatter.string(fromByteCount: Int64(max(0, attachment.size)), countStyle: .file)
            return MessageAttachmentRow(
                id: attachment.id.value, kind: attachment.isImage ? .image : .file,
                title: title(attachment.name), detail: reference.map { "\($0), \(size)" } ?? size,
                reference: reference,
                openURL: link.flatMap { isAppSafe($0, instance: instance, canonical: canonical) ? $0 : nil })
        }
        return (Array(rows), max(0, ordered.count - maximumShown))
    }

    /// True for a signed link on the instance's own address (or its canonical one) under `/app-media/`.
    public static func isAppSafe(_ link: URL, instance: URL, canonical: URL?) -> Bool {
        guard let scheme = link.scheme?.lowercased(), let host = link.host?.lowercased(),
            scheme == "https" || (scheme == "http" && instance.scheme?.lowercased() == "http"),
            link.path.hasPrefix("/app-media/"), !link.pathComponents.contains(".."),
            !link.absoluteString.lowercased().contains("%2e"),
            URLComponents(url: link, resolvingAgainstBaseURL: false)?.queryItems?
                .contains(where: { $0.name == "signature" && ($0.value?.isEmpty == false) }) == true
        else { return false }
        let port = link.port ?? defaultPort(scheme)
        return [instance, canonical].contains { candidate in
            guard let candidate, let candidateHost = candidate.host?.lowercased() else { return false }
            return candidateHost == host && (candidate.port ?? defaultPort(candidate.scheme?.lowercased())) == port
        }
    }

    private static func defaultPort(_ scheme: String?) -> Int? {
        switch scheme {
        case "https": 443
        case "http": 80
        default: nil
        }
    }

    private static func title(_ name: String) -> String {
        guard !name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { return "File" }
        guard name.count > maximumTitleLength else { return name }
        let fileExtension = (name as NSString).pathExtension
        guard !fileExtension.isEmpty, fileExtension.count < 20 else { return String(name.prefix(maximumTitleLength)) }
        return String(name.prefix(maximumTitleLength - fileExtension.count - 1)) + "." + fileExtension
    }
}
