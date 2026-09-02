import { Head } from '@inertiajs/react';
import { Badge, StatusPill, Table, Tbody, Td, Th, Thead, Tr } from '@geocodio/console-ui';
import { ExternalLink } from 'lucide-react';
import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import type { ChannelRow } from '@/types/health';
import type { PageProps } from '@/types/shared';

type Props = PageProps<{
    channels: ChannelRow[];
}>;

export default function Index({ channels }: Props) {
    return (
        <>
            <Head title="Channels" />
            <PageHeader crumbs={['Channels']} />

            <div className="min-h-0 flex-1 overflow-auto">
                <Table className="w-full">
                    <Thead>
                        <Tr>
                            <Th>Channel</Th>
                            <Th>Status</Th>
                            <Th>Description</Th>
                            <Th>Docs</Th>
                        </Tr>
                    </Thead>
                    <Tbody>
                        {channels.map((channel) => (
                            <Tr key={channel.slug} data-testid={`channel-row-${channel.slug}`}>
                                <Td>
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium text-body">{channel.name}</span>
                                        {channel.required && <Badge tone="info">Required</Badge>}
                                    </div>
                                </Td>
                                <Td>
                                    <div className="flex flex-col gap-0.5">
                                        <StatusPill tone={channel.status} label={channel.statusLabel} />
                                        {channel.message && <span className="text-[12px] text-muted">{channel.message}</span>}
                                    </div>
                                </Td>
                                <Td className="text-muted">{channel.description}</Td>
                                <Td>
                                    <a
                                        href={channel.docsUrl}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-1 text-accent-text hover:underline"
                                    >
                                        Setup guide
                                        <ExternalLink size={12} />
                                    </a>
                                </Td>
                            </Tr>
                        ))}
                    </Tbody>
                </Table>
            </div>
        </>
    );
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
