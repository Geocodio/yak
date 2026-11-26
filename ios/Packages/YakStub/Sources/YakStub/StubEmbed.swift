import Foundation

/// The embedded thread page of the app: the mint (`POST web-session`), the redeem (`POST /app/embed/session`) and a
/// fixture page that speaks the script bridge. The scenarios play `paired` underneath:
/// `embed-basic` works, `embed-expired-once` answers the first page load with 401, `embed-slow` posts `ready` after
/// 12 seconds, `embed-working` is `embed-basic` over a thread whose turn stays open, where a message sent while the
/// turn runs is saved as a waiting (queued) message that the thread's show lists until it is changed or withdrawn,
/// `embed-failed` refuses every mint with a 503, and `embed-unsupported` has a `me` without `web` and no
/// mint.
/// Every scenario serves the routes and puts `web` in `me`, so the thread of every test shows this page; only
/// `embed-unsupported` leaves `web` out.
final class StubEmbed: StubFeature, @unchecked Sendable {
    let name = "embed"
    let scenarios = [
        "embed-basic", "embed-expired-once", "embed-slow", "embed-unsupported", "embed-failed", "embed-working",
    ]

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
    /// How many times the app asked for a thread's show (`GET threads/<id>`), which a card answered on the page makes it do.
    private var shows = 0
    private var expiresNextLoad = false
    private var isOnline = true
    /// How the shared file pages answer: `ok`, `missing` (404), `forbidden` (403), `broken` (500), `attachment` (the page is sent as a download) or `slow` (the page
    /// waits 12 seconds). `POST /__stub/embed/file/<mode>` sets it.
    var fileMode = "ok"

    func coreScenario(for scenario: String) -> String? {
        scenario == Self.workingScenario ? Scenario.oneThreadHeld.rawValue : Scenario.paired.rawValue
    }

    static let workingScenario = "embed-working"

    func reset() {
        lock.withLock {
            codes = [:]
            sessions = []
            mints = 0
            redeems = 0
            pageLoads = 0
            shows = 0
            expiresNextLoad = false
            fileMode = "ok"
        }
    }

    func setOnline(_ isOnline: Bool) { lock.withLock { self.isOnline = isOnline } }

    func saw(route: String) {
        guard route.hasPrefix("GET threads/"), !route.dropFirst("GET threads/".count).contains("/") else { return }
        lock.withLock { shows += 1 }
    }

    // MARK: API

    func answer(route: String, request: StubRequest, context: StubContext) -> StubReply? {
        switch route {
        case "GET me" where !Self.offersEmbeddedThread(context.scenario):
            return withoutWeb(context)
        case "POST web-session":
            return mint(request: request, context: context)
        default:
            return nil
        }
    }

    /// Every scenario announces the embedded thread page but `embed-unsupported`, which plays an older server.
    static func offersEmbeddedThread(_ scenario: String) -> Bool { scenario != "embed-unsupported" }

    private func withoutWeb(_ context: StubContext) -> StubReply? {
        guard case .reply(let status, let contentType, let body) = context.core(), status == 200,
            let edited = Self.removingWeb(from: body)
        else { return nil }
        return StubReply(answer: .reply(status: status, contentType: contentType, body: edited))
    }

    /// `me` without its `web` member; nil when `body` is not a JSON object.
    static func removingWeb(from body: Data) -> Data? {
        guard var me = (try? JSONSerialization.jsonObject(with: body)) as? [String: Any] else { return nil }
        me["web"] = nil
        return try? JSONSerialization.data(withJSONObject: me)
    }

    /// The answer to `GET me` as the scenario plays it. Another feature may answer `me` before this one is asked, so the
    /// server passes every `me` answer through here.
    static func meReply(_ reply: StubReply, scenario: String) -> StubReply {
        guard !offersEmbeddedThread(scenario), case .reply(200, let contentType, let body) = reply.answer,
            let edited = removingWeb(from: body)
        else { return reply }
        var result = reply
        result.answer = .reply(status: 200, contentType: contentType, body: edited)
        return result
    }

    private func mint(request: StubRequest, context: StubContext) -> StubReply {
        guard context.scenario != "embed-unsupported" else {
            return StubReply(answer: Scenario.error(404, "not_found", "Not found."))
        }
        guard context.scenario != "embed-failed" else {
            return StubReply(answer: Scenario.error(503, "unavailable", "The server is busy."))
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
            let text = lock.withLock { "mints=\(mints) redeems=\(redeems) loads=\(pageLoads) shows=\(shows)" }
            return StubReply(answer: .reply(status: 200, contentType: "text/plain", body: Data(text.utf8)))
        case "/__stub/embed/expire" where request.method == "POST":
            lock.withLock { expiresNextLoad = true }
            return StubReply(answer: .reply(status: 200, contentType: "text/plain", body: Data("expired".utf8)))
        case let path where path.hasPrefix("/__stub/embed/file/") && request.method == "POST":
            let mode = String(path.dropFirst("/__stub/embed/file/".count))
            guard ["ok", "missing", "forbidden", "broken", "slow", "attachment"].contains(mode) else { return nil }
            lock.withLock { fileMode = mode }
            return StubReply(answer: .reply(status: 200, contentType: "text/plain", body: Data(mode.utf8)))
        default:
            return nil
        }
    }

