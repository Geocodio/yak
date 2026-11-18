import Foundation
import Testing
import YakKit

@testable import Yak

@Suite struct ThreadHandoffTests {
    private let address = URL(string: "https://yak.example.com/threads/d0e515ef")!

    private func filled() -> NSUserActivity {
        let activity = NSUserActivity(activityType: NSUserActivityTypeBrowsingWeb)
        activity.title = "Private notes on billing"
        activity.userInfo = ["title": "Private notes on billing"]
        activity.keywords = ["billing"]
        activity.isEligibleForSearch = true
        activity.isEligibleForPrediction = true
        activity.isEligibleForPublicIndexing = true
        return activity
    }

    @Test func theActivityCarriesOnlyTheWebURL() {
        let activity = filled()
        ThreadHandoff.configure(activity, webURL: address)
        #expect(activity.webpageURL == address)
        #expect(activity.title == nil)
        #expect(activity.userInfo?.isEmpty ?? true)
        #expect(activity.keywords.isEmpty)
        #expect(activity.isEligibleForHandoff)
        #expect(!activity.isEligibleForSearch)
        #expect(!activity.isEligibleForPrediction)
        #expect(!activity.isEligibleForPublicIndexing)
    }

    @Test func noURLMeansNoActivity() {
        let invalid = HandoffLink.webURL(instance: URL(string: "https://yak.example.com")!, threadID: "../x")
        #expect(invalid == nil)
        #expect(!ThreadHandoff.isActive(webURL: invalid, isVisible: true, isLocked: false))
        #expect(ThreadHandoff.isActive(webURL: address, isVisible: true, isLocked: false))
        #expect(!ThreadHandoff.isActive(webURL: address, isVisible: false, isLocked: false))
        #expect(!ThreadHandoff.isActive(webURL: address, isVisible: true, isLocked: true))
    }

    @Test func privateThreadsAreTheSame() throws {
        let url = try #require(
            HandoffLink.webURL(instance: URL(string: "https://yak.example.com")!, threadID: "a1b2c3d4"))
        let activity = filled()
        ThreadHandoff.configure(activity, webURL: url)
        #expect(activity.webpageURL?.absoluteString == "https://yak.example.com/threads/a1b2c3d4")
        #expect(activity.title == nil && activity.keywords.isEmpty)
    }
}
