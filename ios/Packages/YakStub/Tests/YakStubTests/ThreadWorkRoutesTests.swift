import Foundation
import Testing

@testable import YakStub

@Suite struct ThreadWorkRoutesTests {
    private static func fixtures() throws -> StubFixtures {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
        return try StubFixtures(
            directory: directory.appendingPathComponent("fixtures"),
            scenariosDirectory: directory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios"))
    }

    /// A feature that is playing `scenario`, and a way to send it requests.
    private struct Play {
        let feature: StubThreadWork
        let scenario: String
        let fixtures: StubFixtures

        init(_ scenario: String, drainDelay: Duration = .seconds(3)) throws {
            self.scenario = scenario
            feature = StubThreadWork(drainDelay: drainDelay)
            fixtures = try ThreadWorkRoutesTests.fixtures()
            feature.reset()
            feature.played(scenario: scenario)
        }

        struct Answer {
            let status: Int
            let body: [String: Any]
            let delay: Duration
            var code: String? { body["error"] as? String }
            var thread: [String: Any] { body["thread"] as? [String: Any] ?? [:] }
            var threadID: String? { thread["id"] as? String }
            var card: String? { body["card"] as? String }
            var requestStatus: String? { (body["request"] as? [String: Any]).flatMap { ($0["status"] as? [String: Any])?["key"] as? String } }
        }

        func send(_ route: String, body: String = "{}") throws -> Answer {
            try #require(answer(route, body: body))
        }

        func answer(_ route: String, body: String = "{}") -> Answer? {
            let context = StubContext(
                scenario: scenario, baseURL: "http://127.0.0.1", fixtures: fixtures, threads: StubThreads(),
                core: { .reply(status: 404, contentType: "application/json", body: Data("{}".utf8)) })
            let method = String(route.prefix { $0 != " " })
            let request = StubRequest(method: method, path: "x", body: Data(body.utf8))
            guard let reply = feature.answer(route: route, request: request, context: context),
                case .reply(let status, _, let data) = reply.answer
            else { return nil }
            let object = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any] ?? [:]
            return Answer(status: status, body: object, delay: reply.delay)
        }

        func show(_ id: String = StubThreadWork.threadID) throws -> Answer { try send("GET threads/\(id)") }
        func post(_ verb: String, body: String = "{}") throws -> Answer {
            try send("POST threads/\(StubThreadWork.threadID)/\(verb)", body: body)
        }
    }

    @Test func aStoppedThreadShowsItsCardAndRetryAnswersAWorkingShow() throws {
        let play = try Play("work-stopped")
        let before = try play.show()
        #expect(before.card == "stopped" && before.requestStatus == "stopped")
        let retried = try play.post("retry")
        #expect(retried.status == 200 && retried.body["status"] as? String == "queued" && retried.card == nil)
        #expect(retried.requestStatus == "working")
        #expect(try play.show().requestStatus == "working")
    }

    @Test func theFirstRetryIsRefusedWhenTheScenarioSaysSo() throws {
        let play = try Play("work-stopped-retry-fails")
        let first = try play.post("retry")
        #expect(first.status == 409 && first.code == "task_not_retryable")
        #expect(try play.show().card == "stopped")
        #expect(try play.post("retry").status == 200)
        let setUp = try Play("work-stopped-retry-fails")
        #expect(try setUp.post("setup-and-continue").status == 409)
    }