    // MARK: Pages

    func web(_ request: StubRequest, context: StubContext) -> StubReply? {
        guard lock.withLock({ isOnline }) else { return StubReply(answer: .drop) }
        if request.path == Self.redeemPath, request.method == "POST" { return redeem(request) }
        if request.path.hasPrefix(Self.threadPrefix), request.method == "GET" { return page(request, context: context) }
        if request.path.hasPrefix("/files/") { return sharedFile(request) }
        if request.path == Self.loginPath, request.method == "GET" { return loginPage() }
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

    /// Whether the request carries a session this stub redeemed; an expiry asked for with `expire` ends it once.
    func hasSession(_ request: StubRequest) -> Bool {
        lock.withLock { () -> Bool in
            if expiresNextLoad {
                expiresNextLoad = false
                return false
            }
            return Self.sessionToken(in: request).map(sessions.contains) ?? false
        }
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
        :root { color-scheme: light dark; --page: #ffffff; --text: #1b1b1f; --muted: #4a4a52; --fill: #e9e9ee; --line: #6b6b75; }
        @media (prefers-color-scheme: dark) { :root { --page: #1c1c1e; --text: #f4f4f6; --muted: #c4c4cc; --fill: #38383d; --line: #a0a0aa; } }
        html { font-size: 100%; }
        body { font: 1.0625rem -apple-system, sans-serif; margin: 1rem; line-height: 1.4; background: var(--page); color: var(--text); }
        h1 { font-size: 1.5rem; margin: 0 0 0.5rem; }
        h2 { font-size: 1.125rem; margin: 1rem 0 0.5rem; }
        p { color: var(--muted); margin: 0.25rem 0; }
        button { font: inherit; color: var(--text); background: var(--fill); border: 1px solid var(--line); border-radius: 0.5rem; min-height: 2.75rem; padding: 0 1rem; margin: 0 0.5rem 0.5rem 0; }
        a.action { display: inline-block; font: inherit; color: var(--text); background: var(--fill); border: 1px solid var(--line); border-radius: 0.5rem; line-height: 2.75rem; padding: 0 1rem; margin: 0 0.5rem 0.5rem 0; text-decoration: none; }
        ul { padding-left: 1.25rem; }
        li { margin-bottom: 0.5rem; }
        [data-fragment-target] { outline: 2px solid var(--line); outline-offset: 2px; background: var(--fill); }
        #history li, #card-question { padding: 0.5rem; border-radius: 0.5rem; }
        </style>
        </head>
        <body>
        <main>
        <h1 id="title" data-testid="embed-title" tabindex="-1"></h1>
        <p id="status" data-testid="embed-status" role="status">Loading</p>
        <p id="connectivity" data-testid="embed-connectivity">Online</p>
        <p id="refreshes" data-testid="embed-refreshes">Refreshed 0 times</p>
        <div>
        <button id="send-link" data-testid="embed-link">Open a link</button>
        <button id="send-pane" data-testid="embed-pane">Open the files pane</button>
        <button id="send-card" data-testid="embed-card">Answer the question</button>
        <button id="send-approve" data-testid="embed-approve">Approve the co-owner decision</button>
        <button id="send-file" data-testid="embed-file">Open a file</button>
        <button id="send-auth" data-testid="embed-auth">Expire the session</button>
        </div>
        <section aria-label="Shared report" data-testid="embed-report">
        <p>Weekly report.html</p>
        <a id="open-report" class="action" href="/files/01HZX3K9ABCDEFGHJKMNPQRSTV" target="_blank" rel="noopener noreferrer" data-testid="embed-open-report">Open report</a>
        <button id="copy-report" data-testid="embed-copy-report">Copy report link</button>
        <a id="open-scripted" class="action" href="/files/01HZX3K9AUTOCLICKREPORT000" target="_blank" rel="noopener noreferrer" data-testid="embed-open-scripted">Open scripted report</a>
        </section>
        <h2>Messages</h2>
        <ul id="messages" data-testid="embed-messages" aria-label="Messages"></ul>
        <h2>History</h2>
        <ul id="history" data-testid="embed-history" aria-label="History"></ul>
        <section id="card-question" aria-label="Question" tabindex="-1" data-testid="embed-question"><p>Yak has a question for you.</p></section>
        <h2>Unsent messages</h2>
        <ul id="outbox" data-testid="embed-outbox" aria-label="Unsent messages"></ul>
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
          var lastAgents = null;
          function sendReady() {
            lastAgents = null;
            el("status").textContent = "Ready";
            post({ type: "ready", threadId: threadId, url: "/app/embed/threads/" + threadId, latestSequence: 42,
                   title: "Stub conversation " + threadId });
          }
          (function fillHistory() {
            var list = el("history");
            for (var index = 1; index <= 30; index += 1) {
              var row = document.createElement("li");
              row.id = index === 12 ? "message-9f2c1a4e" : "message-earlier-" + index;
              row.textContent = index === 12 ? "The message a link points at" : "Earlier message " + index;
              list.appendChild(row);
            }
          })();
          function focusTarget(fragment) {
            var target = document.getElementById(fragment);
            if (!target) { post({ type: "focusMissed", fragment: fragment }); el("last").textContent = "Missed " + fragment; return; }
            target.setAttribute("data-fragment-target", "");
            target.scrollIntoView({ block: "center" });
            if (!target.hasAttribute("tabindex")) { target.setAttribute("tabindex", "-1"); }
            target.focus({ preventScroll: true });
            el("last").textContent = "Focused " + fragment;
            setTimeout(function () { target.removeAttribute("data-fragment-target"); }, 1600);
          }
          // The agents scenarios of the stub report the running agents; every other scenario answers 404, which ends the polling.
          var agentsTimer = null;
          function pollAgents() {
            fetch("/__stub/agents/report", { cache: "no-store" }).then(function (answer) {
              if (answer.status === 404) { clearInterval(agentsTimer); return null; }
              return answer.ok ? answer.text() : null;
            }).then(function (text) {
              if (!text) { return; }
              var report = JSON.parse(text);
              var key = report.running + ":" + report.newest;
              if (key === lastAgents) { return; }
              lastAgents = key;
              report.cards.forEach(function (id) {
                if (el(id)) { return; }
                var card = document.createElement("section");
                card.id = id;
                card.setAttribute("tabindex", "-1");
                card.textContent = "Agent card " + id;
                el("history").appendChild(card);
              });
              var message = { type: "agents", running: report.running };
              if (report.newest) { message.newest = report.newest; }
              post(message);
            }).catch(function () {});
          }
          agentsTimer = setInterval(pollAgents, 300);
          showThread();
          setTimeout(sendReady, \#(readyDelayMilliseconds));
          el("send-link").addEventListener("click", function () { post({ type: "link", url: "https://example.com/docs" }); });
          el("send-pane").addEventListener("click", function () {
            post({ type: "openPane", pane: "files", mode: "changed", path: "README.md" });
          });
          el("send-card").addEventListener("click", function () {
            post({ type: "cardAnswered", card: "question", action: "answer", ok: true });
          });
          el("send-approve").addEventListener("click", function () {
            post({ type: "cardAnswered", card: "co_owner", action: "approve", ok: true });
          });
          el("copy-report").addEventListener("click", function () {
            navigator.clipboard.writeText(location.origin + "/files/01HZX3K9ABCDEFGHJKMNPQRSTV").then(
              function () { el("last").textContent = "Copied the link"; },
              function () { el("last").textContent = "Could not copy the link"; });
          });
          el("send-file").addEventListener("click", function () {
            post({ type: "quickLook", url: location.origin + "/files/01HZX3K9ABCDEFGHJKMNPQRSTV", name: "report.txt", mime: "text/plain" });
          });
          el("send-auth").addEventListener("click", function () { post({ type: "auth", state: "expired" }); });
          var outboxItems = [];
          var sentKeys = {};
          function renderMessages() {
            var list = el("messages");
            list.textContent = "";
            Object.keys(sentKeys).forEach(function (key) {
              var row = document.createElement("li");
              row.setAttribute("data-testid", "embed-message");
              row.textContent = sentKeys[key];
              list.appendChild(row);
            });
          }
          function loadSent() {
            fetch("/__stub/last-message", { cache: "no-store" }).then(function (answer) { return answer.text(); }).then(function (text) {
              var sent = JSON.parse(text);
              var key = sent.send_key || sent.sendKey;
              if (key && !sentKeys[key.toLowerCase()]) {
                sentKeys[key.toLowerCase()] = sent.message || sent.text || "";
                renderMessages();
                renderOutbox(outboxItems);
              }
            }).catch(function () {});
          }
          function renderOutbox(items) {
            outboxItems = items;
            var list = el("outbox");
            list.textContent = "";
            items.filter(function (item) { return !sentKeys[item.key]; }).forEach(function (item) {
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
                  focusTarget(message.fragment);
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
                  loadSent();
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
