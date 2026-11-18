import SwiftUI
import YakKit

/// The repository, mode and model choices under the text. Each is a menu; a warning dot marks a repository that is
/// still being set up.
struct ShareChips: View {
    let model: ShareExtensionModel

    private var options: NewThreadOptions? { model.context?.options }

    var body: some View {
        if let options, let form = model.form {
            ViewThatFits(in: .horizontal) {
                HStack(spacing: 8) { chips(options, form) }
                VStack(alignment: .leading, spacing: 8) { chips(options, form) }
            }
        } else if model.isRefreshingOptions {
            loadingSkeleton
        } else {
            ShareText.text("share.noContext").font(.footnote).foregroundStyle(.primary)
                .accessibilityIdentifier("share.notice")
        }
    }

    @ViewBuilder private func chips(_ options: NewThreadOptions, _ form: NewThreadForm) -> some View {
        Menu {
            ForEach(options.repos, id: \.slug) { repository in
                Button(repository.slug) { model.selectRepository(repository.slug) }
            }
        } label: {
            HStack(spacing: 6) {
                if let slug = form.repo, options.repos.first(where: { $0.slug == slug })?.setupStatus != "ready" {
                    Circle().fill(Color.orange).frame(width: 8, height: 8).accessibilityHidden(true)
                }
                Text(verbatim: form.repo ?? ShareText.string("share.repository.none"))
            }
            .chip()
        }
        .chipMenu().accessibilityIdentifier("share.repository")
        Menu {
            Button {
                model.selectMode(.fix)
            } label: {
                ShareText.text("share.mode.fix")
            }
            Button {
                model.selectMode(.research)
            } label: {
                ShareText.text("share.mode.research")
            }
        } label: {
            ShareText.text(form.mode == .fix ? "share.mode.fix" : "share.mode.research").chip()
        }
        .chipMenu().accessibilityIdentifier("share.mode")
        Menu {
            Button {
                model.selectModel(nil)
            } label: {
                ShareText.text("share.model.default")
            }
            ForEach(options.models, id: \.family) { family in
                Button(family.latest.label) { model.selectModel(family.latest.value) }
            }
        } label: {
            Text(
                verbatim: ModelChoices.label(for: form.model, in: options.models)
                    ?? ShareText.string("share.model.default")
            )
            .chip()
        }
        .chipMenu().accessibilityIdentifier("share.model")
    }

    private var loadingSkeleton: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 8) {
                ForEach(0..<3, id: \.self) { _ in
                    Capsule().fill(Color(.tertiarySystemFill)).frame(width: 90, height: 36)
                }
            }
            if model.isSlowToLoadOptions {
                ShareText.text("share.slow").font(.footnote).foregroundStyle(.primary)
                Button {
                    Task { await model.retryOptions() }
                } label: {
                    ShareText.text("share.retry")
                }
            }
        }
        .accessibilityElement(children: .contain)
    }
}

extension View {
    /// The system's bordered capsule in the primary color: its text size and contrast come from the system.
    fileprivate func chip() -> some View { foregroundStyle(.primary) }

    fileprivate func chipMenu() -> some View {
        buttonStyle(.bordered).buttonBorderShape(.capsule).tint(.primary).controlSize(.large)
    }
}
