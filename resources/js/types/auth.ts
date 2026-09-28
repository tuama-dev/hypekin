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

export type Notification = {
    id: string;
    read_at: string | null;
    created_at: string | null;
    data: {
        title: string;
        message: string;
        platform: string;
        account_display_name: string;
        error: string | null;
        post_id: string;
        workspace_slug: string;
    };
};

export type Auth = {
    user: User;
    workspace: Workspace | null;
    workspaces: Workspace[] | null;
    notifications: Notification[] | null;
    unread_count: number;
};
