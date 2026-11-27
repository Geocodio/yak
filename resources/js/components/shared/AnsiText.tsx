import { cn } from '@geocodio/console-ui';
import { Fragment } from 'react';
import { parseAnsi } from '@/lib/ansi';

/**
 * Terminal output with its colors shown through theme classes. A line can be
 * given an extra class, which tints the line without hiding its own colors.
 */
export function AnsiText({ text, lineClassName }: { text: string; lineClassName?: (plainLine: string) => string | false | null | undefined }) {
    const lines = parseAnsi(text);

    return lines.map((segments, index) => (
        <Fragment key={index}>
            <span className={cn(lineClassName?.(segments.map((segment) => segment.text).join('')))}>
                {segments.map((segment, segmentIndex) => (segment.className === null ? segment.text : <span key={segmentIndex} className={segment.className}>{segment.text}</span>))}
            </span>
            {index < lines.length - 1 ? '\n' : ''}
        </Fragment>
    ));
}
