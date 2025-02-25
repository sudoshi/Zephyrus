import React, { useContext } from 'react';
import { Flowbite } from 'flowbite-react';
import { useDarkMode } from '@/Layouts/AuthenticatedLayout';

// Create a light theme configuration
const lightTheme = {
  card: {
    root: {
      base: "bg-healthcare-surface border border-healthcare-border rounded-lg shadow-sm",
      children: "p-6 flex flex-col space-y-4"
    }
  },
  button: {
    color: {
      primary: "bg-healthcare-primary hover:bg-healthcare-primary-hover text-white"
    }
  },
  modal: {
    root: {
      base: "fixed top-0 right-0 left-0 z-50 h-modal h-screen overflow-y-auto overflow-x-hidden md:inset-0 md:h-full",
      show: {
        on: "flex bg-gray-900 bg-opacity-50",
        off: "hidden"
      }
    },
    content: {
      base: "relative h-full w-full p-4 md:h-auto",
      inner: "relative rounded-lg bg-healthcare-surface shadow"
    }
  },
  navbar: {
    root: {
      base: "bg-healthcare-surface px-2 py-2.5 sm:px-4 rounded-lg border border-healthcare-border"
    }
  },
  sidebar: {
    root: {
      base: "h-full bg-healthcare-surface border-r border-healthcare-border"
    },
    item: {
      base: "flex items-center justify-center rounded-lg p-2 text-healthcare-text-primary hover:bg-healthcare-hover",
      active: "bg-healthcare-primary text-white"
    }
  },
  table: {
    root: {
      base: "w-full text-left text-sm text-healthcare-text-primary"
    },
    body: {
      base: "divide-y divide-healthcare-border bg-healthcare-surface"
    },
    head: {
      base: "bg-healthcare-surface text-xs uppercase text-healthcare-text-primary"
    },
    row: {
      base: "bg-healthcare-surface border-healthcare-border hover:bg-healthcare-hover"
    },
    cell: {
      base: "px-6 py-4"
    }
  },
  tabs: {
    base: "flex flex-col gap-2",
    tablist: {
      base: "flex text-center",
      styles: {
        default: "flex-wrap border-b border-healthcare-border",
        underline: "flex-wrap -mb-px border-b border-healthcare-border",
        pills: "flex-wrap font-medium text-sm text-healthcare-text-primary space-x-2",
        fullWidth: "w-full text-sm font-medium divide-x divide-healthcare-border rounded-lg border border-healthcare-border"
      },
      tabitem: {
        base: "flex items-center justify-center p-4 rounded-t-lg text-sm font-medium first:ml-0 disabled:cursor-not-allowed disabled:text-healthcare-text-secondary focus:outline-none",
        styles: {
          default: {
            base: "rounded-t-lg",
            active: {
              on: "bg-healthcare-surface text-healthcare-primary",
              off: "text-healthcare-text-secondary hover:bg-healthcare-hover hover:text-healthcare-text-primary"
            }
          },
          underline: {
            base: "rounded-t-lg",
            active: {
              on: "text-healthcare-primary rounded-t-lg border-b-2 border-healthcare-primary active",
              off: "border-b-2 border-transparent text-healthcare-text-secondary hover:border-healthcare-border hover:text-healthcare-text-primary"
            }
          },
          pills: {
            base: "",
            active: {
              on: "rounded-lg bg-healthcare-primary text-white",
              off: "rounded-lg hover:bg-healthcare-hover hover:text-healthcare-text-primary"
            }
          },
          fullWidth: {
            base: "ml-0 first:rounded-l-lg last:rounded-r-lg",
            active: {
              on: "bg-healthcare-surface text-healthcare-primary",
              off: "bg-healthcare-surface text-healthcare-text-secondary hover:bg-healthcare-hover hover:text-healthcare-text-primary"
            }
          }
        }
      }
    },
    tabpanel: "py-3"
  }
};

