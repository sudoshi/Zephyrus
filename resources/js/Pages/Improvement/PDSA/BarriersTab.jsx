import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { barriers as mockBarriers } from '@/mock-data/pdsa';
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
import { Plus, X } from 'lucide-react';

const priorityLevels = [
  { value: 'high', label: 'High', color: 'text-red-500' },
  { value: 'medium', label: 'Medium', color: 'text-yellow-500' },
  { value: 'low', label: 'Low', color: 'text-green-500' },
];

const statusOptions = [
  { value: 'identified', label: 'Identified' },
  { value: 'in_progress', label: 'In Progress' },
  { value: 'resolved', label: 'Resolved' },
  { value: 'blocked', label: 'Blocked' },
];

export default function BarriersTab({ cycleId, initialBarriers = mockBarriers }) {
  const [barriers, setBarriers] = useState(initialBarriers);
  const [showAddForm, setShowAddForm] = useState(false);
  const [data, setData] = useState({
    description: '',
    priority: 'medium',
    status: 'identified',
    mitigation: '',
  });
  const [processing, setProcessing] = useState(false);
  const [errors, setErrors] = useState({});

  const handleSubmit = async (e) => {
    e.preventDefault();
    setProcessing(true);

    try {
      await router.post(`/improvement/pdsa/${cycleId}/barriers`, data, {
        onSuccess: () => {
          setData({
            description: '',
            priority: 'medium',
            status: 'identified',
            mitigation: '',
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

  const handleStatusChange = async (barrierId, newStatus) => {
    try {
      await router.post(`/improvement/pdsa/${cycleId}/barriers/${barrierId}/status`, {
        status: newStatus
      });
    } catch (error) {
      console.error('Failed to update status:', error);
    }
  };

  const handleDelete = async (barrierId) => {
    if (confirm('Are you sure you want to remove this barrier?')) {
      try {
        await router.delete(`/improvement/pdsa/${cycleId}/barriers/${barrierId}`);
      } catch (error) {
        console.error('Failed to delete barrier:', error);
      }
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <h2 className="text-lg font-semibold">Implementation Barriers</h2>
        {!showAddForm && (
          <Button
            onClick={() => setShowAddForm(true)}
            className="flex items-center gap-2"
          >
            <Plus className="w-4 h-4" />
            Add Barrier
          </Button>
        )}
      </div>

      {showAddForm && (
        <form onSubmit={handleSubmit} className="bg-gray-50 p-4 rounded-lg border space-y-4">
          <div className="flex justify-between items-center">
            <h3 className="font-medium">Add New Barrier</h3>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={() => setShowAddForm(false)}
            >
              <X className="w-4 h-4" />
            </Button>
          </div>

          <div className="space-y-2">
            <Label htmlFor="description">Description</Label>
            <Textarea
              id="description"
              value={data.description}
              onChange={e => setData({ ...data, description: e.target.value })}
              placeholder="Describe the barrier..."
              rows={2}
            />
            {errors.description && (
              <p className="text-sm text-red-500">{errors.description}</p>
            )}
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="priority">Priority</Label>
              <Select
                value={data.priority}
                onValueChange={value => setData({ ...data, priority: value })}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Select priority" />
                </SelectTrigger>
                <SelectContent>
                  {priorityLevels.map(priority => (
                    <SelectItem 
                      key={priority.value} 
                      value={priority.value}
                      className={priority.color}
                    >
                      {priority.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label htmlFor="status">Status</Label>
              <Select
                value={data.status}
                onValueChange={value => setData({ ...data, status: value })}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Select status" />
                </SelectTrigger>
                <SelectContent>
                  {statusOptions.map(status => (
                    <SelectItem key={status.value} value={status.value}>
                      {status.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="mitigation">Mitigation Plan</Label>
            <Textarea
              id="mitigation"
              value={data.mitigation}
              onChange={e => setData({ ...data, mitigation: e.target.value })}
              placeholder="How will this barrier be addressed?"
              rows={2}
            />
            {errors.mitigation && (
              <p className="text-sm text-red-500">{errors.mitigation}</p>
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
              Add Barrier
            </Button>
          </div>
        </form>
      )}

      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Description</TableHead>
            <TableHead>Priority</TableHead>
            <TableHead>Status</TableHead>
            <TableHead>Mitigation Plan</TableHead>
            <TableHead className="w-[100px]">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {barriers.map((barrier) => (
            <TableRow key={barrier.id}>
              <TableCell>{barrier.description}</TableCell>
              <TableCell>
                <span className={
                  priorityLevels.find(p => p.value === barrier.priority)?.color
                }>
                  {priorityLevels.find(p => p.value === barrier.priority)?.label}
                </span>
              </TableCell>
              <TableCell>
                <Select
                  value={barrier.status}
                  onValueChange={(value) => handleStatusChange(barrier.id, value)}
                >
                  <SelectTrigger className="w-[130px]">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {statusOptions.map(status => (
                      <SelectItem key={status.value} value={status.value}>
                        {status.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </TableCell>
              <TableCell>{barrier.mitigation}</TableCell>
              <TableCell>
                <Button
                  variant="ghost"
                  size="sm"
                  className="text-red-500 hover:text-red-700"
                  onClick={() => handleDelete(barrier.id)}
                >
                  <X className="w-4 h-4" />
                </Button>
              </TableCell>
            </TableRow>
          ))}
          {barriers.length === 0 && (
            <TableRow>
              <TableCell colSpan={5} className="text-center text-gray-500">
                No barriers identified yet
              </TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
    </div>
  );
}
