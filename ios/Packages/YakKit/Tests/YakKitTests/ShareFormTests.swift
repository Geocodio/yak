import Foundation
import Testing

@testable import YakKit

@Test func shareFormNeverLosesAChoice() throws {
    var form = ShareForm(share: try decoded(ThreadShare.self, "scenario-overview-share"))
    #expect(form.mode == .people && form.selected == [LenientID("7")] && !form.hasChanges && !form.canSave)
    form.toggle(LenientID("6"))
    #expect(form.selected == [LenientID("7")])
    form.toggle(LenientID("99"))
    #expect(form.selected == [LenientID("7")])
    form.toggle(LenientID("7"))
    #expect(form.problem == .pickSomeone && !form.canSave)
    form.toggle(LenientID("8"))
    #expect(form.canSave && form.request.userIDs == [8])
    form.mode = .everyone
    #expect(form.canSave && form.request.userIDs.isEmpty && form.request.mode == .everyone)
    form.mode = .people
    #expect(form.selected == [LenientID("8")])
    form.mode = .onlyMe
    #expect(form.canSave && form.problem == nil)
}

@Test func shareSearchIgnoresCaseAndAccents() throws {
    let share = ThreadShare(
        mode: .onlyMe, userIds: [],
        people: [
            SharePerson(id: LenientID("6"), name: "Test Person", role: "Owner"),
            SharePerson(id: LenientID("7"), name: "Sécond Person"),
            SharePerson(id: LenientID("8"), name: "Third Person"),
        ])
    let form = ShareForm(share: share)
    #expect(form.filtered(by: "second").map(\.id) == [LenientID("7")])
    #expect(form.filtered(by: "SÉCOND").map(\.id) == [LenientID("7")])
    #expect(form.filtered(by: "  ").count == 3)
    #expect(!form.isSelectable(share.people[0]) && form.isSelectable(share.people[1]))
}

@Test func anUnknownModeCannotBeSaved() throws {
    let share = ThreadShare(mode: ShareMode(rawValue: "org_only"), userIds: [], people: [])
    var form = ShareForm(share: share)
    #expect(form.problem == .unknownMode && !form.canSave)
    form.mode = .everyone
    #expect(form.canSave)
}
