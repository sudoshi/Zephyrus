import axios from 'axios';
import { mockPerformanceMetrics } from '@/mock-data/analytics';
import { mockBlockSchedule } from '@/mock-data/block-schedule';
import { mockCases } from '@/mock-data/cases';
import { mockRoomStatus } from '@/mock-data/room-status';
import { mockProviderAnalytics } from '@/mock-data/provider-analytics';
import { mockBlockTemplates, mockBlockUtilization, mockServices } from '@/mock-data/block-templates';
import { useMode } from '@/Contexts/ModeContext';

class DataService {
    constructor(mode) {
        this.mode = mode;
    }

    async getPerformanceMetrics() {
        if (this.mode === 'dev') {
            return Promise.resolve(mockPerformanceMetrics);
        }
        const response = await axios.get('/api/performance-metrics');
        return response.data;
    }

    async getBlockSchedule() {
        if (this.mode === 'dev') {
            return Promise.resolve(mockBlockSchedule);
        }
        const response = await axios.get('/api/block-schedule');
        return response.data;
    }

    async getCases() {
        if (this.mode === 'dev') {
            return Promise.resolve(mockCases);
        }
        const response = await axios.get('/api/cases');
        return response.data;
    }

    async getRoomStatus() {
        if (this.mode === 'dev') {
            return Promise.resolve(mockRoomStatus);
        }
        const response = await axios.get('/api/room-status');
        return response.data;
    }

    async getProviderPerformance(startDate, endDate) {
        if (this.mode === 'dev') {
            return Promise.resolve(mockProviderAnalytics);
        }
        const response = await axios.get('/api/provider-performance', {
            params: { startDate, endDate }
        });
        return response.data;
    }

    async getCapacityAnalysis() {
        if (this.mode === 'dev') {
            return Promise.resolve(mockPerformanceMetrics.capacity_analysis);
        }
        const response = await axios.get('/api/capacity-analysis');
        return response.data;
    }

    async getBlockTemplates() {
        if (this.mode === 'dev') {
            return Promise.resolve(mockBlockTemplates);
        }
        const response = await axios.get('/api/block-templates');
        return response.data;
    }

    async getBlockUtilization(date) {
        if (this.mode === 'dev') {
            return Promise.resolve(mockBlockUtilization);
        }
        const response = await axios.get('/api/block-utilization', {
            params: { date }
        });
        return response.data;
    }

    async getServices() {
        if (this.mode === 'dev') {
            return Promise.resolve(mockServices);
        }
        const response = await axios.get('/api/services');
        return response.data;
    }

    // Hook for React components to use the data service
    static useDataService() {
        const { mode } = useMode();
        const dataService = new DataService(mode);

        return {
            getPerformanceMetrics: () => dataService.getPerformanceMetrics(),
            getBlockSchedule: () => dataService.getBlockSchedule(),
            getCases: () => dataService.getCases(),
            getRoomStatus: () => dataService.getRoomStatus(),
            getProviderPerformance: (startDate, endDate) =>
                dataService.getProviderPerformance(startDate, endDate),
            getCapacityAnalysis: () => dataService.getCapacityAnalysis(),
            getBlockTemplates: () => dataService.getBlockTemplates(),
            getBlockUtilization: (date) => dataService.getBlockUtilization(date),
            getServices: () => dataService.getServices(),
        };
    }
}

export default DataService;
