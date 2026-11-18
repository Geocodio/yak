import Foundation
import YakKit

extension PushBuild {
    /// This build's bundle identifier and push environment, from the Info.plist keys `YakPushBundleID` and
    /// `YakPushEnvironment`. Nil when either is missing, so the app then treats every instance as able to push.
    static func current(bundle: Bundle = .main) -> PushBuild? {
        guard let bundleID = bundle.object(forInfoDictionaryKey: "YakPushBundleID") as? String, !bundleID.isEmpty,
            let name = bundle.object(forInfoDictionaryKey: "YakPushEnvironment") as? String,
            let environment = PushEnvironment(rawValue: name)
        else { return nil }
        return PushBuild(bundleID: bundleID, environment: environment)
    }
}
