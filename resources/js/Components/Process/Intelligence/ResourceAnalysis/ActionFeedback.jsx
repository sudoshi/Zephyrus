import React, { useEffect } from 'react';
import { CheckCircle, AlertCircle, XCircle, Loader2 } from 'lucide-react';

const ActionFeedback = ({ type, message, onDismiss, position = 'top-right' }) => {
  useEffect(() => {
    // Auto-dismiss loading feedback when component unmounts
    return () => {
      if (type === 'loading') {
        onDismiss?.();
      }
    };
  }, [type, onDismiss]);

  const getTypeStyles = () => {
    switch (type) {
      case 'success':
        return {
          icon: CheckCircle,
          bgColor: 'bg-healthcare-success/10',
          textColor: 'text-healthcare-success',
          borderColor: 'border-healthcare-success/20'
        };
      case 'error':
        return {
          icon: XCircle,
          bgColor: 'bg-healthcare-critical/10',
          textColor: 'text-healthcare-critical',
          borderColor: 'border-healthcare-critical/20'
        };
      case 'loading':
        return {
          icon: Loader2,
          bgColor: 'bg-healthcare-primary/10',
          textColor: 'text-healthcare-primary',
          borderColor: 'border-healthcare-primary/20'
        };
      default:
        return {
          icon: AlertCircle,
          bgColor: 'bg-healthcare-warning/10',
          textColor: 'text-healthcare-warning',
          borderColor: 'border-healthcare-warning/20'
        };
    }
  };

  const getPositionClasses = () => {
    switch (position) {
      case 'top-left':
        return 'top-4 left-4';
      case 'top-center':
        return 'top-4 left-1/2 -translate-x-1/2';
      case 'bottom-left':
        return 'bottom-4 left-4';
      case 'bottom-center':
        return 'bottom-4 left-1/2 -translate-x-1/2';
      case 'bottom-right':
        return 'bottom-4 right-4';
      default: // top-right
        return 'top-4 right-4';
    }
  };

  const styles = getTypeStyles();
  const Icon = styles.icon;
  const positionClasses = getPositionClasses();

  return (
    <div 
      role="status"
      aria-live={type === 'loading' ? 'polite' : 'assertive'}
      className={`fixed ${positionClasses} z-50 pointer-events-auto`}
    >
      <div className={`flex items-center gap-3 px-4 py-3 rounded-lg border shadow-lg ${styles.bgColor} ${styles.borderColor}`}>
        <Icon 
          className={`h-5 w-5 ${styles.textColor} ${type === 'loading' ? 'animate-spin' : ''}`} 
          aria-hidden="true"
        />
        <p className={`text-sm font-medium ${styles.textColor}`}>
          {message}
        </p>
        {type !== 'loading' && (
          <button
            onClick={onDismiss}
            className={`ml-2 p-1 rounded-full hover:${styles.bgColor} healthcare-transition`}
            aria-label="Dismiss notification"
          >
            <XCircle className={`h-4 w-4 ${styles.textColor}`} />
          </button>
        )}
      </div>
    </div>
  );
};

export default ActionFeedback;
