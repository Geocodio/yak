import SwiftUI
import YakKit

/// The drawer until the real one arrives: the banner after a pairing, the threads, and the states of loading.
struct DrawerPlaceholder: View {
    let model: AppModel
    let instance: Instance
    let scene: SceneModel
    let state: ThreadListState?
    let isCompactWidth: Bool
    let retry: () -> Void
    let openAccount: () -> Void

    @State private var showsSkeleton = false

    var body: some View {
        VStack(spacing: 0) {
            if let name = scene.justPairedUserName { banner(name) }
            content
            Divider()
            Button(action: openAccount) {
                Label(instance.name, systemImage: "person.crop.circle")
                    .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
            }
            .foregroundStyle(.primary)
            .padding(.horizontal, 20)
            .accessibilityLabel("drawer.account")
            .accessibilityIdentifier("drawer.account")
        }
        .background(Color(.secondarySystemBackground))
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("drawer")
    }

    @ViewBuilder private var content: some View {
        if let list = state?.list {
            if state?.isEmpty == true {
                message(Text("drawer.empty"), actionTitle: "shell.startThread") {
                    scene.openNewThread(repo: nil, text: nil)
                }
            } else {
                ScrollView {
                    LazyVStack(alignment: .leading, spacing: 0) {
                        if state?.isOffline == true {
                            Label("common.offline", systemImage: "wifi.slash").padding(.horizontal, 20)
                                .padding(.vertical, 8)
                        }
                        rows(list)
                    }
                }
            }
        } else if case .failed(let problem)? = state?.load {
            switch problem {
            case .offline: message(Text("drawer.offline"), actionTitle: "common.retry", action: retry)
            case .server(_, let text) where !text.isEmpty:
                message(Text(verbatim: text), actionTitle: "common.retry", action: retry)
            default: message(Text("drawer.loadFailed"), actionTitle: "common.retry", action: retry)
            }
        } else {
            skeleton
        }
    }

    @ViewBuilder private func rows(_ list: ThreadList) -> some View {
        let needy: [(id: String, title: String, open: () -> Void)] = list.needsYou.compactMap { item in
            switch item {
            case .thread(let row): (row.id, row.title, { scene.open(thread: row.id, isCompactWidth: isCompactWidth) })
            case .task(let task):
                (
                    task.taskId.value, task.title,
                    { model.browserURL = instance.url.appending(path: "tasks").appending(path: task.taskId.value) }
                )
            case .unknown: nil
            }
        }
        if !needy.isEmpty {
            group {
                ForEach(needy, id: \.id) { row in rowButton(row.title, action: row.open) }
            } header: {
                sectionHeader("drawer.needsYou")
            }
        }
        if !list.starred.isEmpty {
            group {
                ForEach(list.starred, id: \.id) { row in threadButton(row) }
            } header: {
                sectionHeader("drawer.starred")
            }
        }
        if !list.threads.isEmpty {
            group {
                ForEach(list.threads, id: \.id) { row in threadButton(row) }
            } header: {
                sectionHeader("drawer.threads")
            }
        }
    }

    private func sectionHeader(_ title: LocalizedStringKey) -> some View {
        Text(title).font(.subheadline).foregroundStyle(Color.yakMuted)
            .padding(.horizontal, 20).padding(.top, 16).padding(.bottom, 4)
            .accessibilityAddTraits(.isHeader)
    }

    private func group<Content: View, Header: View>(
        @ViewBuilder content: () -> Content, @ViewBuilder header: () -> Header
    ) -> some View {
        VStack(alignment: .leading, spacing: 0) {
            header()
            content()
        }
    }

    private func threadButton(_ row: ThreadRow) -> some View {
        rowButton(row.title) { scene.open(thread: row.id, isCompactWidth: isCompactWidth) }
    }

    private func rowButton(_ title: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(title).font(.body).multilineTextAlignment(.leading).fixedSize(horizontal: false, vertical: true)
                .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
        }
        .foregroundStyle(.primary)
        .padding(.horizontal, 20)
    }

    /// Nothing for the first 300 ms, then shapes in the layout of the rows.
    private var skeleton: some View {
        VStack(alignment: .leading, spacing: 14) {
            if showsSkeleton {
                ForEach(0..<5, id: \.self) { _ in
                    RoundedRectangle(cornerRadius: 8).fill(Color.primary.opacity(0.08)).frame(height: 18)
                }
                SlowHint(retry: retry)
            }
        }
        .padding(20)
        .frame(maxHeight: .infinity, alignment: .top)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("drawer.loading")
        .task {
            do { try await Task.sleep(for: .milliseconds(300)) } catch { return }
            showsSkeleton = true
        }
    }

    private func message(_ text: Text, actionTitle: LocalizedStringKey, action: @escaping () -> Void)
        -> some View
    {
        VStack(alignment: .leading, spacing: 16) {
            text.foregroundStyle(Color.yakMuted)
            Button(actionTitle, action: action).buttonStyle(SecondaryButtonStyle())
        }
        .padding(20)
        .frame(maxHeight: .infinity, alignment: .top)
    }

    private func banner(_ name: String) -> some View {
        HStack {
            Text("signedIn.signedInAs \(name)").font(.subheadline)
            Spacer()
            Button {
                scene.justPairedUserName = nil
            } label: {
                Image(systemName: "xmark").frame(minWidth: 44, minHeight: 44)
            }
            .foregroundStyle(.primary)
            .accessibilityLabel("drawer.dismissBanner")
        }
        .padding(.leading, 20)
        .background(Color.primary.opacity(0.06))
    }
}

/// The thread screen until the real one arrives: the title, and the button that opens the drawer.
struct ThreadPlaceholder: View {
    let title: String?
    let isNewThread: Bool
    let showsThreadsButton: Bool
    let openDrawer: () -> Void

    var body: some View {
        NavigationStack {
            VStack(spacing: 16) {
                if isNewThread {
                    Text("shell.newThreadTitle").font(.title2.bold())
                } else if let title {
                    Text(title).font(.title2.bold()).multilineTextAlignment(.center)
                } else {
                    Text("shell.noThread").foregroundStyle(Color.yakMuted).multilineTextAlignment(.center)
                }
            }
            .padding(24)
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .accessibilityElement(children: .contain)
            .accessibilityIdentifier("conversation")
            .background(Color(.systemBackground))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                if showsThreadsButton {
                    ToolbarItem(placement: .topBarLeading) {
                        Button(action: openDrawer) { Image(systemName: "line.3.horizontal") }
                            .accessibilityLabel("shell.threads")
                            .accessibilityIdentifier("threads.button")
                    }
                }
            }
        }
    }
}