// Create a dark theme configuration
const darkTheme = {
  card: {
    root: {
      base: "bg-healthcare-surface-dark border border-healthcare-border-dark rounded-lg shadow-sm",
      children: "p-6 flex flex-col space-y-4"
    }
  },
  button: {
    color: {
      primary: "bg-healthcare-primary-dark hover:bg-healthcare-primary-hover-dark text-white"
    }
  },
  modal: {
    root: {
      base: "fixed top-0 right-0 left-0 z-50 h-modal h-screen overflow-y-auto overflow-x-hidden md:inset-0 md:h-full",
      show: {
        on: "flex bg-gray-900 bg-opacity-50 dark:bg-opacity-80",
        off: "hidden"
      }
    },
    content: {
      base: "relative h-full w-full p-4 md:h-auto",
      inner: "relative rounded-lg bg-healthcare-surface-dark shadow dark:bg-healthcare-surface-dark"
    }
  },
  navbar: {
    root: {
      base: "bg-healthcare-surface-dark px-2 py-2.5 dark:border-healthcare-border-dark sm:px-4 rounded-lg border border-healthcare-border-dark"
    }
  },
  sidebar: {
    root: {
      base: "h-full bg-healthcare-surface-dark border-r border-healthcare-border-dark"
    },
    item: {
      base: "flex items-center justify-center rounded-lg p-2 text-healthcare-text-primary-dark hover:bg-healthcare-hover-dark",
      active: "bg-healthcare-primary-dark text-white"
    }
  },
  table: {
    root: {
      base: "w-full text-left text-sm text-healthcare-text-primary-dark dark:text-healthcare-text-primary-dark"
    },
    body: {
      base: "divide-y divide-healthcare-border-dark bg-healthcare-surface-dark dark:divide-healthcare-border-dark dark:bg-healthcare-surface-dark"
    },
    head: {
      base: "bg-healthcare-surface-dark text-xs uppercase text-healthcare-text-primary-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark"
    },
    row: {
      base: "bg-healthcare-surface-dark dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark hover:bg-healthcare-hover-dark"
    },
    cell: {
      base: "px-6 py-4"
    }
  },
  tabs: {
    base: "flex flex-col gap-2",
    tablist: {
      base: "flex text-center",
      styles: {
        default: "flex-wrap border-b border-healthcare-border-dark",
        underline: "flex-wrap -mb-px border-b border-healthcare-border-dark",
        pills: "flex-wrap font-medium text-sm text-healthcare-text-primary-dark space-x-2",
        fullWidth: "w-full text-sm font-medium divide-x divide-healthcare-border-dark rounded-lg border border-healthcare-border-dark"
      },
      tabitem: {
        base: "flex items-center justify-center p-4 rounded-t-lg text-sm font-medium first:ml-0 disabled:cursor-not-allowed disabled:text-healthcare-text-secondary-dark focus:outline-none",
        styles: {
          default: {
            base: "rounded-t-lg",
            active: {
              on: "bg-healthcare-surface-dark text-healthcare-primary-dark",
              off: "text-healthcare-text-secondary-dark hover:bg-healthcare-hover-dark hover:text-healthcare-text-primary-dark"
            }
          },
          underline: {
            base: "rounded-t-lg",
            active: {
              on: "text-healthcare-primary-dark rounded-t-lg border-b-2 border-healthcare-primary-dark active",
              off: "border-b-2 border-transparent text-healthcare-text-secondary-dark hover:border-healthcare-border-dark hover:text-healthcare-text-primary-dark"
            }
          },
          pills: {
            base: "",
            active: {
              on: "rounded-lg bg-healthcare-primary-dark text-white",
              off: "rounded-lg hover:bg-healthcare-hover-dark hover:text-healthcare-text-primary-dark"
            }
          },
          fullWidth: {
            base: "ml-0 first:rounded-l-lg last:rounded-r-lg",
            active: {
              on: "bg-healthcare-surface-dark text-healthcare-primary-dark",
              off: "bg-healthcare-surface-dark text-healthcare-text-secondary-dark hover:bg-healthcare-hover-dark hover:text-healthcare-text-primary-dark"
            }
          }
        }
      }
    },
    tabpanel: "py-3"
  }
};

export function FlowbiteThemeProvider({ children, isDarkMode: propIsDarkMode }) {
  // Try to get dark mode state from context, fallback to prop
  const darkModeContext = useDarkMode();
  const isDarkMode = propIsDarkMode !== undefined ? propIsDarkMode : darkModeContext.isDarkMode;
  
  // Select the appropriate theme based on dark mode state
  const theme = isDarkMode ? darkTheme : lightTheme;
  
  return (
    <Flowbite theme={{ theme: { dark: isDarkMode, theme } }}>
      {children}
    </Flowbite>
  );
}
