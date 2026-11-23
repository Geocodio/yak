import Foundation

/// What the file viewer's web view may load. The viewer shows one shared file's page of the instance and nothing else:
/// that page, the bytes it frames, and the page's Download link, all bound to the id of the address the viewer was
/// opened with. A tap on any other link leaves the viewer through the same rule as a link of the thread page.
///
/// A link in the report itself (the frame, whose script is not ours) may only open the browser sheet, and only after a
/// real touch (see `RecentTouchGate`).
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
        /// The page load went to another page of the instance (the server's login page when the session ended).
        case signedOut
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

    /// What a response does.
    public enum ResponseAction: Equatable, Sendable {
        case allow
        case fail(Failure)
        /// The web view cannot show it, or the server sends it as a download: Quick Look takes it.
        case quickLook(URL)
    }

    public let navigation: EmbedNavigationPolicy
    /// The id of the file the viewer was opened for; no other id is loaded.
    public let fileID: String

    /// Nil unless `fileURL` is the page of a shared file of the instance.
    public init?(fileURL: URL, instanceURL: URL, allowsLoopbackHTTP: Bool = false) {
        let navigation = EmbedNavigationPolicy(instanceURL: instanceURL, allowsLoopbackHTTP: allowsLoopbackHTTP)
        guard navigation.isSharedFilePage(fileURL), let id = navigation.sharedFileID(of: fileURL) else { return nil }
        self.navigation = navigation
        fileID = id
    }

    private func isOwn(_ url: URL, _ matches: (URL) -> Bool) -> Bool {
        matches(url) && navigation.sharedFileID(of: url) == fileID
    }

    public func isOwnPage(_ url: URL) -> Bool { isOwn(url, navigation.isSharedFilePage) }
    public func isOwnRaw(_ url: URL) -> Bool { isOwn(url, navigation.isSharedFileRaw) }
    public func isOwnDownload(_ url: URL) -> Bool { isOwn(url, navigation.isSharedFileDownload) }

    /// `method` is the request's HTTP method; nil reads as GET. `isMainFrame` is the frame the navigation happens in
    /// (the frame of the link for a new window), `isUserInitiated` a tap on a link or a form.
    public func decide(url: URL?, method: String?, isMainFrame: Bool, isUserInitiated: Bool) -> Decision {
        guard let url, (method ?? "GET").uppercased() == "GET" else { return .cancel }
        if !isMainFrame, Self.isBlank(url) { return .allow }
        if !isUserInitiated {
            if !isMainFrame { return isOwnRaw(url) ? .allow : .cancel }
            if isOwnPage(url) { return .allow }
            if isOwnRaw(url) || isOwnDownload(url) { return .cancel }
            // The page load itself went somewhere else on the instance: its login page, when the session ended.
            return navigation.isInstanceOrigin(url) ? .signedOut : .cancel
        }
        if isMainFrame {
            if isOwnDownload(url) { return .download(url) }
            if let id = navigation.threadID(in: url) { return .openThread(id) }
        }
        // The report's script is not ours: a tap there opens the browser sheet and nothing else.
        return WebLinkRule.safeURL(url.absoluteString).map(Decision.openLink) ?? .cancel
    }

    /// What a response from `url` means. A failing status counts for the viewer's own page in the main frame and its
    /// own raw address, nothing else the report loads.
    public func failure(ofResponseFrom url: URL, status: Int, isMainFrame: Bool) -> Failure? {
        guard status >= 400, isMainFrame ? isOwnPage(url) : isOwnRaw(url) else { return nil }
        switch status {
        case 401, 419: return .signedOut
        case 403, 404, 410: return .unavailable
        default: return .server(status)
        }
    }

    /// `contentDisposition` is the response's `Content-Disposition` header.
    public func responseAction(
        url: URL, status: Int, isMainFrame: Bool, canShowMIMEType: Bool, contentDisposition: String?
    ) -> ResponseAction {
        if let failure = failure(ofResponseFrom: url, status: status, isMainFrame: isMainFrame) {
            return .fail(failure)
        }
        guard isMainFrame, status < 400, isOwnPage(url) else { return .allow }
        let isAttachment = contentDisposition?.trimmingCharacters(in: .whitespaces).lowercased().hasPrefix("attachment")
        return isAttachment == true || !canShowMIMEType ? .quickLook(url) : .allow
    }

    /// Whether a load error is the cancellation of a navigation the viewer cancelled itself.
    public static func isOwnCancellation(domain: String, code: Int) -> Bool {
        (domain == "NSURLErrorDomain" && code == -999) || (domain == "WebKitErrorDomain" && code == 102)
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

/// Whether a navigation that a frame of the report asks for follows a real touch. Script in the report can click a
/// link on a timer, and WebKit reports that as a tap on a link; only a touch of the person just before it makes it
/// one. One touch opens at most one sheet.
public struct RecentTouchGate: Sendable {
    /// How long after a touch a navigation of the report still counts as its result.
    public static let window: Duration = .seconds(1)

    private var lastTouch: ContinuousClock.Instant?
    private var isSpent = false

    public init() {}

    /// A finger went down.
    public mutating func touched(at instant: ContinuousClock.Instant) {
        lastTouch = instant
        isSpent = false
    }

    /// True once per touch, and only within `window` of it.
    public mutating func takesAction(at instant: ContinuousClock.Instant) -> Bool {
        guard let lastTouch, !isSpent, lastTouch <= instant, lastTouch.duration(to: instant) <= Self.window else {
            return false
        }
        isSpent = true
        return true
    }
}
