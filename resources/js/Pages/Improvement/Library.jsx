import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Plus, FileText, FolderOpen, Download } from 'lucide-react';

const Library = ({ resources = [] }) => {
  const categories = [
    { name: 'Templates', icon: FileText },
    { name: 'Guidelines', icon: FolderOpen },
    { name: 'Best Practices', icon: FileText },
  ];

  return (
    <DashboardLayout>
      <Head title="Improvement Library - ZephyrusOR" />
      <PageContentLayout
        title="Improvement Library"
        subtitle="Access improvement resources and templates"
      >
        {/* Categories */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
          {categories.map((category, index) => {
            const Icon = category.icon;
            return (
              <div
                key={index}
                className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg p-6 shadow-sm transition-colors duration-300"
              >
                <div className="flex items-center gap-3 mb-4">
                  <div className="p-2 bg-healthcare-primary/10 dark:bg-healthcare-primary-dark/10 rounded-lg">
                    <Icon className="h-6 w-6 text-healthcare-primary dark:text-healthcare-primary-dark" />
                  </div>
                  <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {category.name}
                  </h3>
                </div>
                <Button variant="outline" className="w-full">
                  View All
                </Button>
              </div>
            );
          })}
        </div>

        {/* Resources List */}
        <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow-sm transition-colors duration-300">
          <div className="p-6 border-b border-healthcare-border dark:border-healthcare-border-dark">
            <div className="flex justify-between items-center">
              <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Recent Resources
              </h3>
              <Button className="flex items-center gap-2 bg-healthcare-primary hover:bg-healthcare-primary/90 text-white dark:bg-healthcare-primary-dark dark:hover:bg-healthcare-primary-dark/90">
                <Plus className="h-4 w-4" />
                Add Resource
              </Button>
            </div>
          </div>
          <div className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
            {resources.map((resource, index) => (
              <div key={index} className="p-6 flex items-center justify-between">
                <div>
                  <h4 className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark font-medium mb-1">
                    {resource.title}
                  </h4>
                  <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {resource.description}
                  </p>
                  <div className="flex gap-4 mt-2 text-sm">
                    <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {resource.category}
                    </span>
                    <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {resource.type}
                    </span>
                    <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      Added {resource.dateAdded}
                    </span>
                  </div>
                </div>
                <Button variant="outline" size="sm" className="flex items-center gap-2">
                  <Download className="h-4 w-4" />
                  Download
                </Button>
              </div>
            ))}
          </div>
        </div>

        {/* Empty State */}
        {resources.length === 0 && (
          <div className="text-center py-12">
            <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">
              No Resources Yet
            </h3>
            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-6">
              Add your first resource to the library
            </p>
            <Button className="flex items-center gap-2 bg-healthcare-primary hover:bg-healthcare-primary/90 text-white dark:bg-healthcare-primary-dark dark:hover:bg-healthcare-primary-dark/90">
              <Plus className="h-4 w-4" />
              Add First Resource
            </Button>
          </div>
        )}
      </PageContentLayout>
    </DashboardLayout>
  );
};

export default Library;
