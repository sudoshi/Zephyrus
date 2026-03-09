import React from 'react';
import { Toaster } from 'react-hot-toast';

export function ToastProvider() {
  return (
    <Toaster
      position="top-right"
      toastOptions={{
        style: {
          background: '#1C1C20',
          color: '#F0EDE8',
          border: '1px solid #2A2A30',
        },
        duration: 4000,
      }}
    />
  );
}
