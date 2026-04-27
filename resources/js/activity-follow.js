// Auto-scroll log lists when new entries arrive, but yield to the user
// when they scroll up to read history. Resumes following once the user
// scrolls back to the bottom.
//
// Usage:
//   <div x-data="activityFollow()">
//     <div x-ref="logList" @scroll.passive="onScroll()">…log entries…</div>
//   </div>
export const activityFollow = () => ({
    following: true,
    observer: null,
    suppressScrollEvent: false,
    init() {
        this.$nextTick(() => this.scrollToEnd("auto"));

        this.observer = new MutationObserver(() => {
            if (this.following) {
                this.scrollToEnd("smooth");
            }
        });

        this.observer.observe(this.$refs.logList, {
            childList: true,
            subtree: true,
        });
    },
    destroy() {
        this.observer?.disconnect();
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
    onScroll() {
        if (this.suppressScrollEvent) return;
        this.following = this.isNearBottom();
    },
    jumpToLatest() {
        this.scrollToEnd("smooth");
        this.following = true;
    },
});
