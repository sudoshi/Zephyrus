import React, { useEffect, useRef } from 'react';
import { AlertTriangle, X, Check } from 'lucide-react';

const ConfirmationDialog = ({ 
  isOpen, 
  onClose, 
  onConfirm, 
  title, 
  message, 
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  type = 'warning'
}) => {
  const dialogRef = useRef(null);
  const confirmButtonRef = useRef(null);

  useEffect(() => {
    if (isOpen) {
      dialogRef.current?.showModal();
      confirmButtonRef.current?.focus();

      const handleKeyDown = (e) => {
        if (e.key === 'Escape') {
          onClose();
        }
      };

      document.addEventListener('keydown', handleKeyDown);
      return () => document.removeEventListener('keydown', handleKeyDown);
    } else {
      dialogRef.current?.close();
    }
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  const getTypeStyles = () => {
    switch (type) {
      case 'danger':
        return {
          icon: AlertTriangle,
          iconClass: 'text-healthcare-critical',
          buttonClass: 'bg-healthcare-critical hover:bg-healthcare-critical-hover'
        };
      case 'warning':
        return {
          icon: AlertTriangle,
          iconClass: 'text-healthcare-warning',
          buttonClass: 'bg-healthcare-warning hover:bg-healthcare-warning-hover'
        };
      default:
        return {
          icon: Check,
          iconClass: 'text-healthcare-success',
          buttonClass: 'bg-healthcare-success hover:bg-healthcare-success-hover'
        };
    }
  };

  const styles = getTypeStyles();
  const Icon = styles.icon;

  return (
    <dialog
      ref={dialogRef}
      className="bg-transparent p-0"
      onClick={(e) => {
        if (e.target === dialogRef.current) onClose();
      }}
    >
      <div className="w-full max-w-md transform overflow-hidden rounded-2xl bg-white dark:bg-healthcare-background-dark p-6 text-left align-middle shadow-xl transition-all">
        <div className="flex items-start gap-4">
          <div className={`rounded-full p-2 ${type === 'danger' ? 'bg-healthcare-critical/10' : 'bg-healthcare-warning/10'}`}>
            <Icon className={`h-6 w-6 ${styles.iconClass}`} />
          </div>
          <div className="flex-1">
            <h3 className="text-lg font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {title}
            </h3>
            <p className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {message}
            </p>
          </div>
          <button
            onClick={onClose}
            className="rounded-full p-1 hover:bg-healthcare-surface dark:hover:bg-healthcare-surface-dark healthcare-transition"
            aria-label="Close dialog"
          >
            <X className="h-5 w-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
          </button>
        </div>

        <div className="mt-6 flex justify-end gap-3">
          <button
            type="button"
            onClick={onClose}
            className="px-4 py-2 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark bg-healthcare-surface hover:bg-healthcare-surface-hover dark:bg-healthcare-surface-dark dark:hover:bg-healthcare-surface-hover-dark rounded-md healthcare-transition focus:outline-none focus:ring-2 focus:ring-healthcare-primary focus:ring-offset-2 dark:focus:ring-offset-healthcare-background-dark"
          >
            {cancelLabel}
          </button>
          <button
            type="button"
            ref={confirmButtonRef}
            onClick={() => {
              onConfirm();
              onClose();
            }}
            className={`px-4 py-2 text-sm font-medium text-white rounded-md healthcare-transition focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-healthcare-background-dark ${styles.buttonClass}`}
          >
            {confirmLabel}
          </button>
        </div>
      </div>
    </dialog>
  );
};

export default ConfirmationDialog;
