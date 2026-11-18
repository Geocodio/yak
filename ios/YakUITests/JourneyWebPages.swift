import XCTest

/// The person's side of the web, driven over HTTP: the local-only login, the approval of a pairing and the revoke on
/// Settings, Devices. The system sign-in sheet of the simulator does not share Safari's cookies, so a journey cannot
/// click through the real pages; it sends the same requests the pages send, with a session of its own.
@MainActor final class JourneyWebPages {
    let server: JourneyServer
    private let session: URLSession
    private let cookies: HTTPCookieStorage

    init(server: JourneyServer) {
        self.server = server
        let configuration = URLSessionConfiguration.ephemeral
        cookies = configuration.httpCookieStorage ?? .shared
        cookies.cookieAcceptPolicy = .always
        configuration.timeoutIntervalForRequest = 15
        configuration.timeoutIntervalForResource = 30
        session = URLSession(configuration: configuration)
    }

    /// Signs in as the seeded person through `/letmein`, which exists in e2e mode only.
    func signInWithTheLocalLogin() async throws {
        let (_, response) = try await send("GET", "/letmein")
        XCTAssertEqual(response.statusCode, 200, "/letmein did not end on a page (status \(response.statusCode))")
    }

    /// Accepts the system's sign-in alert that comes before the app's sheet, waiting up to 15 s for it.
    func acceptSignInAlert() {
        let springboard = XCUIApplication(bundleIdentifier: "com.apple.springboard")
        let button = springboard.alerts.buttons["Continue"]
        if button.waitForExistence(timeout: 15) { button.tap() }
    }

    /// Approves the pairing the app waits for, as the page's button "Approve this phone" does.
    func approve(approvalURL: String) async throws {
        let components = try XCTUnwrap(URLComponents(string: approvalURL), "no approval address")
        let code = try XCTUnwrap(components.queryItems?.first { $0.name == "code" }?.value, "no code in \(approvalURL)")
        let (_, page) = try await send("GET", "/device?code=\(code)")
        XCTAssertEqual(page.statusCode, 200, "the approval page answered \(page.statusCode)")
        let (_, response) = try await send("POST", "/device/approve", form: ["code": code], followRedirects: false)
        XCTAssertEqual(response.statusCode, 302, "approving the phone answered \(response.statusCode)")
        let location = response.value(forHTTPHeaderField: "Location") ?? ""
        XCTAssertTrue(location.contains("result=approved"), "approving the phone redirected to \(location)")
    }

    /// Revokes the named device on Settings, Devices, as its "Revoke" button and dialog do. The page lists its
    /// devices in a deferred prop, which the page's own script fetches with a partial reload; so does this.
    func revokePhone(named name: String) async throws {
        let (html, page) = try await send("GET", "/settings/devices")
        XCTAssertEqual(page.statusCode, 200, "Settings, Devices answered \(page.statusCode)")
        let version = try XCTUnwrap(Self.pageVersion(inPage: String(decoding: html, as: UTF8.self)), "no page version")
        let (data, reload) = try await send(
            "GET", "/settings/devices",
            headers: [
                "X-Inertia": "true", "X-Inertia-Version": version, "X-Inertia-Partial-Component": "Settings/Devices",
                "X-Inertia-Partial-Data": "devices", "Accept": "application/json",
            ])
        XCTAssertEqual(reload.statusCode, 200, "the devices list answered \(reload.statusCode)")
        let list = try XCTUnwrap(try? JSONSerialization.jsonObject(with: data), "the devices list is not JSON")
        let identifier = try XCTUnwrap(
            Self.find(deviceNamed: name, in: list), "no device named \(name) on Settings, Devices")
        let (_, response) = try await send(
            "POST", "/settings/devices/\(identifier)", form: ["_method": "DELETE"],
            headers: ["Referer": server.url + "/settings/devices"], followRedirects: false)
        XCTAssertEqual(response.statusCode, 302, "revoking \(name) answered \(response.statusCode)")
        let (flash, _) = try await send("GET", "/settings/devices")
        XCTAssertTrue(
            String(decoding: flash, as: UTF8.self).contains("Revoked \(name)."),
            "the page does not say \(name) was revoked")
    }

    /// The asset version in the page's Inertia data.
    private static func pageVersion(inPage html: String) -> String? {
        guard let marker = html.range(of: "data-page=\"app\""),
            let open = html[marker.upperBound...].firstIndex(of: ">"),
            let close = html.range(of: "</script>", range: open..<html.endIndex),
            let data = String(html[html.index(after: open)..<close.lowerBound]).data(using: .utf8),
            let page = try? JSONSerialization.jsonObject(with: data) as? [String: Any]
        else { return nil }
        return page["version"] as? String
    }

    private static func find(deviceNamed name: String, in node: Any) -> String? {
        if let object = node as? [String: Any] {
            if object["name"] as? String == name, let id = object["id"] {
                return "\(id)"
            }
            for value in object.values { if let found = find(deviceNamed: name, in: value) { return found } }
        } else if let list = node as? [Any] {
            for value in list { if let found = find(deviceNamed: name, in: value) { return found } }
        }
        return nil
    }

    private func send(
        _ method: String, _ path: String, form: [String: String] = [:], headers: [String: String] = [:],
        followRedirects: Bool = true
    ) async throws -> (
        Data, HTTPURLResponse
    ) {
        var request = URLRequest(url: try XCTUnwrap(URL(string: server.url + path)))
        request.httpMethod = method
        request.setValue("text/html", forHTTPHeaderField: "Accept")
        for (name, value) in headers { request.setValue(value, forHTTPHeaderField: name) }
        if method != "GET" {
            let token = cookies.cookies(for: request.url!)?.first { $0.name == "XSRF-TOKEN" }?.value
            request.setValue(token?.removingPercentEncoding, forHTTPHeaderField: "X-XSRF-TOKEN")
        }
        if !form.isEmpty {
            request.setValue("application/x-www-form-urlencoded", forHTTPHeaderField: "Content-Type")
            var allowed = CharacterSet.alphanumerics
            allowed.insert(charactersIn: "-._~")
            request.httpBody = form.map {
                "\($0.key)=\($0.value.addingPercentEncoding(withAllowedCharacters: allowed) ?? "")"
            }
            .joined(separator: "&").data(using: .utf8)
        }
        let (data, response) = try await session.data(
            for: request, delegate: followRedirects ? nil : RedirectRefusal())
        return (data, try XCTUnwrap(response as? HTTPURLResponse))
    }
}

/// Hands the redirect response to the caller, so a journey can assert the status and the address itself.
private final class RedirectRefusal: NSObject, URLSessionTaskDelegate, Sendable {
    func urlSession(
        _ session: URLSession, task: URLSessionTask, willPerformHTTPRedirection response: HTTPURLResponse,
        newRequest request: URLRequest
    ) async -> URLRequest? { nil }
}
