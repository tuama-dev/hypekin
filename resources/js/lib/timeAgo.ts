export function timeAgo(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);

    if (seconds < 60) {
        return 'just now';
    }

    const minutes = Math.round(seconds / 60);
    if (minutes < 60) {
        return `${minutes}m ago`;
    }

    const hours = Math.round(minutes / 60);
    if (hours < 24) {
        return `${hours}h ago`;
    }

    const days = Math.round(hours / 24);
    if (days < 7) {
        return `${days}d ago`;
    }

    return new Date(iso).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
}

export function timeUntil(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);

    if (seconds < 60) {
        return 'in <1m';
    }

    const minutes = Math.round(seconds / 60);
    if (minutes < 60) {
        return `in ${minutes}m`;
    }

    const hours = Math.round(minutes / 60);
    if (hours < 24) {
        return `in ${hours}h`;
    }

    const days = Math.round(hours / 24);

    return `in ${days}d`;
}
