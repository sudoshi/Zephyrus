import React from 'react';
import { useMode } from '@/Contexts/ModeContext';

const ModeToggle = () => {
    const { mode, setMode } = useMode();

    return (
        <div className="flex items-center justify-center space-x-4 p-4">
            <div className="flex items-center space-x-2">
                <input
                    type="radio"
                    id="dev-mode"
                    name="mode"
                    value="dev"
                    checked={mode === 'dev'}
                    onChange={(e) => setMode(e.target.value)}
                    className="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300"
                />
                <label htmlFor="dev-mode" className="text-sm font-medium text-gray-700">
                    Dev Mode
                </label>
            </div>
            <div className="flex items-center space-x-2">
                <input
                    type="radio"
                    id="prod-mode"
                    name="mode"
                    value="prod"
                    checked={mode === 'prod'}
                    onChange={(e) => setMode(e.target.value)}
                    className="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300"
                />
                <label htmlFor="prod-mode" className="text-sm font-medium text-gray-700">
                    Prod Mode
                </label>
            </div>
            <div className="text-xs text-gray-500">
                {mode === 'dev' ? '(Using Mock Data)' : '(Using Real Data)'}
            </div>
        </div>
    );
};

export default ModeToggle;
