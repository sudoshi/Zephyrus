// Type surface for the untyped dialog.jsx Radix wrappers so TS consumers
// (cockpit/DrillModal) get real prop checking. Keep in sync with dialog.jsx.
import * as React from 'react';
import * as DialogPrimitive from '@radix-ui/react-dialog';

export declare const Dialog: typeof DialogPrimitive.Root;
export declare const DialogTrigger: typeof DialogPrimitive.Trigger;
export declare const DialogPortal: typeof DialogPrimitive.Portal;
export declare const DialogClose: typeof DialogPrimitive.Close;

export declare const DialogOverlay: React.ForwardRefExoticComponent<
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Overlay> &
    React.RefAttributes<React.ComponentRef<typeof DialogPrimitive.Overlay>>
>;

export declare const DialogContent: React.ForwardRefExoticComponent<
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Content> &
    React.RefAttributes<React.ComponentRef<typeof DialogPrimitive.Content>>
>;

export declare const DialogHeader: React.FC<React.HTMLAttributes<HTMLDivElement>>;
export declare const DialogFooter: React.FC<React.HTMLAttributes<HTMLDivElement>>;

export declare const DialogTitle: React.ForwardRefExoticComponent<
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Title> &
    React.RefAttributes<React.ComponentRef<typeof DialogPrimitive.Title>>
>;

export declare const DialogDescription: React.ForwardRefExoticComponent<
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Description> &
    React.RefAttributes<React.ComponentRef<typeof DialogPrimitive.Description>>
>;
