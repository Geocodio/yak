import SwiftUI

/// Asks before a private thread's branch and description go to GitHub.
struct PrivatePublishSheet: View {
    let model: FilesModel

    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            Text("files.private.title").font(.title3.weight(.semibold)).fixedSize(horizontal: false, vertical: true)
                .accessibilityAddTraits(.isHeader)
            Text("files.private.detail").font(.body).fixedSize(horizontal: false, vertical: true)
            Spacer(minLength: 0)
            Button {
                Task { await model.confirmPrivatePublish() }
            } label: {
                Text("files.open.label").fixedSize(horizontal: false, vertical: true).frame(maxWidth: .infinity)
            }
            .buttonStyle(OpenPullRequestButtonStyle()).accessibilityIdentifier("files.private.confirm")
            Button("common.cancel") { model.cancelPrivatePublish() }
                .font(.body.weight(.medium)).foregroundStyle(.primary)
                .frame(maxWidth: .infinity, minHeight: 44).accessibilityIdentifier("files.private.cancel")
        }
        .padding(24)
        .presentationDetents([.large])
        .presentationBackground(Color.yakBackground)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("files.private")
    }
}
