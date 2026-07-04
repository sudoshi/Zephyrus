// resources/js/Components/Common/StudyLink.tsx
//
// P5: the workspace → Study affordance. Trend/deep-dive pages live only under
// the Study altitude in the nav; each workspace primary offers this quiet link
// into its retrospective set ("now" = workspace, "over time" = Study).
import React from 'react';
import { Link } from '@inertiajs/react';
import { TrendingUp } from 'lucide-react';

export function StudyLink({ href, label }: { href: string; label: string }) {
  return (
    <Link
      href={href}
      className="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-sm text-healthcare-primary transition-colors duration-200 hover:bg-healthcare-hover dark:text-healthcare-primary-dark dark:hover:bg-healthcare-hover-dark"
    >
      <TrendingUp className="h-4 w-4" />
      {label}
    </Link>
  );
}
