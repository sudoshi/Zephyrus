import React from 'react';
import { Bar } from 'react-chartjs-2';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    BarElement,
    Title,
    Tooltip,
    Legend
} from 'chart.js';

ChartJS.register(
    CategoryScale,
    LinearScale,
    BarElement,
    Title,
    Tooltip,
    Legend
);

const defaultOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: false
        },
        tooltip: {
            mode: 'index',
            intersect: false,
            backgroundColor: 'rgb(var(--color-healthcare-background))',
            titleColor: 'rgb(var(--color-healthcare-text-primary))',
            bodyColor: 'rgb(var(--color-healthcare-text-secondary))',
            borderColor: 'rgb(var(--color-healthcare-border))',
            borderWidth: 1,
            padding: 12,
            bodySpacing: 8,
            titleSpacing: 8,
            cornerRadius: 8,
            displayColors: true,
            boxWidth: 8,
            boxHeight: 8,
            boxPadding: 4,
            usePointStyle: true,
            callbacks: {
                label: function(context) {
                    const value = context.parsed.x || context.parsed.y;
                    return `${context.dataset.label || ''}: ${value}`;
                }
            }
        }
    },
    scales: {
        x: {
            grid: {
                display: false,
                drawBorder: false
            },
            ticks: {
                color: 'rgb(var(--color-healthcare-text-secondary))',
                font: {
                    size: 12
                }
            }
        },
        y: {
            grid: {
                color: 'rgba(var(--color-healthcare-border), 0.1)',
                drawBorder: false
            },
            ticks: {
                color: 'rgb(var(--color-healthcare-text-secondary))',
                font: {
                    size: 12
                },
                padding: 8
            },
            beginAtZero: true
        }
    }
};

export const BarChart = ({ data, options = {} }) => {
    const chartOptions = {
        ...defaultOptions,
        ...options,
        plugins: {
            ...defaultOptions.plugins,
            ...options.plugins
        },
        scales: {
            ...defaultOptions.scales,
            ...options.scales
        }
    };

    return <Bar data={data} options={chartOptions} />;
};

export default BarChart;
