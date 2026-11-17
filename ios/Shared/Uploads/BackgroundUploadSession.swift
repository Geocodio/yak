import Foundation
import YakKit

#if canImport(os)
    import os
#endif

/// A background `URLSession` and its delegate. Forwards every event to the ledger that owns the task's description,
/// in the order the session reported them. Compiled into the app and the share extension.
final class BackgroundUploadSession: NSObject, UploadSession, URLSessionDataDelegate, @unchecked Sendable {
    /// What the session reports, read by one consumer so a completion never overtakes the data before it.
    enum Event: Sendable {
        case sent(UploadTaskDescription, bytes: Int64, total: Int64)
        case received(UploadTaskDescription, Data)
        case completed(
            UploadTaskDescription, session: String, task: Int, status: Int?, headers: [String: String], failed: Bool)
        case finishedEvents
    }

    let identifier: String
    private let files: DiskFileStore
    private let route: @Sendable (UploadTaskDescription) async -> UploadLedger?
    private nonisolated(unsafe) var session: URLSession!
    private let eventStream: AsyncStream<Event>.Continuation
    private let consumer: Task<Void, Never>
    private let finished: OSAllocatedUnfairLock<(@Sendable () -> Void)?>

    #if DEBUG
        private let failures = OSAllocatedUnfairLock(initialState: 0)

        /// `-yakTestUploadFailure YES`: the next completions reported are reported as failed in transport, so a test
        /// reaches the failure line without depending on how the system daemon retries a dropped request.
        func failNextCompletions(_ count: Int) { failures.withLock { $0 = count } }
    #endif

    /// Set by `handleEvents`; called once when the system has delivered every event of a wake.
    var finishedEvents: (@Sendable () -> Void)? {
        get { finished.withLock { $0 } }
        set { finished.withLock { $0 = newValue } }
    }

    init(
        identifier: String, appGroup: String?, files: DiskFileStore,
        finishedEvents: (@Sendable () -> Void)? = nil,
        route: @escaping @Sendable (UploadTaskDescription) async -> UploadLedger?
    ) {
        self.finished = OSAllocatedUnfairLock(initialState: finishedEvents)
        self.identifier = identifier
        self.files = files
        self.route = route
        let (stream, continuation) = AsyncStream.makeStream(of: Event.self, bufferingPolicy: .unbounded)
        self.eventStream = continuation
        let finished = self.finished
        self.consumer = Task {
            for await event in stream {
                if case .finishedEvents = event {
                    let call = finished.withLock { value -> (@Sendable () -> Void)? in
                        defer { value = nil }
                        return value
                    }
                    call?()
                } else {
                    await Self.deliver(event, to: route)
                }
            }
        }
        super.init()
        session = URLSession(
            configuration: Self.configuration(identifier: identifier, appGroup: appGroup), delegate: self,
            delegateQueue: nil)
    }

    /// Uploads outlive the app: no total limit below a day, a 60 second idle limit, no cookies and no cache.
    static func configuration(identifier: String, appGroup: String?) -> URLSessionConfiguration {
        let configuration = URLSessionConfiguration.background(withIdentifier: identifier)
        if let appGroup { configuration.sharedContainerIdentifier = appGroup }
        configuration.sessionSendsLaunchEvents = true
        configuration.isDiscretionary = false
        configuration.allowsCellularAccess = true
        configuration.allowsConstrainedNetworkAccess = true
        configuration.allowsExpensiveNetworkAccess = true
        configuration.timeoutIntervalForRequest = 60
        configuration.timeoutIntervalForResource = 86_400
        configuration.httpCookieStorage = nil
        configuration.httpShouldSetCookies = false
        configuration.urlCache = nil
        configuration.requestCachePolicy = .reloadIgnoringLocalCacheData
        return configuration
    }

    var configuration: URLSessionConfiguration { session.configuration }

    // MARK: UploadSession

    func start(_ request: URLRequest, bodyPath: String, description: UploadTaskDescription)
        async throws(UploadStartProblem) -> Int
    {
        let location: URL
        do { location = try files.fileURL(bodyPath) } catch { throw .fileMissing }
        guard FileManager.default.fileExists(atPath: location.path) else { throw .fileMissing }
        let task = session.uploadTask(with: request, fromFile: location)
        task.taskDescription = description.text
        task.resume()
        return task.taskIdentifier
    }

    func liveTasks() async -> Set<UploadTaskDescription> {
        Set(await session.allTasks.compactMap { $0.taskDescription.flatMap(UploadTaskDescription.init) })
    }

    /// `finishTasksAndInvalidate`: running tasks finish, then the session goes away.
    func finishAndInvalidate() { session.finishTasksAndInvalidate() }

    /// `invalidateAndCancel`, for a removed instance.
    func cancelAll() { session.invalidateAndCancel() }

    // MARK: Delegate

    func urlSession(
        _ session: URLSession, task: URLSessionTask, didSendBodyData bytesSent: Int64, totalBytesSent: Int64,
        totalBytesExpectedToSend: Int64
    ) {
        guard let description = describe(task) else { return }
        eventStream.yield(.sent(description, bytes: totalBytesSent, total: totalBytesExpectedToSend))
    }

    func urlSession(_ session: URLSession, dataTask: URLSessionDataTask, didReceive data: Data) {
        guard let description = describe(dataTask) else { return }
        eventStream.yield(.received(description, data))
    }

    func urlSession(_ session: URLSession, task: URLSessionTask, didCompleteWithError error: (any Error)?) {
        guard let description = describe(task) else { return }
        let response = task.response as? HTTPURLResponse
        var headers: [String: String] = [:]
        for (key, value) in response?.allHeaderFields ?? [:] {
            if let key = key as? String, let value = value as? String { headers[key] = value }
        }
        var failed = error != nil
        #if DEBUG
            let isForced = failures.withLock { remaining -> Bool in
                guard remaining > 0 else { return false }
                remaining -= 1
                return true
            }
            if isForced { failed = true }
        #endif
        eventStream.yield(
            .completed(
                description, session: identifier, task: task.taskIdentifier, status: response?.statusCode,
                headers: headers, failed: failed))
    }

    func urlSessionDidFinishEvents(forBackgroundURLSession session: URLSession) {
        eventStream.yield(.finishedEvents)
    }

    /// A task without a description that parses is logged by type and its events dropped.
    private func describe(_ task: URLSessionTask) -> UploadTaskDescription? {
        if let text = task.taskDescription, let description = UploadTaskDescription(text) { return description }
        #if canImport(os)
            Logger(subsystem: "Yak", category: "Uploads").error("an upload event had no usable description")
        #endif
        return nil
    }

    private static func deliver(
        _ event: Event, to route: @Sendable (UploadTaskDescription) async -> UploadLedger?
    ) async {
        switch event {
        case .sent(let description, let bytes, let total):
            await route(description)?.sent(description, bytes: bytes, of: total)
        case .received(let description, let data):
            await route(description)?.received(description, data: data)
        case .completed(let description, let session, let task, let status, let headers, let failed):
            await route(description)?.completed(
                description, session: session, task: task, status: status, headers: headers, failed: failed)
        case .finishedEvents: break
        }
    }

    deinit { consumer.cancel() }
}
