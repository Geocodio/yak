import Foundation
import SwiftUI
import YakKit

enum ThreadHandoff {
    /// Sets only the web address and the eligibility flags; anything else the system filled in is cleared.
    static func configure(_ activity: NSUserActivity, webURL: URL) {
        activity.webpageURL = webURL
        activity.isEligibleForHandoff = true
        activity.isEligibleForSearch = false
        activity.isEligibleForPrediction = false
        activity.isEligibleForPublicIndexing = false
        activity.title = nil
        activity.userInfo = nil
        activity.keywords = []
        activity.needsSave = true
    }

    /// The activity is advertised while the thread is visible and the app is not locked.
    static func isActive(webURL: URL?, isVisible: Bool, isLocked: Bool) -> Bool {
        webURL != nil && isVisible && !isLocked
    }
}

extension View {
    /// Advertises the thread's web page to the person's other devices while `isActive`.
    func threadHandoff(webURL: URL?, isActive: Bool) -> some View {
        userActivity(NSUserActivityTypeBrowsingWeb, isActive: webURL != nil && isActive) { activity in
            if let webURL { ThreadHandoff.configure(activity, webURL: webURL) }
        }
    }
}
