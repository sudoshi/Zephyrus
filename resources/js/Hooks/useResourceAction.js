import { useState, useCallback } from 'react';

const useResourceActionImpl = () => {
  const [pendingAction, setPendingAction] = useState(null);
  const [actionFeedback, setActionFeedback] = useState(null);

  const confirmAction = useCallback((action) => {
    setPendingAction(action);
  }, []);

  const executeAction = useCallback(async () => {
    if (!pendingAction) return;

    try {
      setActionFeedback({
        type: 'loading',
        message: `Processing ${pendingAction.label.toLowerCase()}...`
      });

      // Simulate API call
      await new Promise(resolve => setTimeout(resolve, 1000));

      // Success feedback
      setActionFeedback({
        type: 'success',
        message: `Successfully ${pendingAction.label.toLowerCase()}`,
        autoHide: true
      });

      // Call the actual action handler
      await pendingAction.handler();
    } catch (error) {
      setActionFeedback({
        type: 'error',
        message: `Failed to ${pendingAction.label.toLowerCase()}: ${error.message}`,
        autoHide: true
      });
    } finally {
      setPendingAction(null);
      
      // Auto-hide success/error feedback after 3 seconds
      if (actionFeedback?.autoHide) {
        setTimeout(() => setActionFeedback(null), 3000);
      }
    }
  }, [pendingAction]);

  const cancelAction = useCallback(() => {
    setPendingAction(null);
  }, []);

  const clearFeedback = useCallback(() => {
    setActionFeedback(null);
  }, []);

  const getConfirmationProps = useCallback(() => {
    if (!pendingAction) return null;

    return {
      isOpen: true,
      onClose: cancelAction,
      onConfirm: executeAction,
      title: pendingAction.confirmTitle || `Confirm ${pendingAction.label}`,
      message: pendingAction.confirmMessage || `Are you sure you want to ${pendingAction.label.toLowerCase()}?`,
      confirmLabel: pendingAction.confirmLabel || 'Confirm',
      cancelLabel: pendingAction.cancelLabel || 'Cancel',
      type: pendingAction.confirmType || 'warning'
    };
  }, [pendingAction, cancelAction, executeAction]);

  const getFeedbackProps = useCallback(() => {
    if (!actionFeedback) return null;

    return {
      type: actionFeedback.type,
      message: actionFeedback.message,
      onDismiss: clearFeedback
    };
  }, [actionFeedback, clearFeedback]);

  return {
    confirmAction,
    executeAction,
    cancelAction,
    clearFeedback,
    getConfirmationProps,
    getFeedbackProps,
    isActionPending: !!pendingAction,
    hasFeedback: !!actionFeedback
  };
};

export const useResourceAction = useResourceActionImpl;
