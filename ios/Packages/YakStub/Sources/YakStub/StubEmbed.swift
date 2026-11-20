import Foundation

/// The embedded thread page of the app: the mint (`POST web-session`), the redeem (`POST /app/embed/session`) and a
/// fixture page that speaks the script bridge. The scenarios play `paired` underneath:
/// `embed-basic` works, `embed-expired-once` answers the first page load with 401, `embed-slow` posts `ready` after
/// 12 seconds, and `embed-unsupported` has a `me` without `web` and no mint.
/// Every scenario, not only these, serves the routes, so a test of another screen can open the page.
final class StubEmbed: StubFeature, @unchecked Sendable {
    let name = "embed"
    let scenarios = ["embed-basic", "embed-expired-once", "embed-slow", "embed-unsupported"]

    /// How long `embed-slow` waits before the page posts `ready`.
    static let slowReadyDelay = Duration.seconds(12)
    static let cookieName = "yak_embed_session"
    static let threadPrefix = "/app/embed/threads/"
    static let redeemPath = "/app/embed/session"

    private let lock = NSLock()
    /// Minted codes and the path each is bound to; a code is removed when it is redeemed.
    private var codes: [String: String] = [:]
    private var sessions: Set<String> = []
    private var mints = 0
    private var redeems = 0
    private var pageLoads = 0
    private var expiresNextLoad = false
    private var isOnline = true

    func coreScenario(for scenario: String) -> String? { Scenario.paired.rawValue }

    func reset() {
        lock.withLock {
            codes = [:]
            sessions = []
            mints = 0
            redeems = 0
            pageLoads = 0
            expiresNextLoad = false
        }
    }

    func setOnline(_ isOnline: Bool) { lock.withLock { self.isOnline = isOnline } }

    // MARK: API

    func answer(route: String, request: StubRequest, context: StubContext) -> StubReply? {
        switch route {
        case "GET me" where context.scenario == "embed-unsupported":
            return withoutWeb(context)
        case "POST web-session":
            return mint(request: request, context: context)
        default:
            return nil
        }
    }

    private func withoutWeb(_ context: StubContext) -> StubReply? {
        guard case .reply(let status, let contentType, let body) = context.core(), status == 200,
            var me = (try? JSONSerialization.jsonObject(with: body)) as? [String: Any]
        else { return nil }
        me["web"] = nil
        guard let edited = try? JSONSerialization.data(withJSONObject: me) else { return nil }
        return StubReply(answer: .reply(status: status, contentType: contentType, body: edited))
    }

    private func mint(request: StubRequest, context: StubContext) -> StubReply {
        guard context.scenario != "embed-unsupported" else {
            return StubReply(answer: Scenario.error(404, "not_found", "Not found."))
        }
        guard request.headers["authorization"]?.hasPrefix("Bearer ") == true else {
            return StubReply(answer: Scenario.error(401, "invalid_token", "This device is not signed in."))
        }
        guard let object = (try? JSONSerialization.jsonObject(with: request.body)) as? [String: Any],
            let path = object["path"] as? String, Self.threadID(inPath: path) != nil
        else {
            return StubReply(answer: Scenario.error(422, "validation_failed", "The path is not an embed thread page."))
        }
        let code =
            "yakweb_" + (UUID().uuidString + UUID().uuidString).replacingOccurrences(of: "-", with: "").prefix(48)
        lock.withLock {
            codes[String(code)] = path
            mints += 1
        }
        let expiresAt = Date().addingTimeInterval(60).formatted(.iso8601)
        let body: [String: Any] = [
            "redeemUrl": context.baseURL + Self.redeemPath, "code": String(code), "expiresAt": expiresAt,
        ]
        let data = (try? JSONSerialization.data(withJSONObject: body)) ?? Data()
        return StubReply(answer: .reply(status: 201, contentType: "application/json", body: data))
    }

    // MARK: Control

    func control(_ request: StubRequest) -> StubReply? {
        switch request.path {
        case "/__stub/embed/state":
            let text = lock.withLock { "mints=\(mints) redeems=\(redeems) loads=\(pageLoads)" }
            return StubReply(answer: .reply(status: 200, contentType: "text/plain", body: Data(text.utf8)))
        case "/__stub/embed/expire" where request.method == "POST":
            lock.withLock { expiresNextLoad = true }
            return StubReply(answer: .reply(status: 200, contentType: "text/plain", body: Data("expired".utf8)))
        default:
            return nil
        }
    }

