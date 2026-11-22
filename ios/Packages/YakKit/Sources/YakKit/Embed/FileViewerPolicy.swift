import Foundation

/// What the file viewer's web view may load. The viewer shows one shared file's page of the instance and nothing else:
/// the page, the bytes it frames, and the page's Download link. A tap on any other link leaves the viewer through
/// the same rule as a link of the thread page.
public struct FileViewerPolicy: Equatable, Sendable {
    public enum Decision: Equatable, Sendable {
        /// The web view goes there.
        case allow
        /// Nothing happens.
        case cancel
        /// The viewer stays; the address passed `WebLinkRule` and opens in the app's browser.
        case openLink(URL)
        /// The viewer stays; a link to a thread of this instance opens that thread in the app.
        case openThread(String)
        /// The viewer stays; the file is downloaded through the viewer's session and shown in Quick Look.
        case download(URL)
    }

    /// Why a response was not the file.
    public enum Failure: Equatable, Sendable {
        /// The session is not accepted (401 or 419): a new one may help once.
        case signedOut
        /// The file is not there or not for this person (403, 404, 410).
        case unavailable
        /// Any other status of 400 or more.
        case server(Int)
    }

    public let navigation: EmbedNavigationPolicy

    public init(instanceURL: URL, allowsLoopbackHTTP: Bool = false) {
        navigation = EmbedNavigationPolicy(instanceURL: instanceURL, allowsLoopbackHTTP: allowsLoopbackHTTP)
    }

    /// Whether the viewer may open `url` as the file page.
    public func canShow(_ url: URL) -> Bool { navigation.isSharedFilePage(url) }

    /// `method` is the request's HTTP method; nil reads as GET. `isUserInitiated` is a tap on a link or a form.
    public func decide(url: URL?, method: String?, isMainFrame: Bool, isUserInitiated: Bool) -> Decision {
        guard let url else { return .cancel }
        guard (method ?? "GET").uppercased() == "GET" else { return .cancel }
        if !isMainFrame, Self.isBlank(url) { return .allow }
        if navigation.isSharedFileDownload(url) { return isUserInitiated ? .download(url) : .cancel }
        if !isUserInitiated {
            // The page loads in the main frame, and the page loads its bytes into its frame; nothing else loads by itself.
            if isMainFrame { return navigation.isSharedFilePage(url) ? .allow : .cancel }
            return navigation.isSharedFileRaw(url) ? .allow : .cancel
        }
        if let id = navigation.threadID(in: url) { return .openThread(id) }
        guard let link = WebLinkRule.safeURL(url.absoluteString) else { return .cancel }
        return .openLink(link)
    }

    /// What a response of `status` from `url` means. Any frame counts: a failing file inside the page is as much a
    /// failed file as a failing page, and a server's error page is never shown.
    public func failure(ofResponseFrom url: URL, status: Int) -> Failure? {
        guard status >= 400, navigation.isInstanceOrigin(url) else { return nil }
        switch status {
        case 401, 419: return .signedOut
        case 403, 404, 410: return .unavailable
        default: return .server(status)
        }
    }

    /// The file's name from the page's title (`<name> - Yak`); nil when there is nothing to show.
    public static func name(fromPageTitle title: String?) -> String? {
        guard var name = title?.trimmingCharacters(in: .whitespacesAndNewlines) else { return nil }
        if name.hasSuffix(" - Yak") { name = String(name.dropLast(" - Yak".count)) }
        let trimmed = name.trimmingCharacters(in: .whitespacesAndNewlines)
        return trimmed.isEmpty ? nil : trimmed
    }

    private static func isBlank(_ url: URL) -> Bool {
        url.scheme?.lowercased() == "about"
            && ["blank", "srcdoc"].contains(url.absoluteString.dropFirst(6).lowercased())
    }
}
