import { Head } from '@inertiajs/react';
import { Badge, StatusPill, Table, Tbody, Td, Th, Thead, Tr } from '@geocodio/console-ui';
import { ExternalLink } from 'lucide-react';
import type { ReactNode } from 'react';
import { SettingsLayout } from '@/layouts/SettingsLayout';
import type { ChannelRow } from '@/types/health';
import type { PageProps } from '@/types/shared';

type Props = PageProps<{
    channels: ChannelRow[];
}>;

export default function Index({ channels }: Props) {
    return (
        <>
            <Head title="Channels" />

            <div className="min-h-0 flex-1 overflow-auto">
                <p className="mb-4 max-w-prose text-[13px] leading-relaxed text-muted">
                    Channels are where work reaches Yak and where it reports back. Each one is configured through environment variables; a
                    channel stays inactive until its credentials are set.
                </p>

                <div className="overflow-x-auto rounded-card border border-hair bg-panel shadow-card">
                    <Table className="w-full table-auto">
                        <Thead>
                            <Tr>
                                <Th className="w-[180px] pl-4">Channel</Th>
                                <Th className="w-[220px]">Status</Th>
                                <Th>Description</Th>
                                <Th className="w-[140px] pr-4">Docs</Th>
                            </Tr>
                        </Thead>
                        <Tbody>
                            {channels.map((channel) => (
                                <Tr key={channel.slug} data-testid={`channel-row-${channel.slug}`}>
                                    <Td className="pl-4 align-top">
                                        <div className="flex items-center gap-2 whitespace-nowrap">
                                            <span className="font-medium text-body">{channel.name}</span>
                                            {channel.required && <Badge tone="info">Required</Badge>}
                                        </div>
                                    </Td>
                                    <Td className="align-top">
                                        <div className="flex flex-col items-start gap-1">
                                            <StatusPill tone={channel.status} label={channel.statusLabel} />
                                            {channel.message && <span className="text-[12px] leading-relaxed text-muted">{channel.message}</span>}
                                        </div>
                                    </Td>
                                    <Td className="align-top text-[12.5px] leading-relaxed text-muted">{channel.description}</Td>
                                    <Td className="pr-4 align-top">
                                        <a
                                            href={channel.docsUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex items-center gap-1 whitespace-nowrap text-accent-text hover:underline"
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
            </div>
        </>
    );
}

Index.layout = (page: ReactNode) => <SettingsLayout slug="channels">{page}</SettingsLayout>;
