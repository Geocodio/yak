import Foundation

/// The shared file pages of the embedded app: `/files/<id>` is the viewer page with its framed report, and
/// `/files/<id>/raw` the report itself. Both need the embed session, as the real pages do for an embed session.
extension StubEmbed {
    static let reportName = "Weekly report.html"
    static let reportLinkTarget = "https://example.com/source"

    func sharedFile(_ request: StubRequest) -> StubReply {
        let parts = request.path.split(separator: "/", omittingEmptySubsequences: false).map(String.init)
        let isRaw = parts.count == 4 && parts[3] == "raw"
        guard request.method == "GET", parts.count == (isRaw ? 4 : 3), parts[1] == "files", Self.isFileID(parts[2])
        else { return Self.text(404, "Not found") }
        guard hasSession(request) else {
            return StubReply(
                answer: .reply(status: 401, contentType: "text/html", body: Data("<p>Unauthenticated.</p>".utf8)))
        }
        switch fileMode {
        case "missing": return Self.text(404, "Not found")
        case "forbidden": return Self.text(403, "Forbidden")
        case "broken": return Self.text(500, "Server error")
        default: break
        }
        let body = isRaw ? Self.reportHTML : Self.viewerHTML(id: parts[2])
        var reply = StubReply(
            answer: .reply(status: 200, contentType: "text/html; charset=utf-8", body: Data(body.utf8)),
            headers: ["Cache-Control": "no-store"])
        if fileMode == "slow", !isRaw { reply.delay = Self.slowReadyDelay }
        return reply
    }

    static func isFileID(_ text: String) -> Bool {
        text.utf8.count == 26
            && text.utf8.allSatisfy { ($0 >= 0x30 && $0 <= 0x39) || ($0 | 0x20 >= 0x61 && $0 | 0x20 <= 0x7A) }
    }

    private static func text(_ status: Int, _ body: String) -> StubReply {
        StubReply(answer: .reply(status: status, contentType: "text/plain", body: Data(body.utf8)))
    }

    /// The page of the server's `shared-files.viewer` view: a header with the name and Download, and the framed report.
    static func viewerHTML(id: String) -> String {
        """
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>\(reportName) - Yak</title>
        <style>
        :root { color-scheme: light dark; --bg: #eceef1; --panel: #fff; --body: #1c1c1f; --accent: #8a3b16; --ink: #fff; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0c0d10; --panel: #17181d; --body: #f4f4f6; --accent: #f0b090; --ink: #101014; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; flex-direction: column; background: var(--bg); color: var(--body); font: 1rem/1.5 -apple-system, sans-serif; }
        header { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 12px; padding: 10px 16px; background: var(--panel); }
        .name { flex: 1; min-width: 0; font-weight: 500; }
        .download { padding: 5px 10px; border-radius: 8px; background: var(--accent); color: var(--ink); text-decoration: none; }
        iframe { flex: 1; width: 100%; min-height: calc(100vh - 56px); border: 0; background: #fff; }
        </style>
        </head>
        <body>
        <header>
        <span class="name" data-testid="shared-file-name">\(reportName)</span>
        <a class="download" href="/files/\(id)/raw?download=1" data-testid="shared-file-download">Download</a>
        </header>
        <iframe src="/files/\(id)/raw" title="\(reportName)" sandbox="allow-scripts" data-testid="shared-file-frame"></iframe>
        </body>
        </html>
        """
    }

    /// The report: its own text, a link to another site, and no script.
    static let reportHTML = """
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>body { font: 1.0625rem -apple-system, sans-serif; margin: 1rem; color: #1b1b1f; background: #fff; } a { color: #0b4fa8; }</style>
        </head>
        <body>
        <h1>Weekly report</h1>
        <p>Signups grew by twelve percent this week.</p>
        <p><a href="\(reportLinkTarget)" data-testid="report-link">Source data</a></p>
        </body>
        </html>
        """
}
