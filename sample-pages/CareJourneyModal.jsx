import React from 'react';
import Modal from '@/Components/Common/Modal';

export default function CareJourneyModal({ children, onClose, open }) {
  return (
    <Modal
      open={open}
      onClose={onClose}
      maxWidth="5xl"
      showClose={true}
    >
      {children}
    </Modal>
  );
}