    // MARK: Pages

    func web(_ request: StubRequest, context: StubContext) -> StubReply? {
        guard lock.withLock({ isOnline }) else { return StubReply(answer: .drop) }
        if request.path == Self.redeemPath, request.method == "POST" { return redeem(request) }
        if request.path.hasPrefix(Self.threadPrefix), request.method == "GET" { return page(request, context: context) }
        return nil
    }

    private func redeem(_ request: StubRequest) -> StubReply {
        // The real server refuses a redeem that does not come from the app's own web view.
        let isFromTheApp = (request.headers["user-agent"] ?? "").contains(" YakApp/")
        let isSameSite = [nil, "none", "same-origin"].contains(request.headers["sec-fetch-site"])
        guard isFromTheApp, isSameSite else {
            return StubReply(answer: .reply(status: 403, contentType: "text/plain", body: Data("Forbidden".utf8)))
        }
        let form = URLComponents(string: "?" + String(decoding: request.body, as: UTF8.self))?.queryItems ?? []
        let code = form.first { $0.name == "code" }?.value ?? ""
        let outcome: (path: String, session: String)? = lock.withLock {
            guard let path = codes.removeValue(forKey: code) else { return nil }
            redeems += 1
            let session = UUID().uuidString
            sessions.insert(session)
            return (path, session)
        }
        guard let outcome else {
            return StubReply(answer: .reply(status: 403, contentType: "text/plain", body: Data("Forbidden".utf8)))
        }
        return StubReply(
            answer: .reply(status: 303, contentType: "text/plain", body: Data()),
            headers: [
                "Location": outcome.path, "Cache-Control": "no-store",
                "Set-Cookie": "\(Self.cookieName)=\(outcome.session); Path=/; HttpOnly; SameSite=Lax",
            ])
    }

    private func page(_ request: StubRequest, context: StubContext) -> StubReply {
        guard let threadID = Self.threadID(inPath: request.path) else {
            return StubReply(answer: .reply(status: 404, contentType: "text/plain", body: Data("Not found".utf8)))
        }
        let isAllowed = lock.withLock { () -> Bool in
            pageLoads += 1
            if expiresNextLoad {
                expiresNextLoad = false
                return false
            }
            if context.scenario == "embed-expired-once", pageLoads == 1 { return false }
            return Self.sessionToken(in: request).map(sessions.contains) ?? false
        }
        guard isAllowed else {
            return StubReply(
                answer: .reply(status: 401, contentType: "text/html", body: Data("<p>Unauthenticated.</p>".utf8)),
                headers: ["Cache-Control": "no-store"])
        }
        let delay = context.scenario == "embed-slow" ? Self.slowReadyDelay : .zero
        let milliseconds = Int(delay.components.seconds) * 1000
        return StubReply(
            answer: .reply(
                status: 200, contentType: "text/html; charset=utf-8",
                body: Data(Self.html(threadID: threadID, readyDelayMilliseconds: milliseconds).utf8)),
            headers: ["Cache-Control": "no-store"])
    }

    /// The thread id of `/app/embed/threads/<8 letters or digits>`, or nil.
    static func threadID(inPath path: String) -> String? {
        guard path.hasPrefix(threadPrefix) else { return nil }
        let id = String(path.dropFirst(threadPrefix.count))
        let isValid =
            id.utf8.count == 8
            && id.utf8.allSatisfy {
                ($0 >= 0x30 && $0 <= 0x39) || ($0 >= 0x41 && $0 <= 0x5A) || ($0 >= 0x61 && $0 <= 0x7A)
            }
        return isValid ? id : nil
    }

    private static func sessionToken(in request: StubRequest) -> String? {
        for pair in (request.headers["cookie"] ?? "").split(separator: ";") {
            let parts = pair.trimmingCharacters(in: .whitespaces).split(separator: "=", maxSplits: 1)
            if parts.count == 2, parts[0] == cookieName { return String(parts[1]) }
        }
        return nil
    }

