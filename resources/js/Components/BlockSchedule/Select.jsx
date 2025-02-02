import React, { Fragment } from 'react';
import PropTypes from 'prop-types';
import { Listbox, Transition } from '@headlessui/react';
import { Icon } from '@iconify/react';

const Select = ({ value, onChange, options, className = '' }) => {
    const selectedOption = options.find(option => option.value === value) || options[0];

    return (
        <Listbox value={value} onChange={onChange}>
            <div className="relative mt-1">
                <Listbox.Button className={`relative w-full cursor-default rounded-md border border-gray-300 bg-white py-2 pl-3 pr-10 text-left shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 sm:text-sm ${className}`}>
                    <span className="block truncate">{selectedOption?.label || 'Select an option'}</span>
                    <span className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                        <Icon icon="heroicons:chevron-up-down" className="h-5 w-5 text-gray-400" aria-hidden="true" />
                    </span>
                </Listbox.Button>
                <Transition
                    as={Fragment}
                    leave="transition ease-in duration-100"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <Listbox.Options className="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none sm:text-sm">
                        {options.map((option) => (
                            <Listbox.Option
                                key={option.value}
                                value={option.value}
                                className={({ active }) =>
                                    `relative cursor-default select-none py-2 pl-3 pr-9 ${
                                        active ? 'bg-indigo-600 text-white' : 'text-gray-900'
                                    }`
                                }
                            >
                                {({ active, selected }) => (
                                    <>
                                        <span className={`block truncate ${selected ? 'font-semibold' : 'font-normal'}`}>
                                            {option.label}
                                        </span>
                                        {selected && (
                                            <span className={`absolute inset-y-0 right-0 flex items-center pr-4 ${active ? 'text-white' : 'text-indigo-600'}`}>
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
