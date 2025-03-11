import { useState, useEffect } from 'react';

const useModalAnimationImpl = (isOpen, onClose) => {
  const [isAnimating, setIsAnimating] = useState(false);
  const [shouldRender, setShouldRender] = useState(false);

  useEffect(() => {
    if (isOpen) {
      setShouldRender(true);
      // Start enter animation after a frame to ensure DOM is ready
      requestAnimationFrame(() => {
        setIsAnimating(true);
      });
    } else {
      setIsAnimating(false);
      // Wait for exit animation to complete before unmounting
      const timeout = setTimeout(() => {
        setShouldRender(false);
      }, 200); // Match transition duration
      return () => clearTimeout(timeout);
    }
  }, [isOpen]);

  const handleClose = () => {
    setIsAnimating(false);
    // Wait for exit animation to complete before calling onClose
    setTimeout(onClose, 200);
  };

  return {
    isAnimating,
    shouldRender,
    handleClose
  };
};

export const useModalAnimation = useModalAnimationImpl;
