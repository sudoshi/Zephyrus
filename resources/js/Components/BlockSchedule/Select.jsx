import React, { Fragment } from 'react';
import PropTypes from 'prop-types';
import { Listbox, Transition } from '@headlessui/react';
import { Icon } from '@iconify/react';

const Select = ({ value, onChange, options, className = '' }) => {
    const selectedOption = options.find(option => option.value === value) || options[0];

    return (
        <Listbox value={value} onChange={onChange}>
            <div className="relative mt-1">
                <Listbox.Button className={`relative w-full cursor-default rounded-md border border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark py-2 pl-3 pr-10 text-left shadow-sm focus:border-healthcare-primary focus:outline-none focus:ring-1 focus:ring-healthcare-primary sm:text-sm ${className}`}>
                    <span className="block truncate">{selectedOption?.label || 'Select an option'}</span>
                    <span className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                        <Icon icon="heroicons:chevron-up-down" className="h-5 w-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" aria-hidden="true" />
                    </span>
                </Listbox.Button>
                <Transition
                    as={Fragment}
                    leave="transition ease-in duration-100"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <Listbox.Options className="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-md bg-healthcare-surface dark:bg-healthcare-surface-dark py-1 text-base shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none sm:text-sm">
                        {options.map((option) => (
                            <Listbox.Option
                                key={option.value}
                                value={option.value}
                                className={({ active }) =>
                                    `relative cursor-default select-none py-2 pl-3 pr-9 ${
                                        active ? 'bg-healthcare-primary dark:bg-healthcare-primary-dark text-white' : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
                                    }`
                                }
                            >
                                {({ active, selected }) => (
                                    <>
                                        <span className={`block truncate ${selected ? 'font-semibold' : 'font-normal'}`}>
                                            {option.label}
                                        </span>
                                        {selected && (
                                            <span className={`absolute inset-y-0 right-0 flex items-center pr-4 ${active ? 'text-white' : 'text-healthcare-primary dark:text-healthcare-primary-dark'}`}>
                                                <Icon icon="heroicons:check" className="h-5 w-5" aria-hidden="true" />
                                            </span>
                                        )}
                                    </>
                                )}
                            </Listbox.Option>
                        ))}
                    </Listbox.Options>
                </Transition>
            </div>
        </Listbox>
    );
};

Select.propTypes = {
  value: PropTypes.oneOfType([PropTypes.string, PropTypes.number]).isRequired,
  onChange: PropTypes.func.isRequired,
  options: PropTypes.arrayOf(PropTypes.shape({
    value: PropTypes.oneOfType([PropTypes.string, PropTypes.number]).isRequired,
    label: PropTypes.string.isRequired
  })).isRequired,
  className: PropTypes.string
};

Select.defaultProps = {
  className: ''
};

export default Select;
