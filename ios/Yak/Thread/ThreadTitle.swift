import SwiftUI
import UIKit
import YakKit

/// The principal toolbar item: a status dot and the title on one line, and the connection line under it when there
/// is one.
struct ThreadTitle: View {
    let title: String
    let dot: ThreadStatusDot
    let connectionLine: ConnectionLine?

    var body: some View {
        VStack(spacing: 1) {
            HStack(spacing: 6) {
                Circle().fill(color).frame(width: 8, height: 8).accessibilityHidden(true)
                Text(title).font(.headline).lineLimit(1).truncationMode(.tail)
                    .accessibilityAddTraits(.isHeader)
                    .accessibilityValue(statusText)
            }
            if let connectionLine {
                Text(connectionLine.text).font(.caption).foregroundStyle(connectionLine.color).lineLimit(1)
                    .accessibilityIdentifier("thread.connection")
            }
        }
        .accessibilityIdentifier("thread.title")
    }

    /// Running is green and blocked or waiting orange; every other state is a neutral gray, never the rust accent.
    private var color: Color {
        switch dot {
        case .running: .green
        case .attention: .orange
        case .neutral: Color(.systemGray2)
        }
    }

    private var statusText: Text {
        switch dot {
        case .running: Text("thread.status.running")
        case .attention: Text("thread.status.attention")
        case .neutral: Text("")
        }
    }
}

extension ConnectionLine {
    var text: LocalizedStringKey {
        switch self {
        case .slow: "connection.slow"
        case .offlineSavedCopy: "connection.offlineSavedCopy"
        case .offline: "connection.offline"
        case .reconnecting: "connection.reconnecting"
        }
    }
}

extension ConnectionLine {
    /// A warning orange for every connection problem, dark enough on light surfaces to read as text.
    var color: Color {
        Color(
            UIColor { traits in
                traits.userInterfaceStyle == .dark
                    ? .systemOrange : UIColor(red: 0.62, green: 0.33, blue: 0.0, alpha: 1)
            })
    }
}
