import SwiftUI
import UIKit
import YakKit

/// The last 200 redacted log lines.
struct LogScreen: View {
    let model: DebugMenuModel
    @State private var showsLoading = false
    @State private var copiedCount = 0
    @State private var showsCopied = false

    var body: some View {
        content
            .navigationTitle("debug.log.title")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItemGroup(placement: .primaryAction) {
                    Button {
                        UIPasteboard.general.string = model.copyLog()
                        copiedCount += 1
                    } label: {
                        Label(
                            showsCopied ? "debug.log.copied" : "debug.log.copy",
                            systemImage: showsCopied ? "checkmark" : "doc.on.doc")
                    }
                    .disabled(!hasLines)
                    .accessibilityIdentifier("debug.log.copy")
                    Button {
                        Task { await model.loadLog() }
                    } label: {
                        Label("debug.log.refresh", systemImage: "arrow.clockwise")
                    }
                    .disabled(model.log == .loading)
                    .accessibilityIdentifier("debug.log.refresh")
                }
            }
            .sensoryFeedback(.success, trigger: copiedCount)
            .task { await model.loadLog() }
            .task(id: model.log == .loading) {
                guard model.log == .loading else {
                    showsLoading = false
                    return
                }
                do { try await Task.sleep(for: .milliseconds(300)) } catch { return }
                showsLoading = true
            }
            .task(id: copiedCount) {
                guard copiedCount > 0 else { return }
                showsCopied = true
                do { try await Task.sleep(for: .seconds(2)) } catch { return }
                showsCopied = false
            }
            .accessibilityIdentifier("debug.log.screen")
    }

    private var hasLines: Bool {
        if case .loaded = model.log { return true }
        return false
    }

    @ViewBuilder private var content: some View {
        switch model.log {
        case .idle:
            Color.clear
        case .loading:
            if showsLoading {
                VStack(spacing: 12) {
                    ProgressView()
                    Text("debug.log.loading").foregroundStyle(Color.yakMuted)
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity)
            } else {
                Color.clear
            }
        case .empty:
            message("debug.log.empty", action: "debug.log.refresh")
        case .failed(let reason):
            message(LocalizedStringKey(reason), action: "debug.log.retry")
        case .loaded(let lines, let partial):
            VStack(spacing: 0) {
                if partial {
                    Text("debug.log.partial").font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                        .frame(maxWidth: .infinity, alignment: .leading).padding(16)
                }
                ScrollViewReader { proxy in
                    List(Array(lines.enumerated()), id: \.offset) { index, line in
                        row(line).id(index)
                    }
                    .listStyle(.plain)
                    .onAppear { proxy.scrollTo(lines.count - 1, anchor: .bottom) }
                }
            }
        }
    }

    private func message(_ text: LocalizedStringKey, action: LocalizedStringKey) -> some View {
        VStack(spacing: 16) {
            Text(text).multilineTextAlignment(.center).foregroundStyle(Color.yakMuted)
            Button(action) { Task { await model.loadLog() } }.buttonStyle(SecondaryButtonStyle())
        }
        .padding(24).frame(maxWidth: 420).frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    private static let timeFormat: DateFormatter = {
        let formatter = DateFormatter()
        formatter.dateFormat = "HH:mm:ss.SSS"
        return formatter
    }()

    private func row(_ line: LogLine) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            HStack(spacing: 6) {
                Circle().fill(line.level == .error || line.level == .fault ? Color.orange : Color.yakMuted)
                    .frame(width: 8, height: 8).accessibilityHidden(true)
                Text(verbatim: Self.timeFormat.string(from: line.date)).font(.system(.caption, design: .monospaced))
                    .foregroundStyle(Color.yakMuted)
                Text(verbatim: line.category).font(.caption).foregroundStyle(Color.yakMuted)
            }
            Text(verbatim: line.text).font(.system(.footnote, design: .monospaced))
                .fixedSize(horizontal: false, vertical: true)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .accessibilityElement(children: .combine)
    }
}
