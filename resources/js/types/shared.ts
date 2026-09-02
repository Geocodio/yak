export type SharedProps = {
    auth: { user: { id: number; name: string; email: string; initials: string } | null };
    flash: { success?: string | null; error?: string | null; id?: string | null };
    nav: { activeTaskCount: number };
    docs: { baseUrl: string };
};

export type PageProps<T = Record<string, unknown>> = SharedProps & T;
