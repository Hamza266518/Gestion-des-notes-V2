import { createContext, useContext, useState, useCallback, useMemo } from 'react';

const AccountModalContext = createContext(null);

// "Mon compte" used to be its own route, so opening it replaced the current
// page and left the modal sitting on an empty background. Keeping the open
// flag here lets the single <MonCompte /> overlay render on top of whatever
// page the user is on, like every other modal in the app.
export const AccountModalProvider = ({ children }) => {
  const [open, setOpen] = useState(false);

  const openAccount = useCallback(() => setOpen(true), []);
  const closeAccount = useCallback(() => setOpen(false), []);

  const value = useMemo(
    () => ({ open, openAccount, closeAccount }),
    [open, openAccount, closeAccount]
  );

  return (
    <AccountModalContext.Provider value={value}>
      {children}
    </AccountModalContext.Provider>
  );
};

export const useAccountModal = () => {
  const context = useContext(AccountModalContext);
  if (!context) throw new Error('useAccountModal must be used within AccountModalProvider');
  return context;
};
