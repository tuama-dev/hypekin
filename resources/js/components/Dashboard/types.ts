export type TrendMetric = 'reach' | 'impressions' | 'engagements';

export interface TrendPoint {
    date: string;
    reach: number;
    impressions: number;
    engagements: number;
}

export interface PlatformShare {
    platform: string;
    label: string;
    reach: number;
}

export interface PipelineStatus {
    status: string;
    label: string;
    count: number;
}

export interface AttentionItem {
    post_id: string;
    title: string | null;
    caption: string;
    platform: { value: string; label: string };
    display_name: string;
    error_message: string | null;
}

export interface UpcomingPost {
    id: string;
    scheduled_at: string | null;
    title: string | null;
    caption: string;
    platforms: string[];
}

export interface BestPost {
    post_id: string;
    title: string | null;
    caption: string;
    platform: string;
    reach: number;
}

export interface DashboardAnalytics {
    kpis: {
        reach: number;
        impressions: number;
        engagements: number;
        posts_published_30d: number;
        accounts_connected: number;
        unread_notifications: number;
    };
    trend: TrendPoint[];
    platform_mix: PlatformShare[];
    pipeline: PipelineStatus[];
    needs_attention: { count: number; items: AttentionItem[] };
    upcoming: UpcomingPost[];
    best_post: BestPost | null;
    onboarding: { has_accounts: boolean; has_media: boolean; has_posts: boolean };
}