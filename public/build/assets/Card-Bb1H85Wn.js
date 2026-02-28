import{j as a}from"./app-DNCPaP7U.js";import{u as s}from"./useDarkMode-Cjnn_MHf.js";const t=({children:e,className:r=""})=>{const[o]=s();return a.jsx("div",{className:`
                rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark
                border border-healthcare-border dark:border-healthcare-border-dark
                shadow-sm hover:shadow-md dark:shadow-none
                dark:hover:shadow-[0_4px_12px_rgba(0,0,0,0.25)]
                transition-all duration-300
                overflow-hidden
                ${r}
            `,style:{backgroundImage:o?"linear-gradient(to bottom, rgba(255, 255, 255, 0.1), transparent)":"linear-gradient(to bottom, rgba(255, 255, 255, 0.5), rgba(255, 255, 255, 0))"},children:e})};t.Header=({children:e,className:r=""})=>a.jsx("div",{className:`
                border-b border-healthcare-border dark:border-healthcare-border-dark
                p-6 transition-colors duration-300
                ${r}
            `,children:e});t.Title=({children:e,className:r=""})=>a.jsx("h3",{className:`
                text-lg font-medium leading-6
                text-healthcare-text-primary dark:text-healthcare-text-primary-dark
                transition-colors duration-300
                ${r}
            `,children:e});t.Description=({children:e,className:r=""})=>a.jsx("p",{className:`
                mt-1 text-sm
                text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark
                transition-colors duration-300
                ${r}
            `,children:e});t.Content=({children:e,className:r=""})=>a.jsx("div",{className:`p-6 overflow-x-auto ${r}`,children:e});t.Item=({title:e,subtitle:r,meta:o,className:d=""})=>a.jsxs("div",{className:`
                flex items-center justify-between rounded-md p-4
                hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark
                transition-colors duration-300
                ${d}
            `,children:[a.jsxs("div",{children:[a.jsx("h4",{className:`
                        font-medium
                        text-healthcare-text-primary dark:text-healthcare-text-primary-dark
                        transition-colors duration-300
                    `,children:e}),r&&a.jsx("p",{className:`
                            text-sm
                            text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark
                            transition-colors duration-300
                        `,children:r})]}),o&&a.jsx("div",{children:o})]});export{t as C};
