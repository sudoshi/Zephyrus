import React, { createContext, useContext, useState, useEffect } from 'react';

const ModeContext = createContext();

export const ModeProvider = ({ children }) => {
    const [mode, setMode] = useState(() => {
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

export const useMode = () => {
    const context = useContext(ModeContext);
    if (!context) {
        throw new Error('useMode must be used within a ModeProvider');
    }
    return context;
};
