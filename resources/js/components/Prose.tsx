import { cn } from '@geocodio/console-ui';

export function Prose({ html, className }: { html: string; className?: string }) {
    return <div className={cn('prose prose-sm max-w-none text-body', className)} dangerouslySetInnerHTML={{ __html: html }} />;
}
