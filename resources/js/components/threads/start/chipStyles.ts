/** The raised chip every composer picker wears: account, model and repository. */
export const CHIP_TRIGGER =
    'inline-flex h-7 max-w-full cursor-pointer items-center gap-1.5 rounded-lg border border-hair bg-panel pl-1 pr-1.5 text-[12.5px] font-medium text-body transition-[border-color,box-shadow,transform] duration-150 ' +
    'hover:border-hair-strong hover:shadow-[0_1px_2px_rgba(0,0,0,0.06)] active:scale-[0.97] disabled:cursor-not-allowed disabled:opacity-60 ' +
    'data-[popup-open]:border-[var(--accent-edge)] data-[popup-open]:shadow-[0_0_0_3px_var(--accent-ring)] motion-reduce:transition-none';

/** A small neutral tag inside a chip, e.g. "Default". */
export const CHIP_TAG = 'shrink-0 rounded-[5px] border border-hair bg-panel-2 px-[5px] py-px text-[10.5px] font-semibold tracking-[0.02em] text-muted';

/** The accent tag, e.g. "Primary". */
export const CHIP_TAG_ACCENT = 'shrink-0 rounded-[5px] border border-[color-mix(in_srgb,var(--accent)_22%,var(--border))] bg-[var(--accent-tint)] px-[5px] py-px text-[10.5px] font-semibold tracking-[0.02em] text-accent-text';
