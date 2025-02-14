// Apply dark mode class immediately based on localStorage
const savedTheme = localStorage.getItem('darkMode');
if (savedTheme === 'false') {
    document.documentElement.classList.remove('dark');
} else {
    document.documentElement.classList.add('dark');
}
