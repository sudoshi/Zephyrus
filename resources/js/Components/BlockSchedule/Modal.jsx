import React from 'react';
import CommonModal from '@/Components/Common/Modal';

const Modal = ({ open, onClose, title, children }) => {
    return (
        <CommonModal
            open={open}
            onClose={onClose}
            title={title}
            showClose={true}
            maxWidth="md"
        >
            {children}
        </CommonModal>
    );
};

export default Modal;
