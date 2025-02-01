import React from 'react';
import { Modal, Button } from '@heroui/react';
import { Icon } from '@iconify/react';
import closeIcon from '@iconify/icons-solar/x-bold';
import {
  ResponsiveContainer,
  LineChart,
  Line,
  XAxis,
  YAxis,
  Tooltip as RechartsTooltip,
  CartesianGrid
} from 'recharts';

const DrillDownModal = ({ isOpen, onClose, metricTitle, chartData }) => {
  return (
    <Modal isOpen={isOpen} onClose={onClose} size="lg">
      <Modal.Header>
        {metricTitle} Details
        <Button variant="ghost" onClick={onClose}>
          <Icon icon={closeIcon} className="w-5 h-5" />
        </Button>
      </Modal.Header>
      <Modal.Body>
        <div className="h-64">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={chartData}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="date" />
              <YAxis />
              <RechartsTooltip />
              <Line type="monotone" dataKey="value" stroke="#4F46E5" />
            </LineChart>
          </ResponsiveContainer>
        </div>
        {/* Additional detailed information can be added here */}
      </Modal.Body>
      <Modal.Footer>
        <Button variant="outline" onClick={onClose}>
          Close
        </Button>
      </Modal.Footer>
    </Modal>
  );
};

export default DrillDownModal;
