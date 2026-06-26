import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArrowLeft, ClipboardList } from 'lucide-react';

/**
 * Improvement › PDSA › Create
 *
 * Detailed stub for the "New PDSA Cycle" flow (route `improvement.pdsa.create`
 * → `/improvement/pdsa/create`). Three "New PDSA Cycle" buttons across the
 * Improvement surface link here, so the route must exist and be on-brand. The
 * form captures the Plan phase; Do / Study / Act are populated as the cycle
 * progresses. Submission is not yet wired to the backend — it returns to the
 * PDSA index for now.
 */
const Create = ({ auth }) => {
  const [form, setForm] = useState({
    title: '',
    objective: '',
    rationale: '',
    prediction: '',
    owner: '',
    dueDate: '',
  });

  const set = (field) => (e) => setForm((f) => ({ ...f, [field]: e.target.value }));

  const handleSubmit = (e) => {
    e.preventDefault();
    // Backend create endpoint not yet implemented — return to the index.
    router.visit('/improvement/pdsa');
  };

  return (
    <AuthenticatedLayout user={auth?.user}>
      <Head title="New PDSA Cycle" />

      <div className="p-4">
        <Link
          href="/improvement/pdsa"
          className="inline-flex items-center gap-1 text-sm text-healthcare-text-secondary hover:text-healthcare-text-primary dark:text-healthcare-text-secondary-dark dark:hover:text-healthcare-text-primary-dark mb-4"
        >
          <ArrowLeft className="h-4 w-4" />
          Back to PDSA Cycles
        </Link>

        <form onSubmit={handleSubmit} className="healthcare-card max-w-3xl">
          <div className="border-b border-healthcare-border dark:border-healthcare-border-dark p-6">
            <div className="flex items-center gap-2">
              <ClipboardList className="h-5 w-5 text-healthcare-primary dark:text-healthcare-primary-dark" />
              <h2 className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                New PDSA Cycle
              </h2>
            </div>
            <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Define the <span className="font-medium">Plan</span> — what you will test and what you
              predict will happen. Do, Study and Act are recorded as the cycle runs.
            </p>
          </div>

          <div className="p-6 space-y-5">
            <Field label="Cycle title" htmlFor="title">
              <input id="title" type="text" value={form.title} onChange={set('title')}
                placeholder="e.g. Reduce AM discharge order delays on 5-West"
                className="healthcare-input w-full" />
            </Field>

            <Field label="Objective" htmlFor="objective" hint="The specific, measurable aim of this cycle.">
              <textarea id="objective" rows={2} value={form.objective} onChange={set('objective')}
                placeholder="What are we trying to accomplish?"
                className="healthcare-input w-full" />
            </Field>

            <Field label="Rationale" htmlFor="rationale" hint="Why this change, and why now.">
              <textarea id="rationale" rows={2} value={form.rationale} onChange={set('rationale')}
                placeholder="The problem and the change idea being tested."
                className="healthcare-input w-full" />
            </Field>

            <Field label="Prediction" htmlFor="prediction" hint="What you expect to observe.">
              <textarea id="prediction" rows={2} value={form.prediction} onChange={set('prediction')}
                placeholder="We predict that…"
                className="healthcare-input w-full" />
            </Field>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
              <Field label="Owner" htmlFor="owner">
                <input id="owner" type="text" value={form.owner} onChange={set('owner')}
                  placeholder="Accountable lead" className="healthcare-input w-full" />
              </Field>
              <Field label="Target completion" htmlFor="dueDate">
                <input id="dueDate" type="date" value={form.dueDate} onChange={set('dueDate')}
                  className="healthcare-input w-full" />
              </Field>
            </div>
          </div>

          <div className="flex items-center justify-end gap-3 border-t border-healthcare-border dark:border-healthcare-border-dark p-6">
            <Link href="/improvement/pdsa" className="healthcare-button-secondary">Cancel</Link>
            <button type="submit" className="healthcare-button-primary">Create cycle</button>
          </div>
        </form>
      </div>
    </AuthenticatedLayout>
  );
};

function Field({ label, htmlFor, hint, children }) {
  return (
    <div>
      <label htmlFor={htmlFor} className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-1">
        {label}
      </label>
      {children}
      {hint && <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{hint}</p>}
    </div>
  );
}

export default Create;
