import React from 'react';
import { Icon } from '@iconify/react';
import { useMode } from '@/Contexts/ModeContext';

const DataModeToggle = () => {
    const { mode, setMode } = useMode();

    return (
        <div className="flex items-center justify-center mb-4">
            <label className="inline-flex items-center cursor-pointer">
                <input
                    type="checkbox"
                    className="sr-only peer"
                    checked={mode === 'prod'}
                    onChange={() => setMode(mode === 'dev' ? 'prod' : 'dev')}
                />
                <div className="relative w-9 h-5 bg-healthcare-surface dark:bg-healthcare-surface-dark peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-healthcare-info dark:after:bg-healthcare-info-dark after:rounded-full after:h-4 after:w-4 after:transition-all border-healthcare-border dark:border-healthcare-border-dark peer-checked:bg-healthcare-surface dark:peer-checked:bg-healthcare-surface-dark"></div>
                <span className="ms-2 text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {mode === 'dev' ? 'Development' : 'Production'}
                </span>
            </label>
        </div>
    );
};

export default DataModeToggle;
