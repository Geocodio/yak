import Foundation

/// What the preview pane's web view may load, and what to do with each answer.
public enum PreviewNavigation {
    public enum Decision: Equatable, Sendable {
        case allow
        case mintTicket(path: String)
        case openInBrowser(URL)
        case cancel
        /// Would open the browser, but no tap or form started it: cancel and say so.
        case cancelUnrequestedOpen
    }
    public enum ResponseDecision: Equatable, Sendable {
        case allow
        case refetchStatus
        case mintAgain(path: String)
    }

    private static let bouncePath = "/deployments/auth-bounce"
    private static let authPath = "/_yak/auth"

    /// An authority is the host, lowercased, plus ":<port>" when the URL names one; nil without a host.
    public static func authority(of url: URL) -> String? {
        guard let components = URLComponents(url: url, resolvingAgainstBaseURL: false),
            let host = components.host, !host.isEmpty
        else { return nil }
        let lowered = host.lowercased()
        return components.port.map { "\(lowered):\($0)" } ?? lowered
    }

    /// `isUserInitiated`: the navigation type is `.linkActivated` or `.formSubmitted`.
    public static func decide(
        url: URL, previewHost: String, instanceHost: String, isMainFrame: Bool,
        isUserInitiated: Bool, allowsLoopbackHTTP: Bool = false
    ) -> Decision {
        let scheme = url.scheme?.lowercased() ?? ""
        let absolute = url.absoluteString.lowercased()
        if absolute == "about:blank" || absolute == "about:srcdoc" { return .allow }
        if scheme == "javascript" { return .cancel }

        func opensBrowser() -> Decision { isUserInitiated ? .openInBrowser(url) : .cancelUnrequestedOpen }

        guard scheme == "http" || scheme == "https" else {
            if !isMainFrame { return scheme == "data" || scheme == "blob" ? .allow : .cancel }
            if scheme == "data" || scheme == "blob" || scheme == "file" { return .cancel }
            return opensBrowser()
        }
        let urlAuthority = authority(of: url)
        let components = URLComponents(url: url, resolvingAgainstBaseURL: false)
        if urlAuthority == instanceHost.lowercased() {
            guard isMainFrame else { return .cancel }
            if components?.path == bouncePath {
                return .mintTicket(path: bouncePath(of: components, previewHost: previewHost))
            }
            return opensBrowser()
        }
        if urlAuthority == previewHost.lowercased() {
            if scheme == "https" { return .allow }
            return allowsLoopbackHTTP && components?.host == "127.0.0.1" ? .allow : .cancel
        }
        return isMainFrame ? opensBrowser() : .allow
    }

    public static func respond(status: Int, url: URL, previewHost: String, isMainFrame: Bool) -> ResponseDecision {
        guard isMainFrame, authority(of: url) == previewHost.lowercased() else { return .allow }
        let components = URLComponents(url: url, resolvingAgainstBaseURL: false)
        if status == 401, components?.path == authPath {
            let destination = components?.queryItems?.first { $0.name == "to" }?.value
            return .mintAgain(path: PreviewAddress.normalize(destination ?? "/"))
        }
        return [425, 502, 404].contains(status) ? .refetchStatus : .allow
    }

    /// The ticket's scheme and authority equal `previewURL`'s, its path is /_yak/auth, and the scheme is https
    /// (or http on 127.0.0.1 with `allowsLoopbackHTTP`).
    public static func isAcceptedTicket(_ ticket: URL, previewURL: URL, allowsLoopbackHTTP: Bool = false) -> Bool {
        guard let ticketComponents = URLComponents(url: ticket, resolvingAgainstBaseURL: false),
            let scheme = ticketComponents.scheme?.lowercased(),
            scheme == previewURL.scheme?.lowercased(),
            let ticketAuthority = authority(of: ticket), ticketAuthority == authority(of: previewURL),
            ticketComponents.path == authPath
        else { return false }
        return scheme == "https" || (scheme == "http" && allowsLoopbackHTTP && ticketComponents.host == "127.0.0.1")
    }

    private static func bouncePath(of components: URLComponents?, previewHost: String) -> String {
        guard let target = components?.queryItems?.first(where: { $0.name == "to" })?.value,
            let targetURL = URL(string: target), authority(of: targetURL) == previewHost.lowercased(),
            let targetComponents = URLComponents(url: targetURL, resolvingAgainstBaseURL: false)
        else { return "/" }
        var path = targetComponents.percentEncodedPath
        if let query = targetComponents.percentEncodedQuery { path += "?" + query }
        return PreviewAddress.normalize(path)
    }
}

/// Why the web view could not show a page.
public enum PreviewPageProblem: Equatable, Sendable {
    case offline, timedOut, untrustedCertificate, cannotReach, other

    /// nil for `.cancelled`.
    public static func make(_ code: URLError.Code) -> PreviewPageProblem? {
        switch code {
        case .cancelled: return nil
        case .notConnectedToInternet, .networkConnectionLost, .dataNotAllowed, .internationalRoamingOff: return .offline
        case .timedOut: return .timedOut
        case .secureConnectionFailed, .serverCertificateUntrusted, .serverCertificateHasBadDate,
            .serverCertificateHasUnknownRoot, .serverCertificateNotYetValid, .clientCertificateRejected,
            .clientCertificateRequired:
            return .untrustedCertificate
        case .cannotFindHost, .cannotConnectToHost, .dnsLookupFailed: return .cannotReach
        default: return .other
        }
    }
}
