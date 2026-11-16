import { TriangleAlert } from 'lucide-react';

export function StartErrorBanner({ message }: { message: string }) {
    return (
        <div className="flex items-start gap-2 border-t border-hair bg-fail-soft px-[18px] py-2.5 text-[12.5px] text-fail" role="alert" data-testid="start-thread-error">
            <TriangleAlert size={14} className="mt-0.5 shrink-0" aria-hidden="true" />
            <p>
                <strong className="font-semibold">Yak could not start the task.</strong> {message}
            </p>
        </div>
    );
}