    /// The fixture page. `threadID` is 8 letters or digits, so it is safe inside a script string.
    static func html(threadID: String, readyDelayMilliseconds: Int) -> String {
        #"""
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Stub conversation</title>
        <style>
        :root { color-scheme: light dark; }
        body { font: 17px -apple-system, sans-serif; margin: 16px; line-height: 1.4; }
        button { font: inherit; min-height: 44px; padding: 0 16px; margin: 0 8px 8px 0; }
        li { margin-bottom: 8px; }
        </style>
        </head>
        <body>
        <main>
        <h1 id="title" data-testid="embed-title"></h1>
        <p id="status" data-testid="embed-status">Loading</p>
        <p id="connectivity" data-testid="embed-connectivity">Online</p>
        <p id="refreshes" data-testid="embed-refreshes">Refreshed 0 times</p>
        <div>
        <button id="send-link" data-testid="embed-link">Open a link</button>
        <button id="send-pane" data-testid="embed-pane">Open the files pane</button>
        <button id="send-card" data-testid="embed-card">Answer the question</button>
        <button id="send-auth" data-testid="embed-auth">Expire the session</button>
        </div>
        <h2>Unsent messages</h2>
        <ul id="outbox" data-testid="embed-outbox"></ul>
        <p id="last" data-testid="embed-last"></p>
        </main>
        <script>
        (function () {
          var threadId = "\#(threadID)";
          var refreshCount = 0;
          function el(id) { return document.getElementById(id); }
          function post(message) {
            message.v = 1;
            var handlers = window.webkit && window.webkit.messageHandlers;
            if (handlers && handlers.yak) { handlers.yak.postMessage(message); }
            el("last").textContent = "Sent " + message.type;
          }
          function showThread() { el("title").textContent = "Stub conversation " + threadId; }
          function sendReady() {
            el("status").textContent = "Ready";
            post({ type: "ready", threadId: threadId, url: "/app/embed/threads/" + threadId, latestSequence: 42,
                   title: "Stub conversation " + threadId });
          }
          showThread();
          setTimeout(sendReady, \#(readyDelayMilliseconds));
          el("send-link").addEventListener("click", function () { post({ type: "link", url: "https://example.com/docs" }); });
          el("send-pane").addEventListener("click", function () {
            post({ type: "openPane", pane: "files", mode: "changed", path: "README.md" });
          });
          el("send-card").addEventListener("click", function () {
            post({ type: "cardAnswered", card: "question", action: "answer", ok: true });
          });
          el("send-auth").addEventListener("click", function () { post({ type: "auth", state: "expired" }); });
          function renderOutbox(items) {
            var list = el("outbox");
            list.textContent = "";
            items.forEach(function (item) {
              var row = document.createElement("li");
              row.setAttribute("data-testid", "embed-outbox-item");
              row.setAttribute("data-status", item.status);
              row.textContent = item.text + " (" + item.status + (item.reason ? ": " + item.reason : "") + ")";
              var retry = document.createElement("button");
              retry.textContent = "Retry";
              retry.addEventListener("click", function () { post({ type: "unsentAction", key: item.key, action: "retry" }); });
              row.appendChild(document.createElement("br"));
              row.appendChild(retry);
              list.appendChild(row);
            });
          }
          window.__yakEmbed = {
            receive: function (message) {
              if (!message || message.v !== 1) { return; }
              switch (message.type) {
                case "open":
                  var previous = threadId;
                  threadId = message.threadId;
                  history.replaceState(null, "", "/app/embed/threads/" + threadId + (message.fragment ? "#" + message.fragment : ""));
                  showThread();
                  post({ type: "threadChanged", threadId: threadId, previousThreadId: previous });
                  sendReady();
                  break;
                case "focus":
                  location.hash = message.fragment;
                  el("last").textContent = "Focused " + message.fragment;
                  break;
                case "scrollToBottom":
                  window.scrollTo(0, document.body.scrollHeight);
                  el("last").textContent = "Scrolled to the bottom";
                  break;
                case "outbox":
                  renderOutbox(message.items || []);
                  break;
                case "refresh":
                  refreshCount += 1;
                  el("refreshes").textContent = "Refreshed " + refreshCount + (refreshCount === 1 ? " time" : " times");
                  break;
                case "connectivity":
                  el("connectivity").textContent = message.online ? "Online" : "Offline";
                  break;
              }
            }
          };
        })();
        </script>
        </body>
        </html>
        """#
    }
}
