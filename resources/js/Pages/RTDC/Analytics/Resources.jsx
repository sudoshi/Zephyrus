import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function Resources() {
    return (
        <AuthenticatedLayout
            header={<h2 className="font-semibold text-xl text-healthcare-text-primary dark:text-healthcare-text-primary-dark leading-tight">Resource Analytics</h2>}
        >
            <div className="p-4">
                <div>
                    <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            <h1>Resource Analytics</h1>
                            <p>This page displays resource allocation and utilization metrics.</p>
                            {/* Add your components and content here */}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
