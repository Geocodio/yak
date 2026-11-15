import Foundation

/// Where a thread's preview is. An open value: a state this app does not know is kept and is not transitional.
public struct PreviewState: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let ready = PreviewState(rawValue: "ready")
    public static let failing = PreviewState(rawValue: "failing")
    public static let startingDevServer = PreviewState(rawValue: "starting_dev_server")
    public static let wakingSandbox = PreviewState(rawValue: "waking_sandbox")
    public static let waitingForSlot = PreviewState(rawValue: "waiting_for_slot")
    public static let noManifest = PreviewState(rawValue: "no_manifest")
    public static let invalidManifest = PreviewState(rawValue: "invalid_manifest")
    public static let destroyed = PreviewState(rawValue: "destroyed")
    public static let closed = PreviewState(rawValue: "closed")
    public static let localMachine = PreviewState(rawValue: "local_machine")

    /// waking_sandbox, starting_dev_server, waiting_for_slot. Unknown values are not transitional.
    public var isTransitional: Bool {
        self == .wakingSandbox || self == .startingDevServer || self == .waitingForSlot
    }
}

/// What a message asks Yak to do about the preview.
public struct MessageIntent: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let fixPreview = MessageIntent(rawValue: "fix_preview")
    public static let addPreviewManifest = MessageIntent(rawValue: "add_preview_manifest")
}

public struct PreviewSetup: Codable, Equatable, Sendable {
    public let intent: MessageIntent?
    public let isRunning: Bool
    public let endedWithoutManifest: Bool

    public init(intent: MessageIntent?, isRunning: Bool, endedWithoutManifest: Bool) {
        self.intent = intent
        self.isRunning = isRunning
        self.endedWithoutManifest = endedWithoutManifest
    }

    static let empty = PreviewSetup(intent: nil, isRunning: false, endedWithoutManifest: false)
}

public struct PreviewStatus: Codable, Equatable, Sendable {
    public let state: PreviewState
    public let steps: [String]
    public let log: [String]
    public let error: String?
    /// Kept as text; `PreviewNavigation` and `PreviewSession` decide whether it may load.
    public let url: String?
    public let port: Int?
    public let coldStartCommand: String?
    public let setup: PreviewSetup

    public init(
        state: PreviewState, steps: [String], log: [String], error: String?, url: String?, port: Int?,
        coldStartCommand: String?, setup: PreviewSetup
    ) {
        self.state = state
        self.steps = steps
        self.log = log
        self.error = error
        self.url = url
        self.port = port
        self.coldStartCommand = coldStartCommand
        self.setup = setup
    }

    private enum CodingKeys: String, CodingKey {
        case state, steps, log, error, url, port, coldStartCommand, setup
    }

    /// A missing or null `setup` decodes as empty, so a server without that field still decodes.
    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        state = try container.decode(PreviewState.self, forKey: .state)
        steps = try container.decode([String].self, forKey: .steps)
        log = try container.decode([String].self, forKey: .log)
        error = try container.decode(String?.self, forKey: .error)
        url = try container.decode(String?.self, forKey: .url)
        port = try container.decode(Int?.self, forKey: .port)
        coldStartCommand = try container.decode(String?.self, forKey: .coldStartCommand)
        setup = try container.decodeIfPresent(PreviewSetup.self, forKey: .setup) ?? .empty
    }

    /// "Try again" shows starting_dev_server locally.
    public func with(state: PreviewState) -> PreviewStatus {
        PreviewStatus(
            state: state, steps: steps, log: log, error: error, url: url, port: port,
            coldStartCommand: coldStartCommand, setup: setup)
    }
}

public struct PreviewTicket: Codable, Equatable, Sendable {
    public let url: String
    public let expiresAt: String

    public init(url: String, expiresAt: String) {
        self.url = url
        self.expiresAt = expiresAt
    }
}

/// The texts the web sends with each intent; sent to Yak, never shown as UI copy.
public enum PreviewRequests {
    public static let fixText = "The preview did not start. Please find out why and fix it."
    public static let addManifestText =
        "Please set up the preview: add a .yak/preview.yml and check that the app starts."
}
