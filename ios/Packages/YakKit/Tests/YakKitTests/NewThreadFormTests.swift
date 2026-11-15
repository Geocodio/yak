import Foundation
import Testing

@testable import YakKit

private func options() throws -> NewThreadOptions { try decoded(NewThreadOptions.self, "scenario-thread-new") }

private func form(
    saved: NewThreadDraft? = nil, lastUsed: String? = nil, link: String? = nil, offline: Bool = false
) throws -> NewThreadForm {
    NewThreadForm(options: offline ? nil : try options(), saved: saved, lastUsedRepo: lastUsed, linkRepo: link)
}

struct NewThreadFormTests {
    @Test func formStartsFromTheRepositoryDefaults() throws {
        let form = try form()
        #expect(
            form.repo == "example/api" && form.mode == .fix && form.accountID?.value == "3" && form.model == "sonnet")
        #expect(
            form.draft
                == NewThreadDraft(
                    repo: "example/api", mode: "fix", repositories: [], claudeAccountId: 3, model: "sonnet"))
    }

    @Test func aLinkOrTheLastUsedRepositoryWins() throws {
        #expect(try form(link: "example/web").repo == "example/web")
        #expect(try form(lastUsed: "example/web").repo == "example/web")
        #expect(try form(link: "example/gone").repo == "example/api")
        #expect(try form(saved: NewThreadDraft(repo: "example/web", mode: "research")).mode == .research)
    }

    @Test func formProblems() throws {
        let options = try options()
        var form = try form()
        #expect(form.problem(text: "  ", options: options, access: [:]) == .noText)
        #expect(form.problem(text: "Fix it", options: options, access: [:]) == nil)
        form.addExtraRepository("example/api", role: "context")
        #expect(
            form.problem(text: "Fix it", options: options, access: [:]) == .extraRepositoryRepeated(slug: "example/api")
        )
        form.removeExtraRepository("example/api")
        form.addExtraRepository("example/docs", role: "context")
        let refused = ["example/docs": RepositoryAccess(allowed: false, reason: "No access")]
        #expect(
            form.problem(text: "Fix it", options: options, access: refused)
                == .extraRepositoryRefused(slug: "example/docs", reason: "No access"))
        form.selectRepository("example/web", options: options)
        #expect(
            form.problem(text: "Fix it", options: options, access: [:])
                == .repositoryNotReady(slug: "example/web", step: "Installing dependencies"))
    }

    @Test func anUnusableAccountStopsTheSend() throws {
        let unusable = ClaudeAccount(
            id: LenientID("3"), name: "Test account", kind: "team", isDefault: true, usable: false, status: "expired")
        let base = try options()
        let changed = NewThreadOptions(
            repos: base.repos, picker: AccountPicker(accounts: [unusable], defaultsByRepo: [:]),
            defaultRepo: "example/api", models: base.models)
        let form = NewThreadForm(options: changed, saved: nil, lastUsedRepo: nil, linkRepo: nil)
        #expect(form.accountID == nil || form.accountID?.value == "3")
        var picked = form
        picked.selectAccount(LenientID("3"))
        #expect(picked.problem(text: "Fix it", options: changed, access: [:]) == .accountUnusable(name: "Test account"))
    }

    @Test func offlineWithASavedDraftCanStillSend() throws {
        let saved = NewThreadDraft(repo: "example/api", mode: "fix")
        #expect(try form(saved: saved, offline: true).problem(text: "Fix it", options: nil, access: [:]) == nil)
        #expect(try form(offline: true).problem(text: "Fix it", options: nil, access: [:]) == .optionsNotLoaded)
    }

    @Test func pickedValuesSurviveARepositoryChange() throws {
        let options = try options()
        var picked = try form()
        picked.selectModel("claude-sonnet-5-0")
        picked.selectRepository("example/web", options: options)
        #expect(picked.model == "claude-sonnet-5-0")
        var unpicked = try form()
        unpicked.selectRepository("example/web", options: options)
        #expect(unpicked.model == "sonnet" && unpicked.repo == "example/web")
    }

    @Test func aRepositoryChangeDropsItsExtraEntry() throws {
        var form = try form()
        form.addExtraRepository("example/web", role: "changes")
        form.selectRepository("example/web", options: try options())
        #expect(form.extraRepositories.isEmpty)
    }

    @Test func anAccountIdThatIsNotANumberSendsNone() throws {
        var form = try form()
        form.selectAccount(LenientID("abc"))
        #expect(form.draft?.claudeAccountId == nil)
    }

    @Test func referencesNumberAcrossKinds() {
        #expect(
            ComposerAttachments.references(isImage: [true, false, true], next: 3) == [
                "Image #3", "File #4", "Image #5",
            ])
    }

    @Test(arguments: [
        ("IMG 0001.HEIC", "IMG-0001.HEIC"), ("../../etc/passwd", "-etc-passwd"), ("", "file"),
        ("räksmörgås.pdf", "r-ksm-rg-s.pdf"),
    ])
    func safeFileNames(input: String, expected: String) {
        #expect(ComposerAttachments.safeFileName(input) == expected)
    }

    @Test func longNamesKeepTheirExtension() {
        let name = String(repeating: "a", count: 200) + ".jpeg"
        let safe = ComposerAttachments.safeFileName(name)
        #expect(safe.count == 80 && safe.hasSuffix(".jpeg"))
    }
}
