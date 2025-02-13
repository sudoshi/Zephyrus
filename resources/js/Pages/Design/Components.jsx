import React from 'react';
import { Button } from '@/Components/ui/button';

export default function Components() {
  return (
    <div className="p-6 space-y-6">
      <h1 className="text-2xl font-bold">Design Components</h1>
      
      <div className="space-y-4">
        <section>
          <h2 className="text-xl font-semibold mb-4">Buttons</h2>
          <div className="flex space-x-4">
            <Button>Default Button</Button>
            <Button variant="outline">Outline Button</Button>
            <Button variant="ghost">Ghost Button</Button>
          </div>
        </section>
      </div>
    </div>
  );
}
