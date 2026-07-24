import { Head } from "@inertiajs/react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";

// Placeholder shell — replaced by the full Catalog Explorer (overview +
// 250-pathway index) in the catalog-explorer plan Phase 3.
export default function Index() {
    return (
        <AuthenticatedLayout>
            <Head title="Care Pathways Catalog" />
            <div className="p-4" />
        </AuthenticatedLayout>
    );
}
