import SwiftUI
import YakKit

/// Asks a co-owner early, or says it is not needed. The names come from the server's list of people who can approve.
struct TagCoOwnerSheet<Model: CoOwnerDecisionHost>: View {
    let model: Model
    let fallbackName: String?
    let close: () -> Void

    var body: some View {
        NavigationStack {
            List {
                switch model.candidates {
                case .loading:
                    ProgressView().frame(maxWidth: .infinity).accessibilityIdentifier("task.tag.loading")
                case .failed:
                    VStack(spacing: 10) {
                        Text("task.tag.failed").font(.subheadline.weight(.medium)).multilineTextAlignment(.center)
                        RetryButton(identifier: "task.tag.retry") { Task { await model.loadCandidates() } }
                    }
                    .frame(maxWidth: .infinity)
                case .loaded(let people, let isChecking):
                    if people.isEmpty, !isChecking {
                        Text("task.tag.nobody").font(.subheadline.weight(.medium)).foregroundStyle(Color.yakMuted)
                            .accessibilityIdentifier("task.tag.nobody")
                    }
                    ForEach(people) { person in
                        Button {
                            Task {
                                if await model.decide(.tag(userID: person.id)) { close() }
                            }
                        } label: {
                            Text(verbatim: person.name).frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                        }
                        .foregroundStyle(.primary)
                        .accessibilityIdentifier("task.tag.person.\(person.id)")
                    }
                    if isChecking {
                        HStack(spacing: 8) {
                            ProgressView()
                            Text("task.tag.checking").font(.subheadline.weight(.medium))
                                .foregroundStyle(Color.yakMuted)
                        }
                        .accessibilityElement(children: .combine).accessibilityIdentifier("task.tag.checking")
                    }
                }
                Section {
                    Button {
                        Task {
                            if await model.decide(.decline) { close() }
                        }
                    } label: {
                        Text("task.tag.notNeeded").frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                    }
                    .foregroundStyle(.primary)
                    .accessibilityIdentifier("task.tag.decline")
                } footer: {
                    if let fallbackName {
                        Text("task.tag.fallback \(fallbackName)").font(.footnote.weight(.medium)).foregroundStyle(
                            Color.yakMuted)
                    }
                }
                if let message = model.message {
                    Text(verbatim: message).font(.footnote.weight(.medium)).foregroundStyle(Color.yakDanger)
                }
            }
            .disabled(model.isSending)
            .navigationTitle("task.tag.title")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("common.close", action: close).accessibilityIdentifier("task.tag.close")
                }
            }
        }
        .task { await model.loadCandidates() }
    }
}
