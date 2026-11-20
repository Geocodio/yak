import Foundation
import Testing
import YakKit

@testable import Yak

@MainActor @Suite struct QueuedChipsModelTests {
    @Test func anActionMarksTheMessageBusyAndEndsWithoutAFailure() async {
        let model = QueuedChipsModel()
        var gate: CheckedContinuation<Void, Never>?
        let running = Task {
            await model.run("u1") {
                await withCheckedContinuation { gate = $0 }
                return .done
            }
        }
        #expect(await TestTimeScale.poll { gate != nil })
        #expect(model.busy == ["u1"])
        gate?.resume()
        await running.value
        #expect(model.busy.isEmpty && model.failures["u1"] == nil)
    }

    @Test func aSecondActionOnABusyMessageIsIgnored() async {
        let model = QueuedChipsModel()
        var gate: CheckedContinuation<Void, Never>?
        var runs = 0
        let first = Task {
            await model.run("u1") {
                runs += 1
                await withCheckedContinuation { gate = $0 }
                return .done
            }
        }
        #expect(await TestTimeScale.poll { gate != nil })
        await model.run("u1") {
            runs += 1
            return .done
        }
        #expect(runs == 1)
        gate?.resume()
        await first.value
    }

    @Test func aRefusalKeepsTheServersWordsUntilTheNextAction() async {
        let model = QueuedChipsModel()
        await model.run("u1") { .refused(code: "no", message: "Yak already read it.", fieldErrors: [:]) }
        #expect(model.failures["u1"] == "Yak already read it.")
        await model.run("u1") { .done }
        #expect(model.failures["u1"] == nil)
    }

    @Test func eachKindOfFailureHasWords() async {
        let model = QueuedChipsModel()
        await model.run("a") { .failed(.offline) }
        await model.run("b") { .failed(.server(code: "queue_broken", message: "The queue is broken.")) }
        await model.run("c") { .alreadySent }
        #expect(model.failures["a"] == String(localized: "chat.action.offline"))
        #expect(model.failures["b"] == "The queue is broken.")
        #expect(model.failures["c"] == String(localized: "chat.message.alreadySent"))
    }

    @Test func messagesThatLeftTheQueueAreForgotten() async {
        let model = QueuedChipsModel()
        await model.run("gone") { .failed(.offline) }
        model.toggleText("gone")
        model.toggleText("stays")
        model.keep(["stays"])
        #expect(model.failures.isEmpty && model.expandedTexts == ["stays"])
        model.toggleList()
        #expect(model.isListExpanded)
    }
}
