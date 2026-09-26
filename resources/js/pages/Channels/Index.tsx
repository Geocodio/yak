import { Head } from '@inertiajs/react';
import { Badge, StackedTable, StackedTbody, StackedTd, StackedThead, StackedTr, StatusPill, Th, Tr } from '@geocodio/console-ui';
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

                <div className="md:overflow-x-auto rounded-card border border-hair bg-panel shadow-card">
                    <StackedTable className="w-full table-auto">
                        <StackedThead>
                            <Tr>
                                <Th className="md:w-[180px] pl-4">Channel</Th>
                                <Th className="md:w-[220px]">Status</Th>
                                <Th>Description</Th>
                                <Th className="md:w-[140px] pr-4">Docs</Th>
                            </Tr>
                        </StackedThead>
                        <StackedTbody>
                            {channels.map((channel) => (
                                <StackedTr key={channel.slug} data-testid={`channel-row-${channel.slug}`}>
                                    <StackedTd label="Channel" className="pl-4 align-top">
                                        <div className="flex items-center gap-2 md:whitespace-nowrap">
                                            <span className="font-medium text-body">{channel.name}</span>
                                            {channel.required && <Badge tone="info">Required</Badge>}
                                        </div>
                                    </StackedTd>
                                    <StackedTd label="Status" className="align-top">
                                        <div className="flex flex-col items-start gap-1">
                                            <StatusPill tone={channel.status} label={channel.statusLabel} />
                                            {channel.message && <span className="text-[12px] leading-relaxed text-muted">{channel.message}</span>}
                                        </div>
                                    </StackedTd>
                                    <StackedTd label="Description" className="align-top text-[12.5px] leading-relaxed text-muted">
                                        {channel.description}
                                    </StackedTd>
                                    <StackedTd label="Docs" className="pr-4 align-top">
                                        <a
                                            href={channel.docsUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex items-center gap-1 md:whitespace-nowrap text-accent-text hover:underline"
                                        >
                                            Setup guide
                                            <ExternalLink size={12} />
                                        </a>
                                    </StackedTd>
                                </StackedTr>
                            ))}
                        </StackedTbody>
                    </StackedTable>
                </div>
            </div>
        </>
    );
}

Index.layout = (page: ReactNode) => <SettingsLayout slug="channels">{page}</SettingsLayout>;
