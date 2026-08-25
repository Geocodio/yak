// Auto-scroll log lists when new entries arrive, but yield to the user
// when they scroll up to read history. Resumes following once the user
// scrolls back to the bottom.
//
// Pass false to start parked at the top instead of following, for a list
// opened on a specific entry the reader chose — following would yank them
// away from it the moment the next log line lands.
//
// Usage:
//   <div x-data="activityFollow()">
//     <div x-ref="logList" @scroll.passive="onScroll()">…log entries…</div>
//   </div>
export const activityFollow = (startFollowing = true) => ({
    following: startFollowing,
    observer: null,
    resizeObserver: null,
    suppressScrollEvent: false,
    revealedId: null,
    init() {
        this.$nextTick(() =>
            this.following ? this.scrollToEnd("auto") : this.revealSelected("auto"),
        );

        this.observer = new MutationObserver(() => {
            this.following ? this.scrollToEnd("smooth") : this.revealSelected("smooth");
        });

        // characterData catches in-place text updates (e.g. a streaming
        // log row whose message field grows over time via Livewire
        // morph), not just node add/remove. Without it, the auto-scroll
        // only kicks in when a *new* entry appears.
        this.observer.observe(this.$refs.logList, {
            childList: true,
            subtree: true,
            characterData: true,
        });

        // A list rendered inside a closed modal has no layout, so the
        // mutation that marks the selected row cannot scroll to it yet.
        // Opening the modal gives the list height but fires no mutation,
        // so watch for the resize and reveal then.
        this.resizeObserver = new ResizeObserver(() => {
            if (!this.following) this.revealSelected("auto");
        });
        this.resizeObserver.observe(this.$refs.logList);
    },
    destroy() {
        this.observer?.disconnect();
        this.resizeObserver?.disconnect();
    },
    isNearBottom() {
        const el = this.$refs.logList;
        return el.scrollTop + el.clientHeight >= el.scrollHeight - 48;
    },
    scrollToEnd(behavior) {
        const el = this.$refs.logList;
        this.suppressScrollEvent = true;
        el.scrollTo({ top: el.scrollHeight, behavior: behavior ?? "smooth" });
        requestAnimationFrame(() => {
            this.suppressScrollEvent = false;
        });
    },
    // Bring the selected entry into view, but only when the selection has
    // actually changed -- re-running on every re-render would drag the
    // reader back every time a poll lands while they scroll the list.
    //
    // A list inside a closed modal has no layout, so scrollIntoView is a
    // no-op there. In that case leave revealedId alone so the next
    // mutation (the one that opens the modal) tries again.
    revealSelected(behavior) {
        const list = this.$refs.logList;
        if (!list) return;

        const row = list.querySelector("[data-log-selected]");
        if (!row) return;

        const id = row.getAttribute("data-log-selected");
        if (id === this.revealedId) return;

        if (list.clientHeight === 0 || row.offsetParent === null) return;

        this.revealedId = id;
        this.suppressScrollEvent = true;
        row.scrollIntoView({ block: "center", behavior: behavior ?? "smooth" });
        requestAnimationFrame(() => {
            this.suppressScrollEvent = false;
        });
    },
    onScroll() {
        if (this.suppressScrollEvent) return;
        this.following = this.isNearBottom();
    },
    jumpToLatest() {
        this.scrollToEnd("smooth");
        this.following = true;
    },
});
