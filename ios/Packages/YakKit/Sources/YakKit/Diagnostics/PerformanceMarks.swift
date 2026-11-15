/// The five measures of app spec 17. Names are stable: the manual pass refers to them.
public enum PerformanceMark: String, CaseIterable, Sendable {
    case coldStartToThread = "cold-start-to-thread"
    case pushTapToQuestion = "push-tap-to-question"
    case streamingScroll = "streaming-scroll"
    case paneSwitch = "pane-switch"
    /// An event, measured for memory in Instruments.
    case allPanesOpen = "all-panes-open"

    /// False only for `allPanesOpen`, which is a point in time.
    public var isInterval: Bool {
        self != .allPanesOpen
    }

    /// What a second begin does while one is open: restart (pane switch, streaming) or keep the first.
    public var restartsOnBegin: Bool {
        self == .paneSwitch || self == .streamingScroll
    }

    /// Cold start ends once per process; later ends are ignored.
    public var endsOncePerProcess: Bool {
        self == .coldStartToThread
    }
}

/// Which intervals are open. One slot per mark, so it never grows.
public struct OpenPerformanceIntervals<Token> {
    private var open: [PerformanceMark: Token] = [:]
    private var endedMarks: Set<PerformanceMark> = []

    public init() {}

    /// Returns the token to end before starting a new one when the mark restarts, and whether to begin.
    public mutating func begin(
        _ mark: PerformanceMark, token: Token
    ) -> (endPrevious: Token?, shouldBegin: Bool) {
        guard mark.isInterval, !endedMarks.contains(mark) else {
            return (nil, false)
        }
        guard let previous = open[mark] else {
            open[mark] = token
            return (nil, true)
        }
        guard mark.restartsOnBegin else {
            return (nil, false)
        }
        open[mark] = token
        return (previous, true)
    }

    /// Returns the token to end, or nil when nothing is open or the mark already ended once this process.
    public mutating func end(_ mark: PerformanceMark) -> Token? {
        guard let token = open.removeValue(forKey: mark) else {
            return nil
        }
        if mark.endsOncePerProcess {
            endedMarks.insert(mark)
        }
        return token
    }
}
