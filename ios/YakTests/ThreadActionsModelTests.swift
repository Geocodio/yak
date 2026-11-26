import Foundation
import Testing
import YakKit

@testable import Yak

@MainActor struct ThreadActionsModelTests {
    private func confirmation(
        _ kind: ThreadActionConfirmation.Kind, subThreads: Int = 0, branch: String? = nil, pullRequest: Int? = nil
    ) -> ThreadActionConfirmation {
        ThreadActionConfirmation(
            kind: kind, surface: .header, threadID: "t0pic001", subThreadCount: subThreads, branch: branch,
            pullRequestNumber: pullRequest)
    }

    @Test func theCloseQuestionSaysItCannotBeReopened() {
        let plain = confirmation(.close)
        #expect(plain.title == "Close this thread?")
        #expect(plain.message == "Its sandbox is destroyed. You can still read it. It cannot be reopened.")
        #expect(plain.confirmLabel == "Close thread")
    }

    @Test func theCloseQuestionNamesTheSubThreadsThatCloseToo() {
        #expect(
            confirmation(.close, subThreads: 1).message
                == "Its sandbox is destroyed and its 1 sub-thread closes too. You can still read it. It cannot be reopened."
        )
        #expect(
            confirmation(.close, subThreads: 2).message
                == "Its sandbox is destroyed and its 2 sub-threads close too. You can still read it. It cannot be reopened."
        )
    }

    @Test func theDeleteQuestionNamesWhatGoesAndWhatStays() {
        let drawn = confirmation(.delete, subThreads: 2, branch: "yak/search-index", pullRequest: 2301)
        #expect(drawn.title == "Delete this thread?")
        #expect(
            drawn.message
                == "Its messages, sandbox, runs and attachments, and its 2 sub-threads, are removed for good. The branch yak/search-index and pull request #2301 on GitHub stay."
        )
        #expect(drawn.confirmLabel == "Delete thread")
    }

    @Test func theDeleteQuestionStaysTrueWhenNothingIsKnownAboutGitHub() {
        #expect(
            confirmation(.delete).message
                == "Its messages, sandbox, runs and attachments are removed for good. The branch and any pull request on GitHub stay."
        )
        #expect(
            confirmation(.delete, branch: "yak/x").message
                == "Its messages, sandbox, runs and attachments are removed for good. The branch yak/x on GitHub stays."
        )
        #expect(
            confirmation(.delete, pullRequest: 7).message
                == "Its messages, sandbox, runs and attachments are removed for good. Pull request #7 on GitHub stays.")
    }

    @Test func everyFailureHasItsOwnWords() {
        let cases: [(ThreadLifecycleFailure, ThreadLifecycleAction, String)] = [
            (.offline, .close, "You are offline. Try again when the connection returns."),
            (.unauthorized, .star, "This device was signed out. Pair it again to go on."),
            (.forbidden, .delete, "You cannot do this on this thread."),
            (.gone, .close, "This thread no longer exists."),
            (.failed, .star, "Could not change the star. Try again."),
            (.failed, .close, "Could not close the thread. Try again."),
            (.failed, .delete, "Could not delete the thread. Try again."),
        ]
        for (failure, action, words) in cases {
            #expect(ThreadActionsModel.sentence(for: failure, action: action) == words)
        }
    }

    @Test func theServersSentenceIsShownForARefusedDelete() {
        let sentence = "The sandbox could not be destroyed yet. Try again in a moment."
        #expect(
            ThreadActionsModel.sentence(for: .sandboxUnavailable(message: sentence), action: .delete) == sentence)
    }

    @Test func theMenuWordsAreTheBoardsWords() {
        #expect(String(localized: "thread.menu.star") == "Star thread")
        #expect(String(localized: "thread.menu.unstar") == "Remove star")
        #expect(String(localized: "thread.menu.close") == "Close thread")
        #expect(String(localized: "thread.menu.delete") == "Delete thread")
        #expect(String(localized: "thread.menu.copyThreadLink") == "Copy thread link")
        #expect(String(localized: "lifecycle.deleted") == "Thread deleted.")
        #expect(String(localized: "composer.closed.title") == "This thread is closed.")
        #expect(String(localized: "composer.closed.detail") == "You can still read it. To go on, start a new thread.")
    }
}
