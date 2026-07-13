import * as Dialog from '@radix-ui/react-dialog';
import { useMutation } from '@tanstack/react-query';
import axios from 'axios';
import { Plus, X } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import type { PharmacyBarrierReason, PharmacyOldestItem } from '@/features/pharmacy/schemas';

export default function BarrierAnnotationDrawer({ item, reasons, onSaved }: { item: PharmacyOldestItem; reasons: PharmacyBarrierReason[]; onSaved: () => void }) {
  const [open, setOpen] = useState(false);
  const [reasonCode, setReasonCode] = useState(reasons[0]?.reasonCode ?? '');
  const [description, setDescription] = useState('');
  const [owner, setOwner] = useState('Pharmacy operations');
  const mutation = useMutation({
    mutationFn: () => axios.post('/api/pharmacy/barriers', { orderUuid: item.orderUuid, reasonCode, description: description || null, owner: owner || null }),
    onSuccess: () => { setOpen(false); setDescription(''); onSaved(); },
  });

  function submit(event: FormEvent) { event.preventDefault(); if (reasonCode) mutation.mutate(); }

  return <Dialog.Root open={open} onOpenChange={setOpen}>
    <Dialog.Trigger asChild><button type="button" className="inline-flex items-center gap-1 rounded-md border border-healthcare-border px-2 py-1 text-xs font-medium focus:outline-none focus:ring-2 focus:ring-healthcare-info dark:border-healthcare-border-dark"><Plus className="size-3.5" aria-hidden="true" /> Add barrier</button></Dialog.Trigger>
    <Dialog.Portal><Dialog.Overlay className="fixed inset-0 z-40 bg-black/40" /><Dialog.Content className="fixed inset-y-0 right-0 z-50 w-full max-w-md overflow-y-auto border-l border-healthcare-border bg-healthcare-surface p-6 shadow-xl dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="flex items-start justify-between gap-3"><div><Dialog.Title className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Annotate Pharmacy barrier</Dialog.Title><Dialog.Description className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Link a governed reason to {item.label}. The action is audited and never commands a source system.</Dialog.Description></div><Dialog.Close aria-label="Close barrier annotation" className="rounded-md p-1 focus:ring-2 focus:ring-healthcare-info"><X className="size-5" aria-hidden="true" /></Dialog.Close></div>
      <form className="mt-6 space-y-4" onSubmit={submit}>
        <label className="block text-sm font-medium">Reason<select aria-label="Reason" value={reasonCode} onChange={(event) => setReasonCode(event.target.value)} required className="mt-1 block w-full rounded-md border-healthcare-border bg-healthcare-background dark:border-healthcare-border-dark dark:bg-healthcare-background-dark">{reasons.map((reason) => <option key={reason.reasonCode} value={reason.reasonCode}>{reason.label}</option>)}</select></label>
        <label className="block text-sm font-medium">Owner<input value={owner} onChange={(event) => setOwner(event.target.value)} maxLength={100} className="mt-1 block w-full rounded-md border-healthcare-border bg-healthcare-background dark:border-healthcare-border-dark dark:bg-healthcare-background-dark" /></label>
        <label className="block text-sm font-medium">Operational detail<textarea value={description} onChange={(event) => setDescription(event.target.value)} maxLength={500} rows={4} className="mt-1 block w-full rounded-md border-healthcare-border bg-healthcare-background dark:border-healthcare-border-dark dark:bg-healthcare-background-dark" /></label>
        {mutation.isError ? <p role="alert" className="text-sm text-healthcare-critical">Barrier could not be saved. Verify access and try again.</p> : null}
        <button type="submit" disabled={!reasonCode || mutation.isPending} className="rounded-md bg-healthcare-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{mutation.isPending ? 'Saving…' : 'Save barrier'}</button>
      </form>
    </Dialog.Content></Dialog.Portal>
  </Dialog.Root>;
}
