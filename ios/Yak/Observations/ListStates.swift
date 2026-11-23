import SwiftUI
import YakKit

/// A centered message with an optional detail and actions: the empty, error, offline and slow states of the lists
/// and of the task screen. It scrolls, so it never clips at the largest text size.
struct StateMessage<Actions: View>: View {
    let title: Text
    let detail: Text?
    let systemImage: String
    let identifier: String
    let actions: Actions

    init(
        title: Text, detail: Text? = nil, systemImage: String, identifier: String,
        @ViewBuilder actions: () -> Actions
    ) {
        self.title = title
        self.detail = detail
        self.systemImage = systemImage
        self.identifier = identifier
        self.actions = actions()
    }

    init(
        title: LocalizedStringKey, detail: LocalizedStringKey? = nil, systemImage: String, identifier: String,
        @ViewBuilder actions: () -> Actions
    ) {
        self.init(
            title: Text(title), detail: detail.map { Text($0) }, systemImage: systemImage, identifier: identifier,
            actions: actions)
    }

    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        ScrollView {
            VStack(spacing: 10) {
                if !dynamicTypeSize.isAccessibilitySize {
                    Image(systemName: systemImage).font(.largeTitle).foregroundStyle(Color.yakMuted)
                        .accessibilityHidden(true).padding(.bottom, 4)
                }
                title.font(.headline).multilineTextAlignment(.center)
                    .fixedSize(horizontal: false, vertical: true)
                if let detail {
                    detail.font(.subheadline.weight(.medium)).foregroundStyle(Color.yakMuted)
                        .multilineTextAlignment(.center).fixedSize(horizontal: false, vertical: true)
                }
                VStack(spacing: 10) { actions }.padding(.top, 8)
            }
            .frame(maxWidth: 420).frame(maxWidth: .infinity)
            .padding(24)
            .accessibilityElement(children: .contain)
            .accessibilityIdentifier(identifier)
        }
    }
}

extension StateMessage where Actions == EmptyView {
    init(title: LocalizedStringKey, detail: LocalizedStringKey? = nil, systemImage: String, identifier: String) {
        self.init(
            title: Text(title), detail: detail.map { Text($0) }, systemImage: systemImage, identifier: identifier,
            actions: { EmptyView() })
    }
}

/// A retry button in the style of the other state screens.
struct RetryButton: View {
    let identifier: String
    let action: () -> Void

    var body: some View {
        Button(action: action) { Text("common.retry").fixedSize(horizontal: false, vertical: true) }
            .buttonStyle(SecondaryButtonStyle())
            .accessibilityIdentifier(identifier)
    }
}

/// Grey bars in the layout of a list row. They appear 300 ms after loading started, so a fast answer never
/// flashes them.
struct RowSkeleton: View {
    var rowCount = 6
    let identifier: String
    @State private var isShown = false
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        VStack(alignment: .leading, spacing: 20) {
            if isShown {
                ForEach(0..<rowCount, id: \.self) { _ in
                    VStack(alignment: .leading, spacing: 8) {
                        bar(width: 0.8, height: 16)
                        bar(width: 0.5, height: 12)
                    }
                }
            }
        }
        .padding(.horizontal, 20).padding(.top, 16)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
        .animation(reduceMotion ? nil : .easeOut(duration: 0.2), value: isShown)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(Text("common.loading"))
        .accessibilityIdentifier(identifier)
        .task {
            do { try await Task.sleep(for: .milliseconds(300)) } catch { return }
            isShown = true
        }
    }

    private func bar(width: CGFloat, height: CGFloat) -> some View {
        RoundedRectangle(cornerRadius: 6).fill(Color.primary.opacity(0.1))
            .frame(height: height)
            .containerRelativeFrame(.horizontal) { length, _ in (length - 40) * width }
    }
}

/// The short banner above content that stays on screen: an offline notice or a problem with the last refresh.
struct NoticeLine: View {
    let text: LocalizedStringKey
    let systemImage: String
    let identifier: String

    var body: some View {
        Label {
            Text(text).font(.footnote.weight(.medium)).fixedSize(horizontal: false, vertical: true)
        } icon: {
            Image(systemName: systemImage).accessibilityHidden(true)
        }
        .foregroundStyle(Color.yakMuted)
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.horizontal, 20).padding(.vertical, 8)
        .background(Color.primary.opacity(0.05))
        .accessibilityElement(children: .combine)
        .accessibilityIdentifier(identifier)
    }
}

/// What a failed load says, in plain words.
enum ProblemCopy {
    static func message(_ problem: ThreadProblem, offline: LocalizedStringKey) -> Text {
        switch problem {
        case .offline: Text(offline)
        case .server(_, let message) where !message.isEmpty: Text(verbatim: message)
        default: Text("list.loadFailed")
        }
    }
}
