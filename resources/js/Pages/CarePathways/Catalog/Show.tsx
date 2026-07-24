import { Head } from "@inertiajs/react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";

// Placeholder shell — replaced by the full per-pathway detail view in the
// catalog-explorer plan Phase 4.
export default function Show() {
    return (
        <AuthenticatedLayout>
            <Head title="Care Pathway Detail" />
            <div className="p-4" />
        </AuthenticatedLayout>
    );
}
