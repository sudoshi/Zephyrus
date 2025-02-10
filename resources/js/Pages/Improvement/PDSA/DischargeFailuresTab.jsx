import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Label } from '@/Components/ui/label';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/Components/ui/table';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/Components/ui/select';
import { Plus, X, Filter } from 'lucide-react';

const failureTypes = [
  { value: 'documentation', label: 'Documentation Issues' },
  { value: 'medication', label: 'Medication Reconciliation' },
  { value: 'transportation', label: 'Transportation Delays' },
  { value: 'placement', label: 'Placement Issues' },
  { value: 'clinical', label: 'Clinical Readiness' },
  { value: 'social', label: 'Social/Family Factors' },
  { value: 'other', label: 'Other' },
];

const impactLevels = [
  { value: 'high', label: 'High Impact', color: 'text-red-500' },
  { value: 'medium', label: 'Medium Impact', color: 'text-yellow-500' },
  { value: 'low', label: 'Low Impact', color: 'text-green-500' },
];

export default function DischargeFailuresTab({ cycleId, initialFailures = [] }) {
  const [failures, setFailures] = useState(initialFailures);
  const [showAddForm, setShowAddForm] = useState(false);
  const [filterType, setFilterType] = useState('all');
  const [data, setData] = useState({
    date: new Date().toISOString().split('T')[0],
    type: '',
    description: '',
    impact: 'medium',
    rootCause: '',
    actionTaken: '',
  });
  const [processing, setProcessing] = useState(false);
  const [errors, setErrors] = useState({});

  const handleSubmit = async (e) => {
    e.preventDefault();
    setProcessing(true);

    try {
      await router.post(`/improvement/pdsa/${cycleId}/discharge-failures`, data, {
        onSuccess: () => {
          setData({
            date: new Date().toISOString().split('T')[0],
            type: '',
            description: '',
            impact: 'medium',
            rootCause: '',
            actionTaken: '',
          });
          setShowAddForm(false);
        },
        onError: (errors) => {
          setErrors(errors);
        },
      });
    } finally {
      setProcessing(false);
    }
  };

  const handleDelete = async (failureId) => {
    if (confirm('Are you sure you want to remove this event?')) {
      try {
        await router.delete(`/improvement/pdsa/${cycleId}/discharge-failures/${failureId}`);
      } catch (error) {
        console.error('Failed to delete failure:', error);
      }
    }
  };

  const filteredFailures = filterType === 'all' 
    ? failures 
    : failures.filter(failure => failure.type === filterType);

  const failuresByType = failures.reduce((acc, failure) => {
    acc[failure.type] = (acc[failure.type] || 0) + 1;
    return acc;
  }, {});

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <h2 className="text-lg font-semibold">Discharge Failures Analysis</h2>
        {!showAddForm && (
          <Button
            onClick={() => setShowAddForm(true)}
            className="flex items-center gap-2"
          >
            <Plus className="w-4 h-4" />
            Add Failure Event
          </Button>
        )}
      </div>

      {/* Summary Statistics */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div className="bg-white p-4 rounded-lg border shadow-sm">
          <h3 className="text-sm font-medium text-gray-500">Total Events</h3>
          <p className="text-2xl font-bold">{failures.length}</p>
        </div>
        <div className="bg-white p-4 rounded-lg border shadow-sm">
          <h3 className="text-sm font-medium text-gray-500">High Impact Events</h3>
          <p className="text-2xl font-bold text-red-500">
            {failures.filter(f => f.impact === 'high').length}
          </p>
        </div>
        <div className="bg-white p-4 rounded-lg border shadow-sm">
          <h3 className="text-sm font-medium text-gray-500">Most Common Type</h3>
          <p className="text-2xl font-bold">
            {Object.entries(failuresByType).sort((a, b) => b[1] - a[1])[0]?.[0] || 'N/A'}
          </p>
        </div>
        <div className="bg-white p-4 rounded-lg border shadow-sm">
          <h3 className="text-sm font-medium text-gray-500">Last 7 Days</h3>
          <p className="text-2xl font-bold">
            {failures.filter(f => {
              const sevenDaysAgo = new Date();
              sevenDaysAgo.setDate(sevenDaysAgo.getDate() - 7);
              return new Date(f.date) >= sevenDaysAgo;
            }).length}
          </p>
        </div>
      </div>

      {showAddForm && (
        <form onSubmit={handleSubmit} className="bg-gray-50 p-4 rounded-lg border space-y-4">
          <div className="flex justify-between items-center">
            <h3 className="font-medium">Add New Failure Event</h3>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={() => setShowAddForm(false)}
            >
              <X className="w-4 h-4" />
            </Button>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="date">Date</Label>
              <Input
                id="date"
                type="date"
                value={data.date}
                onChange={e => setData({ ...data, date: e.target.value })}
              />
              {errors.date && (
                <p className="text-sm text-red-500">{errors.date}</p>
              )}
            </div>

            <div className="space-y-2">
              <Label htmlFor="type">Failure Type</Label>
              <Select
                value={data.type}
                onValueChange={value => setData({ ...data, type: value })}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Select type" />
                </SelectTrigger>
                <SelectContent>
                  {failureTypes.map(type => (
                    <SelectItem key={type.value} value={type.value}>
                      {type.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {errors.type && (
                <p className="text-sm text-red-500">{errors.type}</p>
              )}
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="description">Description</Label>
            <Textarea
              id="description"
              value={data.description}
              onChange={e => setData({ ...data, description: e.target.value })}
              placeholder="Describe what happened..."
              rows={2}
            />
            {errors.description && (
              <p className="text-sm text-red-500">{errors.description}</p>
            )}
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="impact">Impact Level</Label>
              <Select
                value={data.impact}
                onValueChange={value => setData({ ...data, impact: value })}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Select impact" />
                </SelectTrigger>
                <SelectContent>
                  {impactLevels.map(impact => (
                    <SelectItem 
                      key={impact.value} 
                      value={impact.value}
                      className={impact.color}
                    >
                      {impact.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {errors.impact && (
                <p className="text-sm text-red-500">{errors.impact}</p>
              )}
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="rootCause">Root Cause Analysis</Label>
            <Textarea
              id="rootCause"
              value={data.rootCause}
              onChange={e => setData({ ...data, rootCause: e.target.value })}
              placeholder="What were the underlying causes?"
              rows={2}
            />
            {errors.rootCause && (
              <p className="text-sm text-red-500">{errors.rootCause}</p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="actionTaken">Action Taken</Label>
            <Textarea
              id="actionTaken"
              value={data.actionTaken}
              onChange={e => setData({ ...data, actionTaken: e.target.value })}
              placeholder="What actions were taken to address this?"
              rows={2}
            />
            {errors.actionTaken && (
              <p className="text-sm text-red-500">{errors.actionTaken}</p>
            )}
          </div>

          <div className="flex justify-end gap-2">
            <Button
              type="button"
              variant="outline"
              onClick={() => setShowAddForm(false)}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              disabled={processing}
              className="bg-blue-600 text-white hover:bg-blue-700"
            >
              Add Event
            </Button>
          </div>
        </form>
      )}

      {/* Filter Controls */}
      <div className="flex items-center gap-2">
        <Filter className="w-4 h-4 text-gray-500" />
        <Select value={filterType} onValueChange={setFilterType}>
          <SelectTrigger className="w-[200px]">
            <SelectValue placeholder="Filter by type" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All Types</SelectItem>
            {failureTypes.map(type => (
              <SelectItem key={type.value} value={type.value}>
                {type.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Date</TableHead>
            <TableHead>Type</TableHead>
            <TableHead>Description</TableHead>
            <TableHead>Impact</TableHead>
            <TableHead>Root Cause</TableHead>
            <TableHead>Action Taken</TableHead>
            <TableHead className="w-[100px]">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {filteredFailures.map((failure) => (
            <TableRow key={failure.id}>
              <TableCell>{new Date(failure.date).toLocaleDateString()}</TableCell>
              <TableCell>
                {failureTypes.find(t => t.value === failure.type)?.label}
              </TableCell>
              <TableCell>{failure.description}</TableCell>
              <TableCell>
                <span className={
                  impactLevels.find(i => i.value === failure.impact)?.color
                }>
                  {impactLevels.find(i => i.value === failure.impact)?.label}
                </span>
              </TableCell>
              <TableCell>{failure.rootCause}</TableCell>
              <TableCell>{failure.actionTaken}</TableCell>
              <TableCell>
                <Button
                  variant="ghost"
                  size="sm"
                  className="text-red-500 hover:text-red-700"
                  onClick={() => handleDelete(failure.id)}
                >
                  <X className="w-4 h-4" />
                </Button>
              </TableCell>
            </TableRow>
          ))}
          {filteredFailures.length === 0 && (
            <TableRow>
              <TableCell colSpan={7} className="text-center text-gray-500">
                No discharge failures recorded
              </TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
    </div>
  );
}