    @Test func aReplyToAStoppedThreadAnswersANoticeAndTheShowGainsARetryRow() throws {
        let play = try Play("work-stopped-reply")
        let answer = try play.send("POST threads/d0e515ef/messages", body: #"{"message":"Try the other branch"}"#)
        #expect(answer.status == 200 && answer.body["status"] as? String == "sent")
        #expect(answer.body["notice"] as? String == "Trying again with your note." && answer.body["uuid"] is NSNull)
        let messages = try #require(try play.show().body["messages"] as? [[String: Any]])
        #expect(messages.contains { $0["isRetry"] as? Bool == true })
        #expect(messages.contains { $0["text"] as? String == "Try the other branch" })
    }

    @Test func theExpiredCoOwnerStopOffersNoRetry() throws {
        let show = try Play("work-expired-co-owner").show()
        let stopped = try #require(show.body["stopped"] as? [String: Any])
        #expect(stopped["kind"] as? String == "needs_you")
        #expect((stopped["summary"] as? String)?.hasPrefix("Nobody decided within 7 days") == true)
        #expect((show.body["actions"] as? [String: Any])?["canRetry"] as? Bool == false)
    }

    @Test func approvingTakesThreeSecondsAndDecidesTheRequest() throws {
        let play = try Play("work-co-owner-decide")
        let before = try play.show()
        #expect(before.card == "co_owner")
        let approved = try play.post("co-owner/approve")
        #expect(approved.body["status"] as? String == "approved" && approved.delay == .seconds(3))
        #expect(approved.card == nil && (approved.body["coOwner"] as? [String: Any])?["status"] as? String == "approved")
        #expect(try play.show().card == nil)
        for (verb, word) in [
            ("co-owner/request-changes", "changes_requested"), ("co-owner/reject", "rejected"),
            ("co-owner/withdraw", "withdrawn"),
        ] {
            let answer = try Play("work-co-owner-decide").post(verb, body: #"{"feedback":"x"}"#)
            #expect(answer.body["status"] as? String == word && (answer.body["coOwner"] as? [String: Any])?["status"] as? String == word)
        }
    }

    @Test func aSlowApproveAnswersAfterTwentySeconds() throws {
        let answer = try Play("work-slow-approve").post("co-owner/approve")
        #expect(answer.delay == .seconds(20) && answer.body["status"] as? String == "approved")
    }

    @Test func anUnlinkedViewerIsToldToLinkGitHub() throws {
        let play = try Play("work-co-owner-link")
        let viewer = try #require((try play.show().body["coOwner"] as? [String: Any])?["viewer"] as? [String: Any])
        #expect(viewer["isLinked"] as? Bool == false && viewer["access"] as? String == "unlinked")
        let refused = try play.post("co-owner/approve")
        #expect(refused.status == 409 && refused.code == "not_linked")
        #expect((refused.body["details"] as? [String: Any])?["connectUrl"] as? String == "https://yak.example.com/connect/github")
        #expect(try play.post("co-owner/check-access").body["status"] as? String == "checked")
    }

    @Test func theRequesterCanOnlyWithdraw() throws {
        let play = try Play("work-co-owner-requester")
        let panel = try #require(try play.show().body["coOwner"] as? [String: Any])
        #expect((panel["viewer"] as? [String: Any])?["isRequester"] as? Bool == true)
        #expect(try play.post("co-owner/withdraw").body["status"] as? String == "withdrawn")
    }

    @Test func aReaderCannotDecide() throws {
        let show = try Play("work-co-owner-reader").show()
        #expect(show.body["canDrive"] as? Bool == false)
        #expect((show.body["coOwner"] as? [String: Any])?["canDecide"] as? Bool == false)
    }

    @Test func theEarlyWarningListsCandidatesInTwoSteps() throws {
        let play = try Play("work-early-warning")
        #expect(try play.show().card == "early_warning")
        let route = "GET threads/d0e515ef/co-owner/candidates"
        let first = try play.send(route)
        #expect(first.body["isComplete"] as? Bool == false && (first.body["candidates"] as? [Any])?.count == 1)
        let second = try play.send(route)
        #expect(second.body["isComplete"] as? Bool == true && (second.body["candidates"] as? [Any])?.count == 2)
        let tagged = try play.post("co-owner/tag", body: #"{"user_id":4}"#)
        #expect(tagged.body["status"] as? String == "tagged")
        #expect((tagged.body["earlyWarning"] as? [String: Any])?["taggedName"] as? String == "Second person")
        #expect(try Play("work-early-warning").post("co-owner/decline").card == nil)
    }

    @Test func theRepositoryQuestionIsAnsweredOrTaken() throws {
        let play = try Play("work-repository-question")
        let before = try play.show()
        #expect(before.card == "repository_question" && before.thread["hasRepository"] as? Bool == false)
        let started = try play.post("repository-answer", body: #"{"repo":"example/web"}"#)
        #expect(started.body["status"] as? String == "started" && started.thread["hasRepository"] as? Bool == true)
        #expect(started.thread["repo"] as? String == "example/web" && started.card == nil)
        let taken = try Play("work-repository-taken").post("repository-answer", body: #"{"repo":"example/web"}"#)
        #expect(taken.status == 409 && taken.code == "repository_already_picked")
    }

    @Test func movingAnswersTheNewThreadAndTheOldOneShowsTheMovedNotice() throws {
        let play = try Play("work-move")
        let before = try play.show()
        let targets = (before.body["actions"] as? [String: Any])?["moveTargets"] as? [String]
        #expect(targets == ["example/web"])
        let moved = try play.post("move", body: #"{"repo":"example/web"}"#)
        #expect(moved.body["status"] as? String == "moved" && moved.threadID == "m0v3d000")
        let old = try play.show()
        let notice = try #require(old.body["notice"] as? [String: Any])
        #expect(notice["kind"] as? String == "moved" && notice["threadId"] as? String == "m0v3d000")
        #expect(old.thread["status"] as? String == "closed")
        #expect(try play.show("m0v3d000").threadID == "m0v3d000")
    }

    @Test func theMilestoneThreadHasMilestonesAndARetryRow() throws {
        let show = try Play("work-milestones").show()
        let milestones = try #require(show.body["milestones"] as? [[String: Any]])
        #expect(milestones.map { $0["key"] as? String } == ["pushed", "ci_failed", "pushed", "ci_passed", "pull_request_opened", "walkthrough_ready"])
        #expect((show.body["messages"] as? [[String: Any]])?.contains { $0["isRetry"] as? Bool == true } == true)
    }

    @Test func theChannelThreadHasMessagesFromSlack() throws {
        let show = try Play("work-via").show()
        let messages = try #require(show.body["messages"] as? [[String: Any]])
        #expect(messages.compactMap { ($0["via"] as? [String: Any])?["key"] as? String } == ["slack", "slack"])
        #expect(messages.last?["isReply"] as? Bool == true)
    }

    @Test func theLegacyThreadAnswersTheContinuingThreadOnSend() throws {
        let play = try Play("work-legacy")
        let show = try play.show()
        #expect(show.body["isLegacy"] as? Bool == true && (show.body["legacyAnswers"] as? [Any])?.count == 1)
        let sent = try play.send("POST threads/d0e515ef/messages", body: #"{"message":"Carry on"}"#)
        #expect(sent.body["threadId"] as? String == "c0n71nue" && sent.body["uuid"] is NSNull)
        #expect(try play.show("c0n71nue").threadID == "c0n71nue")
    }

    @Test func theQueuedNoteIsTakenAfterTheFirstSubscription() async throws {
        let play = try Play("work-drained", drainDelay: .milliseconds(20))
        let published = Locked<[String]>([])
        play.feature.attach { _, json in published.mutate { $0.append(json) } }
        let before = try play.show()
        #expect((before.body["messages"] as? [[String: Any]])?.last?["id"] as? String == "queued-message-1")
        play.feature.subscribed(thread: "d0e515ef", scenario: "work-drained", fixtures: play.fixtures)
        play.feature.subscribed(thread: "d0e515ef", scenario: "work-drained", fixtures: play.fixtures)
        for _ in 0..<2000 where published.get().isEmpty { try await Task.sleep(for: .milliseconds(5)) }
        try await Task.sleep(for: .milliseconds(50))
        #expect(published.get().count == 1 && published.get()[0].contains("\"showKey\":\"drained00001\""))
        let after = try play.show()
        let last = try #require((after.body["messages"] as? [[String: Any]])?.last)
        #expect(last["id"] as? String == "turn-message-7" && last["uuid"] as? String == "00000000-0000-4000-8000-0000000000aa")
        #expect(after.body["latestSequence"] as? Int == 15)
    }

    @Test func theOfflineScenarioPlaysTheStoppedCard() throws {
        #expect(try Play("work-offline").show().card == "stopped")
    }

    @Test func otherScenariosAreLeftToTheCore() throws {
        let play = try Play("question-open")
        #expect(play.answer("GET threads/d0e515ef") == nil && play.answer("POST threads/d0e515ef/retry") == nil)
        let work = try Play("work-stopped")
        #expect(work.answer("GET threads/d0e515ef/events") == nil)
        #expect(work.answer("GET threads/zzzzzzzz") == nil)
    }

    @Test func aCancelAnswersAStoppedShowWithNoActions() throws {
        let cancelled = try Play("work-stopped").post("cancel")
        #expect(cancelled.body["status"] as? String == "cancelled")
        #expect((cancelled.body["stopped"] as? [String: Any])?["kind"] as? String == "cancelled")
        #expect((cancelled.body["actions"] as? [String: Any])?["canRetry"] as? Bool == false)
        let review = try Play("work-move").post("request-review")
        #expect(review.status == 409 && review.code == "review_not_requested")
    }

    @Test func theWorkFeatureAnswersBeforeTheChangesFeature() {
        let names = StubFeatures.all.map(\.name)
        #expect(names.firstIndex(of: "work")! < names.firstIndex(of: "changes")!)
    }
}
