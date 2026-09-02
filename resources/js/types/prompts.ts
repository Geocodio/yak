export type PromptListItem = {
    slug: string;
    label: string;
    type: string;
    customized: boolean;
};

export type PromptGroup = {
    group: string;
    items: PromptListItem[];
};

export type PromptDetail = {
    slug: string;
    label: string;
    type: string;
    content: string;
    defaultContent: string;
    customized: boolean;
    variables: string[];
    unusedVariables: string[];
    unknownVariables: string[];
};

export type PromptFixtureOption = {
    index: number;
    label: string;
};

export type PromptPreview = {
    ok: boolean;
    body?: string;
    error?: string;
};

export type PromptVersionRow = {
    id: number;
    number: number;
    createdAgo: string | null;
    current: boolean;
};
