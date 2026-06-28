import React, { Fragment } from 'react';
import { Dialog, Transition } from '@headlessui/react';
import { Icon } from '@iconify/react';

const MAX_WIDTHS = {
    sm: 'max-w-sm', md: 'max-w-md', lg: 'max-w-lg', xl: 'max-w-xl',
    '2xl': 'max-w-2xl', '3xl': 'max-w-3xl', '4xl': 'max-w-4xl',
    '5xl': 'max-w-5xl', '6xl': 'max-w-6xl', '7xl': 'max-w-7xl',
};

const Modal = ({ open, onClose, title, children, maxWidth = '5xl', showClose = true }) => {
    const maxWidthClass = MAX_WIDTHS[maxWidth] ?? MAX_WIDTHS['5xl'];
    return (
        <Transition appear show={open} as={Fragment}>
            <Dialog as="div" className="relative z-50" onClose={onClose}>
                <Transition.Child
                    as={Fragment}
                    enter="ease-out duration-300"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-200"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="modal-backdrop" aria-hidden="true" />
                </Transition.Child>

                <div className="fixed inset-0 overflow-y-auto">
                    <div className="flex min-h-full items-center justify-center p-4 text-center">
                        <Transition.Child
                            as={Fragment}
                            enter="ease-out duration-300"
                            enterFrom="opacity-0 scale-95"
                            enterTo="opacity-100 scale-100"
                            leave="ease-in duration-200"
                            leaveFrom="opacity-100 scale-100"
                            leaveTo="opacity-0 scale-95"
                        >
                            <Dialog.Panel className={`modal-surface relative w-full ${maxWidthClass} max-h-[85vh] overflow-y-auto p-6 text-left`}>
                                {showClose && (
                                    <button
                                        onClick={onClose}
                                        className="absolute right-4 top-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark transition-colors duration-300"
                                        aria-label="Close modal"
                                    >
                                        <Icon icon="heroicons:x-mark" className="w-6 h-6" />
                                    </button>
                                )}

                                {title && (
                                    <Dialog.Title
                                        as="h3"
                                        className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4"
                                    >
                                        {title}
                                    </Dialog.Title>
                                )}

                                <div className={!title ? 'mt-0' : 'mt-4'}>
                                    {children}
                                </div>
                            </Dialog.Panel>
                        </Transition.Child>
                    </div>
                </div>
            </Dialog>
        </Transition>
    );
};

export default Modal;
