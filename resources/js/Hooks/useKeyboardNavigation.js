import { useEffect, useRef } from 'react';

const useKeyboardNavigation = (containerRef, itemSelector, onSelect) => {
  const currentFocusIndex = useRef(-1);

  useEffect(() => {
    const container = containerRef.current;
    if (!container) return;

    const handleKeyDown = (e) => {
      const items = Array.from(container.querySelectorAll(itemSelector));
      if (!items.length) return;

      switch (e.key) {
        case 'ArrowRight':
        case 'ArrowDown':
          e.preventDefault();
          currentFocusIndex.current = (currentFocusIndex.current + 1) % items.length;
          items[currentFocusIndex.current].focus();
          break;

        case 'ArrowLeft':
        case 'ArrowUp':
          e.preventDefault();
          currentFocusIndex.current = currentFocusIndex.current - 1 < 0 
            ? items.length - 1 
            : currentFocusIndex.current - 1;
          items[currentFocusIndex.current].focus();
          break;

        case 'Home':
          e.preventDefault();
          currentFocusIndex.current = 0;
          items[0].focus();
          break;

        case 'End':
          e.preventDefault();
          currentFocusIndex.current = items.length - 1;
          items[items.length - 1].focus();
          break;

        case 'Enter':
        case ' ':
          e.preventDefault();
          if (currentFocusIndex.current >= 0) {
            onSelect?.(items[currentFocusIndex.current]);
          }
          break;
      }
    };

    const handleFocus = (e) => {
      const items = Array.from(container.querySelectorAll(itemSelector));
      currentFocusIndex.current = items.indexOf(e.target);
    };

    container.addEventListener('keydown', handleKeyDown);
    container.addEventListener('focusin', handleFocus);

    return () => {
      container.removeEventListener('keydown', handleKeyDown);
      container.removeEventListener('focusin', handleFocus);
    };
  }, [containerRef, itemSelector, onSelect]);

  return {
    resetFocus: () => {
      currentFocusIndex.current = -1;
    }
  };
};

export default useKeyboardNavigation;
