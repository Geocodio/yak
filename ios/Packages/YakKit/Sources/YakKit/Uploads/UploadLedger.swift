import Foundation

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif
#if canImport(os)
    import os
#endif

private func logUpload(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "UploadLedger").error("\(message, privacy: .public)")
    #endif
}

/// The durable record of every background upload of one instance. It never caches a record: each lookup reads the
/// record file, so a record another process wrote is seen at once.
public actor UploadLedger {
    public static let answerLimit = 65_536
    public static let recordLimit = 65_536
    public static let shareLostAfter: Duration = .seconds(3600)
    public static let preparedLostAfter: Duration = .seconds(120)
    /// The most the ledger writes or reads for one progress update.
    static let progressInterval: Duration = .milliseconds(250)
    /// A stored answer is base64 inside the JSON, so the read bound is larger than the answer it may hold.
    private static let recordReadLimit = recordLimit + answerLimit * 2

    private let instance: UUID
    private let files: any FileStore
    private let clock: any YakClock
    private var buffers: [UploadTaskDescription: Data] = [:]
    private var starting: Set<UUID> = []
    private var progress: [UUID: Double] = [:]
    private var failedItems: Set<UUID> = []
    private var flushTask: Task<Void, Never>?
    private let summaryBroadcast = Broadcast(UploadSummary())
    private var completionReceivers: [Int: AsyncStream<UUID>.Continuation] = [:]
    private var nextReceiver = 0

    public init(instance: UUID, files: any FileStore, clock: any YakClock) {
        self.instance = instance
        self.files = files
        self.clock = clock
    }

    // MARK: Reading

    public func record(for item: UUID) -> UploadRecord? { load(item) }

    public func records() -> [UploadRecord] {
        let names: [String]
        do {
            names = try files.list(InstanceFolders.uploads(instance))
        } catch {
            logUpload("upload folder not listed: \(type(of: error))")
            return []
        }
        return names.compactMap { name -> UploadRecord? in
            guard name.hasSuffix(".json"), let item = UUID(uuidString: String(name.dropLast(5))) else { return nil }
            return load(item)
        }
    }

    /// The item ids that have a record file, readable or not; nil when the folder cannot be listed, so a caller that
    /// must account for every record can stop instead of reading an empty list.
    public func recordedItemIDs() -> Set<UUID>? {
        do {
            let names = try files.list(InstanceFolders.uploads(instance))
            return Set(names.compactMap { $0.hasSuffix(".json") ? UUID(uuidString: String($0.dropLast(5))) : nil })
        } catch {
            logUpload("upload folder not listed: \(type(of: error))")
            return nil
        }
    }

    public func owns(_ description: UploadTaskDescription) -> Bool { load(description.item) != nil }

    /// Records that may go again now: failed ones, share uploads past `shareLostAfter`, prepared past `preparedLostAfter`.
    public func retryable() -> [UploadRecord] {
        let now = clock.now()
        return records().filter { record in
            let age = Duration.seconds(now.timeIntervalSince(record.updatedAt))
            switch record.phase {
            case .failed: return true
            case .uploading: return record.origin == .share && age >= Self.shareLostAfter
            case .prepared: return age >= Self.preparedLostAfter
            case .answered: return false
            }
        }
    }

    public func outcome(for item: UUID) -> UploadOutcome? {
        guard let record = load(item),
            case .answered(let status, let body, let protocolHeader, let retryAfter) = record.phase
        else { return nil }
        var headers: [String: String] = [:]
        if let protocolHeader { headers[ClientProtocol.headerName] = protocolHeader }
        if let retryAfter { headers["Retry-After"] = retryAfter }
        guard let url = URL(string: "https://yak.invalid/api/app/v1/\(record.path)"),
            let response = HTTPURLResponse(url: url, statusCode: status, httpVersion: nil, headerFields: headers)
        else { return nil }
        let wasCut = body.count > Self.answerLimit
        do throws(APIFailure) {
            if wasCut { throw .notYak }
            if record.path == "threads" {
                let created: ThreadCreated = try APIClient.interpret(data: body, response: response)
                return .delivered(status: .sent, messageUUID: nil, threadID: created.id)
            }
            let result: MessageSendResult = try APIClient.interpret(data: body, response: response)
            return .delivered(status: result.status, messageUUID: result.uuid, threadID: result.threadId)
        } catch {
            return .decision(OutboxRetryPolicy.decision(for: error, attempt: record.attempts))
        }
    }

    // MARK: Writing

    /// Writes the body file and a `.prepared` record. Attachments are read one at a time.
    public func prepare(_ item: OutboxItem, origin: UploadOrigin) throws(MultipartFileProblem) -> UploadRecord {
        let placeholders = item.attachments.map {
            MultipartBody.Part.file(
                name: "attachments[]", fileName: $0.fileName, contentType: $0.contentType, data: Data())
        }
        let references = item.attachments.map(\.reference)
        let path: String
        let multipart: MultipartBody?
        switch item.target {
        case .thread(let threadID):
            let endpoint = Endpoint.sendMessage(
                threadID, text: item.text, model: item.model, effort: item.effort, delivery: item.delivery,
                sendKey: item.id, attachments: placeholders, references: references, intent: item.intent)
            path = endpoint.path
            multipart = endpoint.multipart
        case .newThread(let draft):
            let withModel = NewThreadDraft(
                repo: draft.repo, mode: draft.mode, repositories: draft.repositories,
                claudeAccountId: draft.claudeAccountId, model: item.model ?? draft.model)
            let endpoint = Endpoint.startThread(
                withModel, description: item.text, sendKey: item.id, attachments: placeholders,
                references: references)
            path = endpoint.path
            multipart = endpoint.multipart
        }
        guard let multipart else { throw .partCountMismatch(expected: 1, given: 0) }
        remove(item.id)
        let bodyPath = bodyPath(item.id)
        _ = try MultipartFileWriter.write(
            multipart, contents: item.attachments.map(\.path), from: files, to: bodyPath,
            protection: .untilFirstUnlock)
        let record = UploadRecord(
            itemID: item.id, origin: origin, path: path, contentType: multipart.contentType, bodyPath: bodyPath,
            updatedAt: clock.now())
        do {
            try save(record)
        } catch {
            removeQuietly(bodyPath)
            throw .notWritten
        }
        return record
    }

    /// Writes a fresh body and a `.prepared` record for an item whose answer asked for another attempt. The attempt
    /// count carries over, so the next task has the next description.
    public func renew(_ item: OutboxItem) throws(MultipartFileProblem) -> UploadRecord {
        let attempts = load(item.id)?.attempts ?? 0
        var record = try prepare(item, origin: .app)
        record.attempts = attempts
        persist(record)
        return record
    }

    /// Starts (or starts again) the upload of a record with a request from `client`.
    public func start(_ item: UUID, session: any UploadSession, client: APIClient) async -> UploadRecord? {
        guard var record = load(item) else { return nil }
        if case .answered = record.phase { return nil }
        guard starting.insert(item).inserted else { return record }
        defer { starting.remove(item) }
        let request: URLRequest
        do {
            request = try await client.uploadRequest(path: record.path, contentType: record.contentType)
        } catch {
            logUpload("upload not started: \(type(of: error))")
            return fail(record, attempts: record.attempts)
        }
        // The attempt is saved before the task starts, so an answer that arrives while `start` waits is matched.
        let attempt = record.attempts + 1
        let previous = record
        record.attempts = attempt
        record.phase = .uploading(session: session.identifier, task: 0)
        record.updatedAt = clock.now()
        progress[item] = nil
        persist(record)
        do {
            let task = try await session.start(
                request, bodyPath: record.bodyPath,
                description: UploadTaskDescription(item: item, attempt: attempt))
            guard var current = load(item), current.attempts == attempt, case .uploading = current.phase else {
                return load(item)
            }
            current.phase = .uploading(session: session.identifier, task: task)
            persist(current)
            refreshSummary()
            return current
        } catch {
            logUpload("upload not started: \(type(of: error))")
            guard let current = load(item), current.attempts == attempt, case .uploading = current.phase else {
                return load(item)
            }
            return fail(previous, attempts: previous.attempts)
        }
    }

    private func fail(_ record: UploadRecord, attempts: Int) -> UploadRecord {
        var failed = record
        failed.attempts = attempts
        failed.phase = .failed(.notStarted)
        failed.updatedAt = clock.now()
        progress[record.itemID] = nil
        persist(failed)
        refreshSummary()
        return failed
    }

    public func sent(_ description: UploadTaskDescription, bytes: Int64, of total: Int64) {
        guard total > 0, let record = load(description.item), record.attempts == description.attempt,
            case .uploading = record.phase
        else { return }
        progress[description.item] = min(max(Double(bytes) / Double(total), 0), 1)
        scheduleFlush()
    }

    public func received(_ description: UploadTaskDescription, data: Data) {
        var buffer = buffers[description] ?? Data()
        // One byte past the limit is kept, so an answer of exactly the limit differs from a cut one.
        let room = Self.answerLimit + 1 - buffer.count
        guard room > 0 else { return }
        buffer.append(data.prefix(room))
        buffers[description] = buffer
    }

    public func completed(
        _ description: UploadTaskDescription, session: String, task: Int, status: Int?,
        headers: [String: String], failed: Bool
    ) {
        let body = buffers.removeValue(forKey: description) ?? Data()
        guard var record = load(description.item) else {
            logUpload("answer for an unknown upload")
            return
        }
        guard record.attempts == description.attempt else {
            logUpload("answer for another attempt")
            return
        }
        switch record.phase {
        case .uploading(let knownSession, let knownTask):
            if knownSession != session || knownTask != task { logUpload("answer from another task number") }
        case .failed(.lost): break
        default:
            logUpload("answer for a record that is not uploading")
            return
        }
        progress[description.item] = nil
        if failed || status == nil {
            record.phase = .failed(.transport)
        } else if let status {
            record.phase = .answered(
                status: status, body: body, protocolHeader: header("X-Yak-Protocol", in: headers),
                retryAfter: header("Retry-After", in: headers))
            removeQuietly(record.bodyPath)
        }
        record.updatedAt = clock.now()
        persist(record)
        refreshSummary()
        for receiver in completionReceivers.values { receiver.yield(description.item) }
    }

    /// Marks `.uploading` records of `session` whose description is not in `live` as `.failed(.lost)`.
    public func reconcile(session: String, live: Set<UploadTaskDescription>) {
        for var record in records() {
            guard case .uploading(let known, _) = record.phase, known == session, !starting.contains(record.itemID),
                !live.contains(UploadTaskDescription(item: record.itemID, attempt: record.attempts))
            else { continue }
            record.phase = .failed(.lost)
            record.updatedAt = clock.now()
            progress[record.itemID] = nil
            persist(record)
        }
        refreshSummary()
    }

    public func remove(_ item: UUID) {
        let known = load(item)
        removeQuietly(recordPath(item))
        removeQuietly(known?.bodyPath ?? bodyPath(item))
        progress[item] = nil
        refreshSummary()
    }

    // MARK: Streams

    public func completions() -> AsyncStream<UUID> {
        nextReceiver += 1
        let identifier = nextReceiver
        let (stream, continuation) = AsyncStream.makeStream(of: UUID.self, bufferingPolicy: .unbounded)
        completionReceivers[identifier] = continuation
        continuation.onTermination = { [weak self] _ in
            Task { await self?.dropReceiver(identifier) }
        }
        return stream
    }

    /// The current summary at once, then a new one at most every 250 ms and at once when the failed set changes.
    public func summaries() -> AsyncStream<UploadSummary> {
        failedItems = failedSet()
        summaryBroadcast.send(UploadSummary(progress: progress, failed: failedItems))
        return summaryBroadcast.stream()
    }

    private func dropReceiver(_ identifier: Int) { completionReceivers[identifier] = nil }

    private func scheduleFlush() {
        guard flushTask == nil else { return }
        let clock = clock
        flushTask = Task { [weak self] in
            do { try await clock.sleep(for: Self.progressInterval) } catch { return }
            await self?.flush()
        }
    }

    private func flush() {
        flushTask = nil
        publish()
    }

    private func refreshSummary() {
        let failed = failedSet()
        let failedChanged = failed != failedItems
        failedItems = failed
        let visible = summaryBroadcast.value.progress.keys.contains { progress[$0] == nil }
        if failedChanged || visible { publish() }
    }

    private func publish() {
        let summary = UploadSummary(progress: progress, failed: failedItems)
        if summary != summaryBroadcast.value { summaryBroadcast.send(summary) }
    }

    private func failedSet() -> Set<UUID> {
        Set(
            records().compactMap { record in
                guard case .failed(let kind) = record.phase, kind == .transport || kind == .lost else { return nil }
                return record.itemID
            })
    }

    // MARK: Files

    private func recordPath(_ item: UUID) -> String { "\(InstanceFolders.uploads(instance))/\(item.uuidString).json" }
    private func bodyPath(_ item: UUID) -> String { "\(InstanceFolders.uploads(instance))/\(item.uuidString).body" }

    /// Reads a record. A file that is too large, does not decode or names another item is removed with its body.
    private func load(_ item: UUID) -> UploadRecord? {
        let data: Data?
        do {
            data = try files.read(recordPath(item), maximumBytes: Self.recordReadLimit)
        } catch FileStoreError.tooLarge {
            drop(item, reason: "too large")
            return nil
        } catch {
            logUpload("upload record unreadable: \(type(of: error))")
            return nil
        }
        guard let data else { return nil }
        let decoded: UploadRecord?
        do {
            decoded = try JSONDecoder().decode(UploadRecord.self, from: data)
        } catch {
            logUpload("upload record does not decode: \(type(of: error))")
            decoded = nil
        }
        guard let record = decoded, record.itemID == item,
            data.count - answerSize(record) <= Self.recordLimit, record.bodyPath == bodyPath(item),
            isUploadPath(record.path)
        else {
            drop(item, reason: "invalid")
            return nil
        }
        return record
    }

    private func isUploadPath(_ path: String) -> Bool {
        let pieces = path.split(separator: "/", omittingEmptySubsequences: false)
        if pieces == ["threads"] { return true }
        guard pieces.count == 3, pieces[0] == "threads", pieces[2] == "messages" else { return false }
        return !pieces[1].isEmpty && pieces[1].allSatisfy { $0.isLetter || $0.isNumber || $0 == "-" || $0 == "_" }
    }

    private func answerSize(_ record: UploadRecord) -> Int {
        if case .answered(_, let body, _, _) = record.phase { return (body.count + 2) / 3 * 4 }
        return 0
    }

    private func drop(_ item: UUID, reason: String) {
        logUpload("upload record dropped, \(reason)")
        removeQuietly(recordPath(item))
        removeQuietly(bodyPath(item))
    }

    private func save(_ record: UploadRecord) throws(FileStoreError) {
        let data: Data
        do { data = try JSONEncoder().encode(record) } catch { throw .unreadable }
        try files.write(data, to: recordPath(record.itemID), protection: .untilFirstUnlock)
    }

    private func persist(_ record: UploadRecord) {
        do { try save(record) } catch { logUpload("upload record not saved: \(type(of: error))") }
    }

    private func removeQuietly(_ path: String) {
        do { try files.remove(path) } catch { logUpload("upload file not removed: \(type(of: error))") }
    }

    private func header(_ name: String, in headers: [String: String]) -> String? {
        headers.first { $0.key.caseInsensitiveCompare(name) == .orderedSame }?.value
    }
}
