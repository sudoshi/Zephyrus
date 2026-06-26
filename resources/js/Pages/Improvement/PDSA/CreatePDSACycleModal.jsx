import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import Input from '@/Components/ui/input';
import Textarea from '@/Components/ui/textarea';
import Label from '@/Components/ui/label';

export default function CreatePDSACycleModal({ isOpen, onClose }) {
  const [data, setData] = React.useState({
    title: '',
    objective: '',
    dueDate: '',
    metrics: '',
    expectedOutcome: ''
  });
  const [processing, setProcessing] = React.useState(false);
  const [errors, setErrors] = React.useState({});

  const handleSubmit = (e) => {
    e.preventDefault();
    setProcessing(true);

    router.post('/improvement/pdsa', data, {
      onSuccess: () => {
        setData({
          title: '',
          objective: '',
          dueDate: '',
          metrics: '',
          expectedOutcome: ''
        });
        setProcessing(false);
        onClose();
      },
      onError: (errors) => {
        setErrors(errors);
        setProcessing(false);
      }
    });
  };

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="sm:max-w-[600px]">
        <DialogHeader>
          <DialogTitle>Create New PDSA Cycle</DialogTitle>
          <DialogDescription>
            Start a new Plan-Do-Study-Act improvement cycle. Fill in the initial details below.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="title">Title</Label>
            <Input
              id="title"
              value={data.title}
              onChange={e => setData(prev => ({ ...prev, title: e.target.value }))}
              placeholder="Enter a descriptive title"
              error={errors.title}
            />
            {errors.title && (
              <p className="text-sm text-healthcare-critical dark:text-healthcare-critical-dark">{errors.title}</p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="objective">Objective</Label>
            <Textarea
              id="objective"
              value={data.objective}
              onChange={e => setData(prev => ({ ...prev, objective: e.target.value }))}
              placeholder="What do you want to accomplish?"
              rows={3}
              error={errors.objective}
            />
            {errors.objective && (
              <p className="text-sm text-healthcare-critical dark:text-healthcare-critical-dark">{errors.objective}</p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="dueDate">Target Completion Date</Label>
            <Input
              id="dueDate"
              type="date"
              value={data.dueDate}
              onChange={e => setData(prev => ({ ...prev, dueDate: e.target.value }))}
              error={errors.dueDate}
            />
            {errors.dueDate && (
              <p className="text-sm text-healthcare-critical dark:text-healthcare-critical-dark">{errors.dueDate}</p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="metrics">Key Metrics</Label>
            <Textarea
              id="metrics"
              value={data.metrics}
              onChange={e => setData(prev => ({ ...prev, metrics: e.target.value }))}
              placeholder="List the metrics you will track (one per line)"
              rows={3}
              error={errors.metrics}
            />
            {errors.metrics && (
              <p className="text-sm text-healthcare-critical dark:text-healthcare-critical-dark">{errors.metrics}</p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="expectedOutcome">Expected Outcome</Label>
            <Textarea
              id="expectedOutcome"
              value={data.expectedOutcome}
              onChange={e => setData(prev => ({ ...prev, expectedOutcome: e.target.value }))}
              placeholder="What results do you expect to achieve?"
              rows={3}
              error={errors.expectedOutcome}
            />
            {errors.expectedOutcome && (
              <p className="text-sm text-healthcare-critical dark:text-healthcare-critical-dark">{errors.expectedOutcome}</p>
            )}
          </div>

          <DialogFooter className="mt-6">
            <Button
              type="button"
              variant="outline"
              onClick={onClose}
              disabled={processing}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              disabled={processing}
              className="bg-healthcare-primary dark:bg-healthcare-primary-dark text-white hover:bg-healthcare-primary-dark dark:hover:bg-healthcare-primary"
            >
              Create PDSA Cycle
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
