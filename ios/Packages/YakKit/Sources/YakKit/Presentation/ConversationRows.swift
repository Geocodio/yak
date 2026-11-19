import Foundation

public enum DayLabel: Equatable, Sendable {
    case today
    case yesterday
    case date(Date)
}

public enum ConversationRow: Equatable, Sendable, Identifiable {
    /// The id is `day:<yyyy-MM-dd>` in the calendar's time zone.
    case divider(DayLabel, id: String)
    case entry(ConversationEntry, isCurrentStep: Bool)
    /// The id is `waiting`.
    case waiting(WaitingRow)

    public var id: String {
        switch self {
        case .divider(_, let id): id
        case .entry(let entry, _): entry.id
        case .waiting: "waiting"
        }
    }
}

public enum ConversationTimestamps {
    public static func date(of entry: ConversationEntry) -> Date? {
        switch entry {
        case .person(let message, _): RelativeAge.parse(message.sentAt)
        case .event(let item): RelativeAge.parse(item.at)
        case .unsent(let item): item.createdAt
        case .delivered(let delivery): delivery.sentAt
        case .milestone: nil
        case .retry(let message): RelativeAge.parse(message.sentAt)
        case .legacyAnswer(let answer): RelativeAge.parse(answer.at)
        }
    }
}

public enum ConversationRows {
    public static func make(
        entries: [ConversationEntry], currentStepKey: String?, waiting: WaitingRow?, now: Date,
        calendar: Calendar
    ) -> [ConversationRow] {
        var rows: [ConversationRow] = []
        var previousDay: DateComponents?
        for entry in entries {
            if let date = ConversationTimestamps.date(of: entry) {
                let day = calendar.dateComponents([.year, .month, .day], from: date)
                if day != previousDay {
                    rows.append(divider(for: date, now: now, calendar: calendar))
                    previousDay = day
                }
            }
            rows.append(.entry(entry, isCurrentStep: entry.id == currentStepKey))
        }
        if let waiting { rows.append(.waiting(waiting)) }
        return rows
    }

    private static func divider(for date: Date, now: Date, calendar: Calendar) -> ConversationRow {
        let start = calendar.startOfDay(for: date)
        let components = calendar.dateComponents([.year, .month, .day], from: start)
        let id = String(
            format: "day:%04d-%02d-%02d", components.year ?? 0, components.month ?? 0, components.day ?? 0)
        let label: DayLabel
        if calendar.isDate(date, inSameDayAs: now) {
            label = .today
        } else if let yesterday = calendar.date(byAdding: .day, value: -1, to: now),
            calendar.isDate(date, inSameDayAs: yesterday)
        {
            label = .yesterday
        } else {
            label = .date(start)
        }
        return .divider(label, id: id)
    }
}
