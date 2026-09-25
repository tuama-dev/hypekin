export type User = {
    id: string;
    name: string;
    fullname: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
};

export type Workspace = {
    id: string;
    name: string;
    slug: string;
    role: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    workspace: Workspace | null;
    workspaces: Workspace[] | null;
};
