import { Button, Dialog, Field, Select, Textarea, TextInput } from '@geocodio/console-ui';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import mcp from '@/routes/settings/mcp';

type Transport = 'http' | 'sse' | 'stdio';

const TRANSPORT_OPTIONS: { value: Transport; label: string }[] = [
    { value: 'http', label: 'HTTP' },
    { value: 'sse', label: 'SSE' },
    { value: 'stdio', label: 'Command' },
];

export function AddMcpServerDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const form = useForm<{ name: string; transport: Transport; target: string; headers: string }>({
        name: '',
        transport: 'http',
        target: '',
        headers: '',
    });

    useEffect(() => {
        if (open) {
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = () => {
        form.post(mcp.store.url(), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    const isCommand = form.data.transport === 'stdio';

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title="Add MCP server"
            description="Added to the shared Claude Code config, so every sandbox sees it on its next run. Remote servers that use OAuth ask for a login afterwards."
            footer={
                <>
                    <Button variant="tertiary" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" pending={form.processing} pendingLabel="Adding…" onClick={submit} data-testid="mcp-add-server-submit">
                        Add server
                    </Button>
                </>
            }
        >
            <div className="flex flex-col gap-4">
                <Field label="Name" description="Letters, digits and dashes. This is the name agents see." error={form.errors.name}>
                    <TextInput
                        autoFocus
                        placeholder="my-server"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        data-testid="mcp-server-name"
                    />
                </Field>

                <Field label="Transport" error={form.errors.transport}>
                    <Select
                        options={TRANSPORT_OPTIONS}
                        value={form.data.transport}
                        onChange={(value) => form.setData('transport', (value ?? 'http') as Transport)}
                        data-testid="mcp-server-transport"
                    />
                </Field>

                <Field
                    label={isCommand ? 'Command' : 'URL'}
                    error={form.errors.target}
                >
                    <TextInput
                        placeholder={isCommand ? 'npx -y my-mcp-server' : 'https://example.com/mcp'}
                        value={form.data.target}
                        onChange={(e) => form.setData('target', e.target.value)}
                        data-testid="mcp-server-target"
                    />
                </Field>

                <Field
                    label="Headers"
                    description="One per line. Leave empty for servers that log in with OAuth. For a command, this becomes environment variables (KEY=value)."
                    error={form.errors.headers}
                >
                    <Textarea
                        rows={3}
                        placeholder={isCommand ? 'API_KEY=...' : 'Authorization: Bearer ...'}
                        value={form.data.headers}
                        onChange={(e) => form.setData('headers', e.target.value)}
                        data-testid="mcp-server-headers"
                    />
                </Field>
            </div>
        </Dialog>
    );
}
