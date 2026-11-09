import type { ClassValue } from 'clsx';
import { cn } from '@/lib/utils';

export type AppButtonVariant = 'primary' | 'secondary' | 'quiet' | 'danger';

export type AppButtonSize = 'md' | 'sm' | 'xs';

/**
 * Small, bordered buttons with a subtle fill, after Supabase's dashboard.
 * Every variant carries a 1px border (transparent when it shouldn't show)
 * so sizes line up whichever variant sits next to which.
 */
const base =
    'inline-flex shrink-0 cursor-pointer items-center justify-center gap-1.5 rounded-[6px] border font-medium whitespace-nowrap transition-[background-color,border-color,color,scale] duration-150 select-none active:scale-[0.97] motion-reduce:active:scale-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring) disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 motion-reduce:transition-none [&_svg]:shrink-0';

const sizes: Record<AppButtonSize, string> = {
    md: 'h-8 px-3 text-[13px]/4',
    sm: 'h-7 px-2.5 text-xs/4',
    xs: 'h-[26px] px-2.5 text-xs/4',
};

const variants: Record<AppButtonVariant, string> = {
    primary:
        'border-[#3B7BFF]/60 bg-[#1C4BB0] text-white hover:border-[#3B7BFF] hover:bg-[#2257C7]',
    secondary:
        'border-(--ds-border-strong) bg-(--ds-field) text-(--ds-text) hover:border-(--ds-border-hover) hover:bg-(--ds-hover)',
    quiet: 'border-transparent text-(--ds-text-secondary) hover:bg-(--ds-hover) hover:text-(--ds-text)',
    danger: 'border-transparent text-(--ds-text-secondary) hover:border-(--ds-red)/30 hover:bg-(--ds-red)/10 hover:text-(--ds-red)',
};

/**
 * Classes for the dashboard's buttons, for elements that can't be AppButton.
 * Extra classes are merged last, so they can override the size or layout.
 */
export function appButtonClasses(
    variant: AppButtonVariant = 'secondary',
    size: AppButtonSize = 'md',
    ...extra: ClassValue[]
): string {
    return cn(base, sizes[size], variants[variant], ...extra);
}
