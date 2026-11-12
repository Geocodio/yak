import { Button } from '@geocodio/console-ui';
import { ShieldAlert } from 'lucide-react';
import { STAND_DOWN_HELP } from './levels';

export function StandDown({ isAlreadyDown, isPending, onStandDown }: { isAlreadyDown: boolean; isPending: boolean; onStandDown: () => void }) {
    return (
        <div className="flex flex-col gap-2 border-t border-hair pt-4">
            <h3 className="font-mono text-[12px] tracking-[0.16em]">ABORT</h3>
            <Button data-testid="approvals-stand-down" variant="destructive" icon={<ShieldAlert size={16} />} disabled={isAlreadyDown} pending={isPending} onClick={onStandDown}>
                Stand down to DEFCON 1
            </Button>
            <p className="text-[13px] leading-snug text-muted">{isAlreadyDown ? STAND_DOWN_HELP.already : STAND_DOWN_HELP.ready}</p>
        </div>
    );
}
