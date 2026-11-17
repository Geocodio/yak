import Foundation
import Testing
import YakKit

@testable import Yak

@MainActor @Suite struct SettingsPrivacySectionTests {
    private let british = Locale(identifier: "en_GB")

    private func device(name: String = "iPhone", pairedAt: String? = "2026-11-12T09:30:00Z") throws -> Me.DeviceInfo {
        let date = pairedAt.map { ", \"pairedAt\": \"\($0)\"" } ?? ""
        let json = "{\"id\": 1, \"name\": \"\(name)\", \"kind\": \"phone\"\(date)}"
        return try JSONDecoder().decode(Me.DeviceInfo.self, from: Data(json.utf8))
    }

    @Test func theLockTitleFollowsTheMethod() {
        #expect(PrivacyRows.lockTitle(.faceID) == "Require Face ID")
        #expect(PrivacyRows.lockTitle(.touchID) == "Require Touch ID")
        #expect(PrivacyRows.lockTitle(.opticID) == "Require Optic ID")
        #expect(PrivacyRows.lockTitle(.passcode) == "Require passcode")
        #expect(PrivacyRows.lockTitle(.deviceUnlock) == "Require device unlock")
        #expect(PrivacyRows.lockSubtitle(hasMethod: true) == "To open Yak")
        #expect(PrivacyRows.lockSubtitle(hasMethod: false).hasPrefix("Set a device passcode"))
    }

    @Test func theSpotlightRowIsDisabledWhileLocked() {
        #expect(
            PrivacyRows.spotlightSubtitle(includesPrivate: true, lockIsOn: true).hasPrefix("Off while app lock is on"))
        #expect(
            PrivacyRows.spotlightSubtitle(includesPrivate: false, lockIsOn: false).hasPrefix("Search shows the titles"))
        #expect(
            PrivacyRows.spotlightSubtitle(includesPrivate: true, lockIsOn: false)
                == "Search also shows private thread titles.")
    }

    @Test func theSpotlightSwitchShowsOffWhileLocked() {
        #expect(!PrivacyRows.spotlightSwitchValue(stored: true, lockIsOn: true))
        #expect(PrivacyRows.spotlightSwitchValue(stored: true, lockIsOn: false))
        #expect(!PrivacyRows.spotlightSwitchValue(stored: false, lockIsOn: false))
    }

    @Test func theDeviceSubtitleHasEveryForm() throws {
        let dated = ThisDeviceLine.make(device: try device())
        #expect(
            PrivacyRows.deviceSubtitle(line: dated, hasLoaded: true, locale: british)
                == "Paired 12 Nov. Remove it on the web.")
        let undated = ThisDeviceLine.make(device: try device(pairedAt: nil))
        #expect(
            PrivacyRows.deviceSubtitle(line: undated, hasLoaded: true, locale: british)
                == "Paired. Remove it on the web.")
        #expect(PrivacyRows.deviceSubtitle(line: undated, hasLoaded: false, locale: british) == "Remove it on the web.")
        for text in [
            PrivacyRows.deviceSubtitle(line: dated, hasLoaded: true, locale: british),
            PrivacyRows.deviceSubtitle(line: undated, hasLoaded: true, locale: british),
        ] {
            #expect(!text.contains("iPhone"))
        }
    }

    @Test func theDebugRowFollowsTheBuildKind() {
        #expect(!PrivacyRows.showsDebugRow(kind: nil))
        #expect(!PrivacyRows.showsDebugRow(kind: .appStore))
        #expect(!PrivacyRows.showsDebugRow(kind: .unknown))
        #expect(PrivacyRows.showsDebugRow(kind: .testFlight))
        #expect(PrivacyRows.showsDebugRow(kind: .debug))
    }

    @Test func theVersionReadsBothKeys() {
        #expect(PrivacyRows.versionText(short: "0.1.0", build: "1") == "Version 0.1.0 (1)")
        #expect(PrivacyRows.versionText(short: nil, build: "1") == "Version unknown")
    }
}
