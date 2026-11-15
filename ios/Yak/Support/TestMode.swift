#if DEBUG
    import Foundation
    import YakKit

    /// How UI tests and walkthroughs run the app against the stub server. It exists in Debug builds only.
    ///
    /// Launch arguments: `-yakTestInstance <url>` turns it on, `-yakTestToken <token>` also starts the app
    /// paired with that instance, `-yakTestKeychainLocked YES` makes reading a token fail like a locked
    /// Keychain, `-yakTestSkipWebSession YES` keeps the system web sign-in sheet closed so the waiting screen can be seen. The network state comes from the stub's `/__stub/online`, so a test can take the app offline and
    /// bring it back. In test mode nothing is read from or written to the real Keychain or `UserDefaults`, and
    /// plain `http://127.0.0.1` addresses and pairing links are accepted, which Release never does.
    struct TestMode {
        let instance: URL
        let token: String?
        let isKeychainLocked: Bool
        let skipsWebSession: Bool

        static func current(_ defaults: UserDefaults = .standard) -> TestMode? {
            guard let address = defaults.string(forKey: "yakTestInstance"), let instance = URL(string: address) else {
                return nil
            }
            return TestMode(
                instance: instance, token: defaults.string(forKey: "yakTestToken"),
                isKeychainLocked: defaults.bool(forKey: "yakTestKeychainLocked"),
                skipsWebSession: defaults.bool(forKey: "yakTestSkipWebSession"))
        }

        @MainActor func makeModel(version: String, system: String) -> AppModel {
            let lockable = LockableTokenStore()
            let instances = InstanceStore(defaults: MemoryDefaults(), tokens: lockable, now: { Date() })
            let seed = self
            let model = AppModel(
                instances: instances, tokens: lockable,
                makeClient: { url in
                    APIClient(
                        baseURL: url, token: nil, transport: URLSessionTransport(), appVersion: version,
                        systemDescription: system)
                },
                normalizeAddress: Self.normalize, parseLink: Self.parse,
                prepare: { instances in
                    if let token = seed.token {
                        do {
                            _ = try await instances.add(
                                url: seed.instance, name: seed.instance.host() ?? "Yak", userName: "Test Person",
                                token: token)
                        } catch {
                            preconditionFailure("test mode could not seed the instance: \(error)")
                        }
                    }
                    if seed.isKeychainLocked { lockable.lockReads() }
                },
                network: NetworkMonitor(poll: { await Self.stubIsOnline(at: seed.instance) }))
            model.skipsWebSession = skipsWebSession
            return model
        }

        /// Reads `GET /__stub/online`; a stub that cannot be reached counts as online, so a stopped stub is not "offline".
        @Sendable static func stubIsOnline(at instance: URL) async -> Bool {
            guard let (data, _) = try? await URLSession.shared.data(from: instance.appending(path: "__stub/online"))
            else { return true }
            return String(decoding: data, as: UTF8.self) != "no"
        }

        private static func isLoopback(_ address: String) -> Bool { address.lowercased().hasPrefix("http://127.0.0.1") }

        /// Plain `http://127.0.0.1[:port]` stays http; everything else follows the normal rules.
        @Sendable static func normalize(_ input: String) -> URL? {
            let text = input.trimmingCharacters(in: .whitespacesAndNewlines)
            let authority = text.lowercased().replacing("http://", with: "").prefix { !"/?#".contains($0) }
            guard authority == "127.0.0.1" || authority.hasPrefix("127.0.0.1:") else {
                return InstanceAddress.normalize(input)
            }
            return URL(string: "http://\(authority)")
        }

        @Sendable static func parse(_ url: URL) -> PairingLink? {
            if let link = PairingLink(url: url) { return link }
            guard url.scheme?.lowercased() == "yak", url.host?.lowercased() == "pair",
                let items = URLComponents(url: url, resolvingAgainstBaseURL: false)?.queryItems,
                let address = items.first(where: { $0.name == "url" })?.value, isLoopback(address),
                let code = items.first(where: { $0.name == "code" })?.value, !code.isEmpty,
                let instance = normalize(address)
            else { return nil }
            return PairingLink(instance: instance, code: code)
        }
    }

    private final class MemoryDefaults: KeyValueStore, @unchecked Sendable {
        private let lock = NSLock()
        private var values: [String: Data] = [:]
        func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
        func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
    }

    /// Holds tokens in memory and, once locked, fails every read like a Keychain before first unlock.
    private final class LockableTokenStore: TokenStore, @unchecked Sendable {
        struct Locked: Error {}
        private let inner = InMemoryTokenStore()
        private let lock = NSLock()
        private var isLocked = false

        func lockReads() { lock.withLock { isLocked = true } }
        func token(for instance: UUID) throws -> String? {
            if lock.withLock({ isLocked }) { throw Locked() }
            return try inner.token(for: instance)
        }
        func setToken(_ token: String, for instance: UUID) throws { try inner.setToken(token, for: instance) }
        func removeToken(for instance: UUID) throws { try inner.removeToken(for: instance) }
    }
#endif
