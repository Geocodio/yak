#if DEBUG
    import SwiftUI
    import YakKit

    /// A Debug-only screen that shows one long sample document and a streaming run, for looking at the
    /// rendering in light, dark and at the largest text size. Launch with `-yakMarkdownGallery YES`.
    struct MarkdownGallery: View {
        private let renderer = AppMarkdownRenderer()
        @State private var streamed: AppMarkdownRenderer.Document = []
        @State private var sampleDocument: AppMarkdownRenderer.Document = []
        @State private var isStreaming = false

        var body: some View {
            NavigationStack {
                ScrollViewReader { proxy in
                    ScrollView {
                        VStack(alignment: .leading, spacing: 24) {
                            renderer.view(sampleDocument)
                                .accessibilityIdentifier("markdown-sample")
                                .id("start")
                            Divider().id("end")
                            Button(isStreaming ? "Streaming" : "Stream the sample") { stream() }
                                .buttonStyle(.bordered)
                                .disabled(isStreaming)
                                .accessibilityIdentifier("markdown-stream")
                            renderer.view(streamed)
                        }
                        .padding(16)
                    }
                    .task {
                        sampleDocument = await renderer.parse(
                            UserDefaults.standard.bool(forKey: "yakMarkdownGalleryPaged")
                                ? Self.pagedSample : Self.sample)
                        let fraction = UserDefaults.standard.double(forKey: "yakMarkdownGalleryFraction")
                        if fraction > 0 {
                            try? await Task.sleep(for: .milliseconds(500))
                            proxy.scrollTo("end", anchor: UnitPoint(x: 0.5, y: fraction))
                        }
                    }
                }
                .navigationTitle("Markdown")
                .navigationBarTitleDisplayMode(.inline)
            }
        }

        private func stream() {
            isStreaming = true
            streamed = []
            let renderer = renderer
            let characters = Array(Self.sample)
            Task {
                var length = 0
                while length < characters.count {
                    length = min(characters.count, length + 40)
                    let text = String(characters[0..<length])
                    streamed = await Task.detached { await renderer.parse(text) }.value
                    try? await Task.sleep(for: .milliseconds(100))
                }
                isStreaming = false
            }
        }

        /// The sample under a numbered title, many times over, so the document has several pages. Launch with
        /// `-yakMarkdownGalleryPaged YES`.
        static let pagedSample = (1...60).map { "# Part \($0)\n\n" + sample }.joined(separator: "\n\n")

        static let sample = """
            # Importer rate limits

            The limit lives in `RateLimit::forImporter()` and applies to **every** queue, not only the *default* one. See [the notes](https://example.com/notes) for the long version, including a sentence that wraps over several lines at the largest text size.

            ## What changed

            1. Read the limit from configuration
            2. Pass it to the importer
            3. Add a test for the boundary

            - A bullet with `inline code`
            - A bullet with a very long line that keeps going and going so that it has to wrap when the text size is large
            - Another bullet
              - A nested bullet under it
              - A second nested bullet with a long line that wraps at the largest text size
            - A last bullet

            - [x] A finished task
            - [ ] An open task with a long line that wraps when the text size is large
            - Done and ~~struck out~~ with **bold** and *italic* text
              1. A numbered child
              2. Another numbered child

            Raw HTML stays text: <div class="note">not a div</div> and an image ![chart](https://example.com/c.png).

            1. One
            2. Two
            3. Three
            4. Four
            5. Five
            6. Six
            7. Seven
            8. Eight
            9. Nine
            10. Ten, aligned with the rest

            > A quote with a second line
            > that continues here.

            ### Code

            ```swift
            func importRows(from source: URL, limit: Int, onProgress: @escaping (Int) -> Void, onFailure: @escaping (Error) -> Void) async throws -> [Row] {
                try await withThrowingTaskGroup(of: Row.self) { group in group.waitForAll() }
            }
            ```

            A second block, with emoji and a long line:

            ```swift
            report("done ✅")
            let team = "👨‍👩‍👧‍👦 🇩🇰 👩‍💻"
            let summary = "A line that is much longer than the screen is wide, so the block scrolls sideways instead of wrapping it"
            ```

            > A quote that holds a list:
            >
            > - First point, with a [link](https://example.com)
            > - Second point
            >
            > And a closing line.

            ### Table

            | Step | Owner | Duration | Notes |
            |---|:-:|--:|---|
            | Parse the file | importer | 120 ms | Streams rows without loading the whole file into memory at once |
            | Validate | importer | 40 ms | Rejects rows with a missing identifier |
            | Store | database | 900 ms | Batches of 500 |

            ---

            #### Last words

            An unfinished fence follows, as it looks while streaming.

            ```php
            $limit = config('import.limit'
            """
    }

    #Preview { MarkdownGallery() }
#endif
