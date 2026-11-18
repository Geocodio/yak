import Foundation
import YakKit

/// The sentences Settings shows for push, in one place so each state has exactly one wording.
enum SettingsText {
    /// The line under an instance's name. Working states lead with the host; every problem is the sentence alone.
    static func subtitle(
        status: PushRegistrationStatus?, availability: PushAvailability?, host: String
    ) -> String {
        guard let status else { return fallbackSubtitle(availability: availability, host: host) }
        switch status {
        case .unavailable(let reason): return unavailable(reason, host: host)
        case .waitingForToken: return String(localized: "settings.subtitle.waitingForToken \(host)")
        case .registering: return String(localized: "settings.subtitle.registering \(host)")
        case .waitingForNetwork: return String(localized: "settings.subtitle.waitingForNetwork")
        case .retrying: return String(localized: "settings.subtitle.retrying")
        case .registered: return String(localized: "settings.subtitle.registered \(host)")
        case .refused(let problem):
            if problem == .serverTooOld { return String(localized: "settings.subtitle.serverTooOld") }
            return String(localized: "settings.subtitle.refused \(reason(problem))")
        case .needsPairing: return String(localized: "settings.subtitle.needsPairing")
        }
    }

    private static func fallbackSubtitle(availability: PushAvailability?, host: String) -> String {
        switch availability {
        case nil: host
        case .available?: String(localized: "settings.subtitle.registering \(host)")
        case .some(let reason): unavailable(reason, host: host)
        }
    }

    /// Why an instance cannot push to this build.
    static func unavailable(_ availability: PushAvailability, host: String) -> String {
        switch availability {
        case .available: String(localized: "settings.subtitle.registering \(host)")
        case .noKey: String(localized: "settings.subtitle.noKey")
        case .otherBuild: String(localized: "settings.subtitle.otherBuild")
        case .otherEnvironment(let environment):
            switch environment {
            case .production?: String(localized: "settings.subtitle.productionOnly")
            case .sandbox?: String(localized: "settings.subtitle.sandboxOnly")
            case nil: String(localized: "settings.subtitle.unknownEnvironment")
            }
        }
    }

    /// The reason after "Could not save" and "Push was refused".
    static func reason(_ problem: PushProblem) -> String {
        switch problem {
        case .offline: String(localized: "settings.reason.offline")
        case .unavailable: String(localized: "settings.reason.unavailable")
        case .needsPairing: String(localized: "settings.reason.needsPairing")
        case .environmentMismatch: String(localized: "settings.reason.environmentMismatch")
        case .pushUnavailable: String(localized: "settings.test.pushUnavailable")
        case .noPushToken: String(localized: "settings.test.noPushToken")
        case .appTooOld: String(localized: "signedIn.appTooOld")
        case .serverTooOld: String(localized: "settings.subtitle.serverTooOld")
        case .rateLimited: String(localized: "settings.test.rateLimited")
        case .rejected(_, let message), .invalid(let message), .other(let message):
            message.isEmpty ? String(localized: "settings.reason.generic") : message
        case .threadClosed, .notFound: String(localized: "settings.reason.generic")
        }
    }

    /// The line a refused test notification shows.
    static func test(_ problem: PushProblem) -> String {
        switch problem {
        case .noPushToken: String(localized: "settings.test.noPushToken")
        case .pushUnavailable: String(localized: "settings.test.pushUnavailable")
        case .rejected(_, let message): message.isEmpty ? String(localized: "settings.reason.generic") : message
        case .offline: String(localized: "settings.test.offline")
        case .unavailable: String(localized: "settings.test.unavailable")
        case .rateLimited: String(localized: "settings.test.rateLimited")
        default: reason(problem)
        }
    }
}
