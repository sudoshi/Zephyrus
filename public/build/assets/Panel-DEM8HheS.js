import{j as r}from"./app-DNCPaP7U.js";const y=({children:i,title:a,isSubpanel:e=!1,dropLightIntensity:g="medium",className:l="",titleClassName:m="",headerRight:s=null,headerContent:t=null})=>{const n=()=>{if(!e)return"";const d={subtle:{light:"from-white to-gray-50 shadow-sm",dark:"dark:from-gray-800 dark:to-gray-850 dark:shadow-gray-900/10"},medium:{light:"from-white to-gray-100 shadow-md",dark:"dark:from-gray-800 dark:to-gray-850 dark:shadow-gray-900/20"},strong:{light:"from-white to-gray-150 shadow-lg",dark:"dark:from-gray-800 dark:to-gray-750 dark:shadow-gray-900/30"}},o=d[g]||d.medium;return`bg-gradient-to-b ${o.light} ${o.dark} border border-gray-100 dark:border-gray-700`};return r.jsxs("div",{className:`
        bg-white dark:bg-gray-800 rounded-lg 
        ${e?n():"shadow"} 
        p-4 
        ${l}
      `,children:[a&&r.jsxs("div",{className:"flex justify-between items-center mb-4",children:[r.jsx("h2",{className:`text-lg font-semibold dark:text-white ${m}`,children:a}),s&&r.jsx("div",{className:"flex items-center",children:s})]}),t&&r.jsx("div",{className:"mb-4",children:t}),i]})};export{y as P};
