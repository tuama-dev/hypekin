import { usePage } from "@inertiajs/react";
import AuthenticatedLayout from "@/components/Layout/AuthenticatedLayout";

export default function Dashboard() {
    const { auth } = usePage().props;

    return (
        <AuthenticatedLayout>
            <h3>Workspace dashboard</h3>
            {auth.workspace && (
                <p>
                    You are in <strong>{auth.workspace.name}</strong> as a{" "}
                    <em>{auth.workspace.role}</em>.
                </p>
            )}
        </AuthenticatedLayout>
    );
}