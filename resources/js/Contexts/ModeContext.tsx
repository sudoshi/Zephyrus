import React, { createContext, useContext, useState, useEffect } from 'react';
import type { ReactNode } from 'react';

type Mode = 'dev' | 'prod' | string;

interface ModeContextType {
    mode: Mode;
    setMode: React.Dispatch<React.SetStateAction<Mode>>;
}

const ModeContext = createContext<ModeContextType | undefined>(undefined);

interface ModeProviderProps {
    children: ReactNode;
}

export const ModeProvider = ({ children }: ModeProviderProps) => {
    const [mode, setMode] = useState<Mode>(() => {
        // Try to get the mode from sessionStorage, default to 'dev'
        return sessionStorage.getItem('mode') || 'dev';
    });

    useEffect(() => {
        // Persist mode changes to sessionStorage
        sessionStorage.setItem('mode', mode);
    }, [mode]);

    return (
        <ModeContext.Provider value={{ mode, setMode }}>
            {children}
        </ModeContext.Provider>
    );
};

export const useMode = (): ModeContextType => {
    const context = useContext(ModeContext);
    if (!context) {
        throw new Error('useMode must be used within a ModeProvider');
    }
    return context;
};
