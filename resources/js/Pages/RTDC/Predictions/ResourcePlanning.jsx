import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function ResourcePlanning() {
    return (
        <AuthenticatedLayout
            header={<h2 className="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Resource Planning</h2>}
        >
            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900 dark:text-gray-100">
                            <h1>Resource Planning</h1>
                            <p>This page displays resource planning and allocation tools.</p>
                            {/* Add your components and content here */}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
