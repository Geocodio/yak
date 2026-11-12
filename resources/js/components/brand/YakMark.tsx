import type { SVGProps } from 'react';
import { MARK_FACE_MARKUP, MARK_FACE_VIEW_BOX, MARK_MARKUP, MARK_VIEW_BOX } from './markArtwork';

type MarkProps = Omit<SVGProps<SVGSVGElement>, 'viewBox' | 'children' | 'dangerouslySetInnerHTML'> & {
    /** Accessible name. Without it the mark is decorative and hidden from assistive tech. */
    title?: string;
};

/** The full walker. */
export function YakMark({ title, ...props }: MarkProps) {
    return <svg xmlns="http://www.w3.org/2000/svg" viewBox={MARK_VIEW_BOX} role={title ? 'img' : undefined} aria-label={title} aria-hidden={title ? undefined : true} {...props} dangerouslySetInnerHTML={{ __html: MARK_MARKUP }} />;
}

/** The head only, transparent, for small leading avatars. */
export function YakMarkFace({ title, ...props }: MarkProps) {
    return <svg xmlns="http://www.w3.org/2000/svg" viewBox={MARK_FACE_VIEW_BOX} role={title ? 'img' : undefined} aria-label={title} aria-hidden={title ? undefined : true} {...props} dangerouslySetInnerHTML={{ __html: MARK_FACE_MARKUP }} />;
}
