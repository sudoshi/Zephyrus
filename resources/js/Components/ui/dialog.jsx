import React from 'react';

export const Dialog = ({ children, ...props }) => (
  <div {...props}>
    {children}
  </div>
);

export const DialogTrigger = ({ children, ...props }) => (
  <button {...props}>
    {children}
  </button>
);

export const DialogContent = ({ children, ...props }) => (
  <div {...props}>
    {children}
  </div>
);

export const DialogHeader = ({ children, ...props }) => (
  <div {...props}>
    {children}
  </div>
);

export const DialogTitle = ({ children, ...props }) => (
  <h2 {...props}>
    {children}
  </h2>
);

export const DialogDescription = ({ children, ...props }) => (
  <p {...props}>
    {children}
  </p>
);

export const DialogFooter = ({ children, ...props }) => (
  <div {...props}>
    {children}
  </div>
);

export const DialogClose = ({ children, ...props }) => (
  <button {...props}>
    {children}
  </button>
);
