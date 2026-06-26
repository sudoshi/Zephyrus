import React from 'react';
import { cn } from '@/lib/utils';

const AlertDialog = ({ open, onOpenChange, children, className }) => {
  return (
    <div className={cn(
      'fixed inset-0 z-50 flex items-center justify-center',
      !open && 'hidden',
      className
    )}>
      <div className="fixed inset-0 bg-black/50" onClick={() => onOpenChange(false)} />
      <div className="relative bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow-lg">
        {children}
      </div>
    </div>
  );
};

const AlertDialogContent = ({ children, className }) => {
  return (
    <div className={cn('relative', className)}>
      {children}
    </div>
  );
};

const AlertDialogHeader = ({ children, className }) => {
  return (
    <div className={cn('p-4 space-y-2', className)}>
      {children}
    </div>
  );
};

const AlertDialogFooter = ({ children, className }) => {
  return (
    <div className={cn('p-4 bg-healthcare-background dark:bg-healthcare-background-dark flex justify-end space-x-2 rounded-b-lg', className)}>
      {children}
    </div>
  );
};

const AlertDialogTitle = ({ children, className }) => {
  return (
    <h2 className={cn('text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark', className)}>
      {children}
    </h2>
  );
};

const AlertDialogDescription = ({ children, className }) => {
  return (
    <p className={cn('text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark', className)}>
      {children}
    </p>
  );
};

const AlertDialogAction = ({ children, onClick, className }) => {
  return (
    <button
      onClick={onClick}
      className={cn(
        'px-4 py-2 text-sm font-medium text-white bg-healthcare-primary hover:bg-healthcare-primary-hover',
        'dark:bg-healthcare-primary-dark dark:hover:bg-healthcare-primary-hover-dark rounded-md focus:outline-none focus:ring-2',
        'focus:ring-offset-2 focus:ring-healthcare-primary transition-colors',
        className
      )}
    >
      {children}
    </button>
  );
};

const AlertDialogCancel = ({ children, onClick, className }) => {
  return (
    <button
      onClick={onClick}
      className={cn(
        'px-4 py-2 text-sm font-medium text-healthcare-text-secondary bg-healthcare-surface hover:bg-healthcare-hover',
        'dark:text-healthcare-text-secondary-dark dark:bg-healthcare-surface-dark dark:hover:bg-healthcare-hover-dark rounded-md',
        'border border-healthcare-border dark:border-healthcare-border-dark focus:outline-none focus:ring-2',
        'focus:ring-offset-2 focus:ring-healthcare-primary transition-colors',
        className
      )}
    >
      {children}
    </button>
  );
};

export {
  AlertDialog,
  AlertDialogContent,
  AlertDialogHeader,
  AlertDialogFooter,
  AlertDialogTitle,
  AlertDialogDescription,
  AlertDialogAction,
  AlertDialogCancel
};
