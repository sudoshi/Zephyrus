import React, { useEffect, useRef, useCallback } from 'react';
import { X } from 'lucide-react';
import ActionDetails from './ActionDetails';
import useClickOutside from '@/Hooks/useClickOutside';
import useModalAnimation from '@/Hooks/useModalAnimation';
import './ActionModal.css';

const ActionModal = ({ isOpen, onClose, action }) => {
  const modalRef = useRef(null);
  const closeButtonRef = useRef(null);
  const { isAnimating, shouldRender, handleClose } = useModalAnimation(isOpen, onClose);

  useClickOutside(modalRef, handleClose);

  const handleInitialFocus = useCallback(() => {
    if (closeButtonRef.current) {
      closeButtonRef.current.focus();
    }
  }, []);

  useEffect(() => {
    let originalFocus = document.activeElement;

    if (isOpen) {
      handleInitialFocus();
      // Focus trap
      const handleKeyDown = (e) => {
        if (e.key === 'Escape') {
          onClose();
        }

        if (e.key === 'Tab') {
          const focusableElements = modalRef.current.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
          );
          const firstElement = focusableElements[0];
          const lastElement = focusableElements[focusableElements.length - 1];

          if (e.shiftKey && document.activeElement === firstElement) {
            e.preventDefault();
            lastElement.focus();
          } else if (!e.shiftKey && document.activeElement === lastElement) {
            e.preventDefault();
            firstElement.focus();
          }
        }
      };

      document.addEventListener('keydown', handleKeyDown);
      
      return () => {
        document.removeEventListener('keydown', handleKeyDown);
        if (originalFocus) {
          originalFocus.focus();
        }
      };
    }
  }, [isOpen, onClose]);

  if (!shouldRender) return null;

  return (
    <div className={`fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 modal-overlay ${isAnimating ? 'enter' : ''}`}>
      <div 
        ref={modalRef}
        className={`bg-white dark:bg-healthcare-background-dark rounded-lg shadow-xl w-full max-w-5xl mx-4 max-h-[90vh] overflow-hidden flex flex-col modal-content ${isAnimating ? 'enter' : ''}`}
        role="dialog"
        aria-modal="true"
        aria-labelledby="modal-title"
      >
        {/* Header */}
        <div className="flex justify-between items-center px-6 py-4 border-b border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark">
          <h2 id="modal-title" className="text-xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Action Details
          </h2>
          <button
            ref={closeButtonRef}
            onClick={handleClose}
            className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark hover:text-healthcare-primary dark:hover:text-healthcare-primary-dark p-2 rounded-full hover:bg-healthcare-surface-hover dark:hover:bg-healthcare-surface-hover-dark healthcare-transition"
            aria-label="Close modal"
          >
            <X className="h-6 w-6" />
          </button>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto p-6 bg-healthcare-surface dark:bg-healthcare-surface-dark">
          <ActionDetails action={action} />
        </div>
      </div>
    </div>
  );
};

export default ActionModal;
