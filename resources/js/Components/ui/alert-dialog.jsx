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
      <div className="relative bg-white dark:bg-gray-800 rounded-lg shadow-lg">
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
    <div className={cn('p-4 bg-gray-50 dark:bg-gray-900 flex justify-end space-x-2 rounded-b-lg', className)}>
      {children}
    </div>
  );
};

const AlertDialogTitle = ({ children, className }) => {
  return (
    <h2 className={cn('text-lg font-semibold text-gray-900 dark:text-gray-100', className)}>
      {children}
    </h2>
  );
};

const AlertDialogDescription = ({ children, className }) => {
  return (
    <p className={cn('text-sm text-gray-600 dark:text-gray-400', className)}>
      {children}
    </p>
  );
};

const AlertDialogAction = ({ children, onClick, className }) => {
  return (
    <button
      onClick={onClick}
      className={cn(
        'px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700',
        'dark:bg-blue-500 dark:hover:bg-blue-600 rounded-md focus:outline-none focus:ring-2',
        'focus:ring-offset-2 focus:ring-blue-500 transition-colors',
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
        'px-4 py-2 text-sm font-medium text-gray-700 bg-white hover:bg-gray-50',
        'dark:text-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 rounded-md',
        'border border-gray-300 dark:border-gray-600 focus:outline-none focus:ring-2',
        'focus:ring-offset-2 focus:ring-blue-500 transition-colors',
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
