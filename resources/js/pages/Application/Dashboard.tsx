import AuthenticatedLayout from '@/components/Layout/AuthenticatedLayout';

export default function Dashboard() {
    return (
        <AuthenticatedLayout>
            <h3>Workspace dashboard</h3>
        </AuthenticatedLayout>
    );
}
